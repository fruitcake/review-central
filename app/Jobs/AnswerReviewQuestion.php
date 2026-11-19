<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ReviewQuestion;
use App\Runners\Container\QuestionAnswer;
use App\Services\ReviewQuestions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Answers one question someone asked the reviewer on a pull request.
 *
 * Tried once: a second try could post a second answer.
 */
class AnswerReviewQuestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $questionId)
    {
        // Above the reviewer's own limit, with room for the clone.
        $this->timeout = (int) config('review.container.question_timeout') + 300;
    }

    public function handle(QuestionAnswer $answer, ReviewQuestions $questions): void
    {
        $question = ReviewQuestion::query()->with('repository.tenant')->find($this->questionId);

        // A reply under an answer that names nobody: first whether it is for the reviewer.
        if ($question?->status === ReviewQuestion::STATUS_UNSURE) {
            $meant = $questions->isMeant($question);
            $question->update(['status' => $meant ? ReviewQuestion::STATUS_ASKED : ReviewQuestion::STATUS_IGNORED]);

            // Counted only now that it is the reviewer's to answer.
            if ($meant && ! $questions->withinLimit($question->repository, $question->pull_request_id)) {
                $question->update(['status' => ReviewQuestion::STATUS_FAILED, 'answered_at' => now()]);

                if ($question->comment_id !== null) {
                    $questions->reply($question->repository, $question->pull_request_id, $question->comment_id, ReviewQuestions::overLimit());
                }

                return;
            }
        }

        // Removed while the job waited, or answered by a job before this one.
        if ($question === null || $question->status !== ReviewQuestion::STATUS_ASKED) {
            return;
        }

        $answer->give($question);
    }

    public function failed(?Throwable $exception): void
    {
        $question = ReviewQuestion::query()->with('repository')->find($this->questionId);

        if ($question !== null && $question->status === ReviewQuestion::STATUS_ASKED) {
            app(QuestionAnswer::class)->fail($question);
        }
    }
}
