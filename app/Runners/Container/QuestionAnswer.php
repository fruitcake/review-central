<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Models\ReviewQuestion;
use App\Services\ReviewCredentials;
use App\Services\ReviewQuestions;
use App\Services\ReviewSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Has the reviewer answer one question about a pull request, and posts the answer
 * under the comment that asked; one asked on a review's page is answered there
 * alone, and nothing is posted.
 *
 * The branch is cloned and the reviewer runs once in the sandbox a review runs in,
 * with the AI provider's key and nothing else. Its home is a copy of the session
 * the last review of the branch ended in: the answer is no part of the review, and
 * a review may be using the home it was copied from.
 */
class QuestionAnswer
{
    /** The longest answer that is posted; the rest is cut with a note. */
    protected const LONGEST = 6000;

    public function __construct(
        protected Checkout $checkout,
        protected Sandbox $sandbox,
        protected ReviewSessions $sessions,
        protected ReviewCredentials $credentials,
        protected ReviewQuestions $questions,
        protected Sandboxes $sandboxes,
    ) {}

    public function give(ReviewQuestion $question): void
    {
        $repository = $question->repository;
        $directory = rtrim((string) config('review.container.work_directory'), '/')."/{$repository->getKey()}-question-{$question->getKey()}";
        $home = "{$directory}-home";

        try {
            File::ensureDirectoryExists(dirname($directory), 0700);
            File::deleteDirectory($directory);
            File::deleteDirectory($home);

            $resumed = $this->sessions->copy($repository, $question->branch, $home) !== null;
            File::ensureDirectoryExists($home, 0700);

            $this->checkout->clone($repository, $question->branch, null, $directory);

            // The mount point of the scripts, the worker's own whatever the branch has there.
            foreach (["{$directory}/.review", "{$directory}/.review/skill", "{$directory}/review-answer.json"] as $path) {
                if (is_link($path)) {
                    unlink($path);
                }
            }

            File::delete("{$directory}/review-answer.json");
            File::ensureDirectoryExists("{$directory}/.review/skill");

            $settings = ReviewSettings::variables($repository, null, (string) $question->pull_request_id);

            // Where the repository's reviews run.
            $result = $this->sandboxes->for(null, $repository, $this->sandbox)->run(
                "review-{$repository->getKey()}-question-{$question->getKey()}",
                'sh .review/skill/answer-question.sh',
                $directory,
                $this->credentials->environment($repository->tenant)
                    + array_intersect_key($settings, ['CLAUDE_REVIEW_MODEL' => true])
                    + ['REVIEW_QUESTION' => $this->asked($question), 'CI' => 'true'],
                trusted: false,
                timeout: (int) config('review.container.question_timeout'),
                home: $home,
            );

            $answer = $result->successful() ? $this->read("{$directory}/review-answer.json") : null;

            if ($answer === null) {
                Log::warning('A question for the reviewer got no answer', ['repository' => $repository->fullName(), 'question' => $question->getKey(), 'exit' => $result->exitCode()]);
                $this->fail($question);

                return;
            }

            // Asked on the review's page: answered there alone.
            $reply = $question->comment_id === null ? null : $this->questions->reply($repository, $question->pull_request_id, $question->comment_id, $answer['text']);

            $question->update([
                'answer' => $answer['text'],
                'answer_comment_id' => $reply,
                'status' => ReviewQuestion::STATUS_ANSWERED,
                'resumed' => $resumed,
                'model' => $answer['model'],
                'cost' => $answer['cost'],
                'answered_at' => now(),
            ]);
        } finally {
            File::deleteDirectory($directory);
            File::deleteDirectory($home);
        }
    }

    /**
     * The question as the reviewer gets it: a follow-up with what it follows, since
     * the session it answers from is the review's and not the thread's.
     */
    protected function asked(ReviewQuestion $question): string
    {
        $earlier = $question->follows;

        // Asked under one of the review's findings: that finding is what it is about.
        if (filled($question->about) && ($earlier === null || $earlier->answer === null)) {
            return "This is asked in the thread of a finding the review posted on the pull request.\n\n"
                ."The finding:\n".mb_substr((string) $question->about, 0, 4000)."\n\n"
                ."The question:\n".$question->question;
        }

        if ($earlier === null || $earlier->answer === null) {
            return $question->question;
        }

        return "This follows up on an earlier question in the same thread.\n\n"
            ."Earlier question:\n".mb_substr($earlier->question, 0, 1000)."\n\n"
            ."Your answer then:\n".mb_substr($earlier->answer, 0, 3000)."\n\n"
            ."The follow-up:\n".$question->question;
    }

    /**
     * Notes that there is no answer, and says so under the comment that asked, so
     * nobody waits for one. A question asked on a review's page shows it there.
     */
    public function fail(ReviewQuestion $question): void
    {
        $question->update(['status' => ReviewQuestion::STATUS_FAILED, 'answered_at' => now()]);

        if ($question->comment_id !== null) {
            $this->questions->reply($question->repository, $question->pull_request_id, $question->comment_id, 'I could not answer this question: the reviewer stopped without an answer. Asking again starts a new attempt.');
        }
    }

    /**
     * The answer Claude Code wrote, from a plain file of a sane size.
     *
     * @return array{text: string, cost: float|null, model: string|null}|null
     */
    protected function read(string $path): ?array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > 2_000_000) {
            return null;
        }

        $result = json_decode((string) File::get($path), true);
        $text = is_array($result) && is_string($result['result'] ?? null) && ($result['is_error'] ?? false) !== true ? trim($result['result']) : '';

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::LONGEST) {
            $text = mb_substr($text, 0, self::LONGEST)."\n\n(The answer was longer and is cut here.)";
        }

        $models = is_array($result['modelUsage'] ?? null) ? array_keys($result['modelUsage']) : [];

        return [
            'text' => $text,
            'cost' => is_numeric($result['total_cost_usd'] ?? null) ? (float) $result['total_cost_usd'] : null,
            'model' => is_string($models[0] ?? null) ? $models[0] : null,
        ];
    }
}
