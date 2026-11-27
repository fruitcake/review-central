<?php

use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Jobs\AnswerReviewQuestion;
use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewQuestion;
use App\Models\ReviewRun;
use App\Models\User;
use App\Runners\Container\QuestionAnswer;
use App\Runners\Container\ReviewSessions;
use App\Runners\Container\RunLog;
use App\Services\FindingSettlement;
use App\Services\ReviewQuestions;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['review.docker.enabled' => true, 'review.container.work_directory' => storage_path('framework/testing/review-work')]);

    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->repository->integration->update(['webhook_key' => 'hook-one', 'webhook_secret' => 'secret']);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);

    Http::fake(['api.bitbucket.org/*' => Http::response(['id' => 901])]);
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/review-work')));

/**
 * @param  array<string, mixed>  $more  what else the host says of the comment: its parent, its author's account
 */
function comment(string $text, int $id = 77, string $state = 'OPEN', array $more = []): TestResponse
{
    $body = json_encode([
        'repository' => ['full_name' => 'acme/shop'],
        'pullrequest' => ['id' => 570, 'state' => $state, 'source' => ['branch' => ['name' => 'feature/export']]],
        'comment' => array_replace_recursive(['id' => $id, 'content' => ['raw' => $text], 'user' => ['display_name' => 'Alex']], $more),
    ]);

    return test()->call('POST', '/api/webhooks/hook-one', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_EVENT_KEY' => 'pullrequest:comment_created',
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
    ], $body);
}

/**
 * Fakes git and docker; the reviewer writes this as its answer.
 *
 * @param  array<string, mixed>|null  $answer
 */
function fakeReviewer(?array $answer, int $exit = 0): ArrayObject
{
    $steps = new ArrayObject;

    Process::fake(function ($process) use ($steps, $answer, $exit) {
        $command = (array) $process->command;
        $steps[] = ['command' => $command, 'environment' => $process->environment];

        if ($command[0] === 'git') {
            if ($command[1] === 'clone') {
                File::ensureDirectoryExists(end($command));
            }

            return Process::result(in_array('rev-parse', $command, true) ? str_repeat('a', 40)."\n" : '');
        }

        if ($command[1] === 'run' && $answer !== null) {
            File::put(explode(':', $command[array_search('--volume', $command, true) + 1])[0].'/review-answer.json', json_encode($answer));
        }

        return Process::result(output: $command[1] === 'run' ? "Reading app/Export.php\n" : '', exitCode: $command[1] === 'run' ? $exit : 0);
    });

    return $steps;
}

it('takes a comment that starts with @review as a question, and nothing else', function (string $comment, ?string $question) {
    expect(ReviewQuestions::isQuestion($comment))->toBe($question !== null);

    if ($question !== null) {
        expect(ReviewQuestions::question($comment))->toBe($question);
    }
})->with([
    ['@review did you mean the export in F2?', 'did you mean the export in F2?'],
    ["  @Review: how does the lock work?\n\nI could not find it.", "how does the lock work?\n\nI could not find it."],
    ['@review', null],
    ['@reviewer what about this?', null],
    ['Thanks @review that helps', null],
    ['/review please look again', null],
]);

it('queues an answer for a question on a pull request, once, and no review', function () {
    Queue::fake();

    comment('@review did you mean the export or the import in F2?')->assertOk();
    // The host delivers the same comment again.
    comment('@review did you mean the export or the import in F2?')->assertOk();

    $question = ReviewQuestion::query()->sole();

    expect($question->only(['pull_request_id', 'branch', 'comment_id', 'author', 'question', 'status']))->toBe([
        'pull_request_id' => 570, 'branch' => 'feature/export', 'comment_id' => 77, 'author' => 'Alex',
        'question' => 'did you mean the export or the import in F2?', 'status' => ReviewQuestion::STATUS_ASKED,
    ]);

    Queue::assertPushedOn('reviews', AnswerReviewQuestion::class);
    Queue::assertPushed(AnswerReviewQuestion::class, 1);
    Queue::assertNotPushed(RunContainerReview::class);
});

it('answers from the session of the last review, in a copy of it, and replies under the comment', function () {
    $home = app(ReviewSessions::class)->home($this->repository, 'feature/export');
    File::put("{$home}/.review-session", "session-one\n");
    File::ensureDirectoryExists("{$home}/.claude/projects/work");
    File::put("{$home}/.claude/projects/work/session-one.jsonl", '{}');

    $question = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'branch' => 'feature/export', 'comment_id' => 77, 'question' => 'Did you mean the export in F2?',
    ]);

    $steps = fakeReviewer(['type' => 'result', 'result' => 'The export, at `app/Export.php:12`.', 'total_cost_usd' => 0.42, 'modelUsage' => ['claude-opus-5-5' => []]]);

    app(QuestionAnswer::class)->give($question);

    $step = collect($steps)->first(fn (array $step): bool => $step['command'][1] === 'run');
    $volumes = implode(' ', $step['command']);

    // The reviewer gets the AI key and the question, in the sandbox a review runs in,
    // and a home of its own that is not the branch's.
    expect($step['environment'])->toMatchArray(['ANTHROPIC_API_KEY' => 'sk-ant-one', 'REVIEW_QUESTION' => 'Did you mean the export in F2?'])
        ->not->toHaveKey('REVIEW_API_KEY')
        ->and($step['command'])->toContain('--runtime=runsc', '--cap-drop=ALL')
        ->and(end($step['command']))->toContain('answer-question.sh')
        ->and($volumes)->toContain('-home:/home/reviewer')->not->toContain("{$home}:")
        // With the repository's Composer cache, for vendor/ as the review has it.
        ->and($volumes)->toContain(':/cache/composer')
        ->and($step['environment'])->toHaveKey('COMPOSER_CACHE_DIR')
        // The branch's own session is as it was, and nothing of the question stays behind.
        ->and(File::get("{$home}/.review-session"))->toBe("session-one\n")
        ->and(File::directories(storage_path('framework/testing/review-work')))->toBe([]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pullrequests/570/comments')
        && $request['parent'] === ['id' => 77]
        && str_starts_with($request['content']['raw'], 'The export, at `app/Export.php:12`.')
        // Marked as the review's own, so the next review does not read it as a person talking.
        && str_contains($request['content']['raw'], '(ai-review-reply)'));

    expect($question->refresh()->only(['status', 'answer', 'resumed', 'model']))->toBe([
        'status' => ReviewQuestion::STATUS_ANSWERED, 'answer' => 'The export, at `app/Export.php:12`.', 'resumed' => true, 'model' => 'claude-opus-5-5',
    ])->and((float) $question->cost)->toBe(0.42);

    // What it did while it answered is kept, as a review's log is, without its key.
    expect(app(RunLog::class)->questionTail($question)['text'])
        ->toContain('── clone ──', 'Answering from the session of the last review.', 'Reading app/Export.php', 'Answered.')
        ->not->toContain('sk-ant-one');
});

it('answers a question about a trial from the trial\'s own session, with its findings and the branch to diff against', function () {
    $run = ReviewRun::query()->forceCreate([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 570, 'commit' => str_repeat('c', 40), 'build_number' => 9, 'custom' => true,
        'status' => ReviewRun::STATUS_UNPUBLISHED, 'started_at' => now(), 'summary' => 'One problem in the export.',
        'context' => ['pull_request' => ['destination_branch' => 'main']],
        'findings' => [['id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'title' => 'Customers can load every ride', 'path' => 'app/RideController.php', 'line' => 317, 'details' => 'Any account can call it.']],
    ]);

    // The trial's own session, apart from the branch's, which has none.
    $home = app(ReviewSessions::class)->home($this->repository, ReviewSessions::forTrial('feature/export', 9));
    File::put("{$home}/.review-session", "trial-session\n");

    $question = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $run->id, 'branch' => 'feature/export', 'comment_id' => null, 'question' => 'Did you verify F1?',
    ]);

    $steps = fakeReviewer(['type' => 'result', 'result' => 'Yes, at `app/RideController.php:317`.', 'total_cost_usd' => 0.1, 'modelUsage' => ['claude-opus-5-5' => []]]);

    app(QuestionAnswer::class)->give($question);

    $asked = collect($steps)->first(fn (array $step): bool => $step['command'][1] === 'run')['environment']['REVIEW_QUESTION'];

    expect($asked)->toContain('git diff origin/main...HEAD', 'was a trial', 'One problem in the export.', 'F1 (high, new) Customers can load every ride — app/RideController.php:317', 'Any account can call it.')
        ->and($asked)->toEndWith('Did you verify F1?')
        ->and(collect($steps)->contains(fn (array $step): bool => $step['command'][0] === 'git' && in_array('+refs/heads/main:refs/remotes/origin/main', $step['command'], true)))->toBeTrue()
        ->and($question->refresh()->resumed)->toBeTrue();
});

it('says so under the comment when the reviewer gives no answer', function (?array $answer, int $exit) {
    $question = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'branch' => 'feature/export', 'comment_id' => 77, 'question' => 'How does the lock work?',
    ]);

    fakeReviewer($answer, $exit);

    app(QuestionAnswer::class)->give($question);

    expect($question->refresh()->only(['status', 'answer', 'resumed']))->toBe(['status' => ReviewQuestion::STATUS_FAILED, 'answer' => null, 'resumed' => false]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['parent'] === ['id' => 77]
        && str_starts_with($request['content']['raw'], 'I could not answer this question'));
})->with([
    'the step failed' => [null, 1],
    'no answer was written' => [null, 0],
    'an error for an answer' => [['result' => 'Out of budget', 'is_error' => true], 0],
]);

it('tells the asker why a question cannot be answered here, and stops answering a thread that keeps asking', function () {
    Queue::fake();

    config(['review.docker.enabled' => false, 'review.cloudflare.local' => false]);
    comment('@review how does this work?', 1)->assertOk();

    config(['review.docker.enabled' => true]);
    Connection::query()->where('driver', 'anthropic')->delete();
    comment('@review how does this work?', 2)->assertOk();

    Queue::assertNothingPushed();
    Http::assertSent(fn (Request $request): bool => ($request['parent']['id'] ?? null) === 1 && str_contains($request['content']['raw'], 'Docker or a Cloudflare sandbox'));
    Http::assertSent(fn (Request $request): bool => ($request['parent']['id'] ?? null) === 2 && str_contains($request['content']['raw'], 'Anthropic connection'));

    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    foreach (range(10, 20) as $id) {
        comment('@review and this?', $id)->assertOk();
    }

    // Ten an hour for a pull request; the eleventh is told to wait.
    Queue::assertPushed(AnswerReviewQuestion::class, 10);
    Http::assertSent(fn (Request $request): bool => ($request['parent']['id'] ?? null) === 20 && str_contains($request['content']['raw'], 'Ask again later'));
});

it('answers a question on a repository whose pull requests are not reviewed by themselves', function () {
    Queue::fake();
    $this->repository->update(['reviews_enabled' => false]);

    comment('@review how does this work?')->assertOk();

    expect(ReviewQuestion::query()->count())->toBe(1);
    Queue::assertPushed(AnswerReviewQuestion::class, 1);
});

it('lists the questions asked on a pull request on its review\'s page, with what each cost', function () {
    $user = User::factory()->create();
    $user->tenants()->attach($this->repository->tenant);
    $this->repository->tenant->update(['slug' => 'acme']);

    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'feature/export',
        'commit' => str_repeat('a', 40), 'build_number' => 41, 'pull_request_id' => 570, 'status' => 'finished', 'result' => 'PASSED', 'started_at' => now(),
    ]);
    $asked = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export', 'comment_id' => 77,
        'author' => 'Alex', 'question' => 'Did you mean the export in F2?', 'answer' => 'The export, at `app/Export.php:12`. <script>alert(1)</script>',
        'status' => ReviewQuestion::STATUS_ANSWERED, 'resumed' => true, 'cost' => 0.42,
    ]);
    File::ensureDirectoryExists(dirname(app(RunLog::class)->questionPath($asked)));
    File::put(app(RunLog::class)->questionPath($asked), "── reviewer ──\nReading app/Export.php\n");
    // Another pull request's question is not this one's.
    ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 571, 'branch' => 'feature/other', 'comment_id' => 78,
        'question' => 'Something else entirely?',
    ]);

    $this->actingAs($user)->get("/app/acme/review-runs/{$run->uuid}")->assertOk()
        ->assertSee('Did you mean the export in F2?')
        ->assertSee('<code>app/Export.php:12</code>', false)
        ->assertSee('Answered from the last review&#039;s session', false)
        ->assertSee('$0.42')
        ->assertSee('Reading app/Export.php')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('Something else entirely?');
});

it('takes a mention of the account it comments as for a question, wherever it stands', function () {
    Queue::fake();
    $this->repository->integration->update(['settings' => ['account_id' => '712020:bot']]);

    comment('So @{712020:bot} is this right as it is now?', 78)->assertOk();
    // For a colleague, and its own reply as the host delivers it back.
    comment('@{712020:nora} can you look at this?', 79)->assertOk();
    comment('It is.', 80, more: ['user' => ['account_id' => '712020:bot']])->assertOk();

    expect(ReviewQuestion::query()->sole()->only(['comment_id', 'question', 'status']))
        ->toBe(['comment_id' => 78, 'question' => 'So  is this right as it is now?', 'status' => ReviewQuestion::STATUS_ASKED]);
});

it('asks the host once who it comments as, and remembers', function () {
    Queue::fake();
    Http::swap(new Factory);
    Http::fake(['api.bitbucket.org/2.0/user*' => Http::response(['account_id' => '712020:bot']), 'api.bitbucket.org/*' => Http::response(['id' => 901])]);

    comment('@{712020:bot} why is this a .cjs file?', 78)->assertOk();
    comment('@{712020:bot} and the other one?', 79)->assertOk();

    expect(ReviewQuestion::query()->count())->toBe(2)
        ->and($this->repository->integration->refresh()->setting('account_id'))->toBe('712020:bot');

    Http::assertSentCount(1);
});

it('does not keep asking a host that will not say who it comments as', function () {
    Queue::fake();
    Http::swap(new Factory);
    Http::fake(['api.bitbucket.org/2.0/user*' => Http::response([], 403)]);

    comment('@{712020:bot} why is this a .cjs file?', 78)->assertOk();
    comment('@{712020:bot} and the other one?', 79)->assertOk();

    expect(ReviewQuestion::query()->count())->toBe(0);

    Http::assertSentCount(1);
});

it('still takes /review as the command under one of its own answers', function () {
    Queue::fake();
    ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => 77, 'answer_comment_id' => 901, 'question' => 'Why .cjs?', 'answer' => 'Node reads it as CommonJS.', 'status' => ReviewQuestion::STATUS_ANSWERED,
    ]);

    comment('/review', 78, more: ['parent' => ['id' => 901]])->assertOk();

    expect(ReviewQuestion::query()->count())->toBe(1);
    Queue::assertNotPushed(AnswerReviewQuestion::class);
});

it('takes a reply under its own answer as a follow-up, unless it names someone else', function () {
    Queue::fake();
    $this->repository->integration->update(['settings' => ['account_id' => '712020:bot']]);
    $earlier = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => 77, 'answer_comment_id' => 901, 'question' => 'Why .cjs?', 'answer' => 'Node reads it as CommonJS.', 'status' => ReviewQuestion::STATUS_ANSWERED,
    ]);

    comment('ok so this is simply right as it is?', 78, more: ['parent' => ['id' => 901]])->assertOk();
    comment('@{712020:nora} see above', 79, more: ['parent' => ['id' => 901]])->assertOk();
    // Under someone else's comment it is nobody's question.
    comment('ok so this is simply right as it is?', 80, more: ['parent' => ['id' => 555]])->assertOk();

    // Named nobody, so whether it is for the reviewer is asked before it answers.
    expect(ReviewQuestion::query()->where('id', '!=', $earlier->id)->sole()->only(['comment_id', 'status', 'follows_id']))
        ->toBe(['comment_id' => 78, 'status' => ReviewQuestion::STATUS_UNSURE, 'follows_id' => $earlier->id]);

    Queue::assertPushed(AnswerReviewQuestion::class, 1);
});

it('answers a reply that names nobody only when it is for the reviewer, and then with the thread', function (bool $meant) {
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['answer' => $meant])]]])]);
    $steps = fakeReviewer(['result' => 'Yes, it is.', 'total_cost_usd' => 0.02, 'modelUsage' => ['claude-opus-5-5' => []]]);
    $earlier = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => 77, 'answer_comment_id' => 900, 'question' => 'Why .cjs?', 'answer' => 'Node reads it as CommonJS.', 'status' => ReviewQuestion::STATUS_ANSWERED,
    ]);
    $reply = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => 78, 'follows_id' => $earlier->id, 'question' => $meant ? 'ok so this is simply right as it is?' : 'Thanks!', 'status' => ReviewQuestion::STATUS_UNSURE,
    ]);

    (new AnswerReviewQuestion($reply->id))->handle(app(QuestionAnswer::class), app(ReviewQuestions::class));

    // The small model reads the thread, and a yes costs one container.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request['model'] === 'claude-haiku-5-5' && $request['output_config']['format']['type'] === 'json_schema'
        && str_contains((string) json_encode($request['messages']), 'Node reads it as CommonJS.'));

    $run = collect($steps)->first(fn (array $step): bool => $step['command'][0] === 'docker' && $step['command'][1] === 'run');

    if (! $meant) {
        expect($reply->refresh()->status)->toBe(ReviewQuestion::STATUS_IGNORED)->and($run)->toBeNull();
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/pullrequests/570/comments'));

        return;
    }

    expect($reply->refresh()->only(['status', 'answer', 'answer_comment_id']))->toBe(['status' => ReviewQuestion::STATUS_ANSWERED, 'answer' => 'Yes, it is.', 'answer_comment_id' => 901])
        ->and($run['environment']['REVIEW_QUESTION'])->toContain('Earlier question:', 'Why .cjs?', 'Node reads it as CommonJS.', 'ok so this is simply right as it is?');
})->with([true, false]);

it('asks the provider\'s own classification where the tenant has a provider with one', function () {
    Classification::fake([['answer' => new BooleanAnswer(0.08)]]);
    Connection::factory()->openai('sk-openai-one')->create(['tenant_id' => $this->repository->tenant_id]);
    $earlier = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => 77, 'answer_comment_id' => 900, 'question' => 'Why .cjs?', 'answer' => 'Node reads it as CommonJS.', 'status' => ReviewQuestion::STATUS_ANSWERED,
    ]);
    $reply = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => 78, 'follows_id' => $earlier->id, 'question' => 'Thanks!', 'status' => ReviewQuestion::STATUS_UNSURE,
    ]);

    expect(app(ReviewQuestions::class)->isMeant($reply))->toBeFalse();

    // The thread is what is classified, and no model is asked to write anything.
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => str_contains((string) $prompt->state, 'Thanks!') && str_contains((string) $prompt->state, 'Node reads it as CommonJS.'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.anthropic.com'));
});

it('takes a reply under one of the review\'s findings as maybe for it, and asks about that finding', function (bool $meant) {
    Queue::fake();
    Http::swap(new Factory);
    $finding = "**HIGH** · Security · F1\n\nThe export escapes nothing.\n\n[//]: # (ai-finding id=F1 status=new severity=HIGH path=app/Export.php line=12)";
    Http::fake([
        'api.bitbucket.org/*/pullrequests/570/comments*' => Http::response(['values' => [
            ['id' => 600, 'content' => ['raw' => $finding]],
            ['id' => 601, 'content' => ['raw' => 'Looks fine to me.']],
        ]]),
        'api.bitbucket.org/*' => Http::response(['id' => 901]),
        'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['answer' => $meant])]]]),
    ]);

    comment('eli5 en français, s\'il te plaît', 78, more: ['parent' => ['id' => 600]])->assertOk();
    // Under a person's comment it is nobody's question.
    comment('eli5 stp', 79, more: ['parent' => ['id' => 601]])->assertOk();

    $question = ReviewQuestion::query()->sole();

    expect($question->only(['comment_id', 'status']))->toBe(['comment_id' => 78, 'status' => ReviewQuestion::STATUS_UNSURE])
        ->and($question->about)->toContain('The export escapes nothing.')->not->toContain('ai-finding');

    // The small model reads the finding and the reply.
    expect(app(ReviewQuestions::class)->isMeant($question))->toBe($meant);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
        && str_contains((string) json_encode($request['messages']), 'The export escapes nothing.'));
})->with(['for the reviewer' => true, 'for someone else' => false]);

it('tells the reviewer which finding a question in its thread is about', function () {
    $question = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => 78, 'question' => 'explique-moi ça simplement', 'about' => 'The export escapes nothing.', 'status' => ReviewQuestion::STATUS_ASKED,
    ]);
    $steps = fakeReviewer(['type' => 'result', 'result' => 'L\'export n\'échappe rien.', 'total_cost_usd' => 0.1, 'modelUsage' => ['claude-opus-5-5' => []]]);

    app(QuestionAnswer::class)->give($question);

    $step = collect($steps)->first(fn (array $step): bool => $step['command'][1] === 'run');

    expect($step['environment']['REVIEW_QUESTION'])->toContain('The finding:', 'The export escapes nothing.', 'The question:', 'explique-moi ça simplement');
});

it('takes no reply settled through Review Central for a question, under a finding or naming the reviewer', function () {
    Queue::fake();
    Http::swap(new Factory);
    $finding = "The export escapes nothing.\n\n[//]: # (ai-finding id=F1 status=new severity=HIGH path=app/Export.php line=12)";
    Http::fake([
        'api.bitbucket.org/*/pullrequests/570/comments*' => Http::response(['values' => [['id' => 600, 'content' => ['raw' => $finding]]]]),
        'api.bitbucket.org/*' => Http::response(['id' => 901]),
    ]);

    comment(FindingSettlement::ownBody('Could you look again? CsvWriter escapes it.'), 78, more: ['parent' => ['id' => 600]])->assertOk();
    comment(FindingSettlement::ownBody('review, this is handled in CsvWriter.'), 79)->assertOk();

    expect(ReviewQuestion::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    // The same words typed by the person are still a reply to consider.
    comment('Could you look again? CsvWriter escapes it.', 80, more: ['parent' => ['id' => 600]])->assertOk();

    expect(ReviewQuestion::query()->sole()->comment_id)->toBe(80);
});

it('counts a reply under a finding against the hour only once it is for the reviewer', function () {
    Queue::fake();
    Http::swap(new Factory);
    $finding = "The export escapes nothing.\n\n[//]: # (ai-finding id=F1 status=new severity=HIGH path=app/Export.php line=12)";
    Http::fake([
        'api.bitbucket.org/*/pullrequests/570/comments*' => Http::response(['values' => [['id' => 600, 'content' => ['raw' => $finding]]]]),
        'api.bitbucket.org/*' => Http::response(['id' => 901]),
        'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['answer' => false])]]]),
    ]);

    // Ten acknowledgements under findings, which are for nobody.
    foreach (range(100, 109) as $id) {
        comment('fixed', $id, more: ['parent' => ['id' => 600]])->assertOk();
    }

    ReviewQuestion::query()->each(function (ReviewQuestion $reply): void {
        (new AnswerReviewQuestion($reply->id))->handle(app(QuestionAnswer::class), app(ReviewQuestions::class));
    });

    expect(ReviewQuestion::query()->where('status', ReviewQuestion::STATUS_IGNORED)->count())->toBe(10);

    // A real question after them is still taken.
    comment('@review why is this a problem?', 200)->assertOk();

    expect(ReviewQuestion::query()->where('comment_id', 200)->sole()->status)->toBe(ReviewQuestion::STATUS_ASKED);
    Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['content']['raw'] ?? ''), 'Ask again later'));
});

it('takes a question on the review\'s page and answers it there alone, saying nothing on the pull request', function () {
    Queue::fake();
    $user = User::factory()->create(['name' => 'Sam Sample']);
    $user->tenants()->attach($this->repository->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
    ]);

    livewire(ViewReviewRun::class, ['record' => $run->uuid])
        ->assertSee('Ask the reviewer about this pull request')
        ->set('question', 'Why is F1 a problem?')
        ->call('ask')
        ->assertHasNoErrors()
        ->assertSet('question', '')
        ->assertNotified('Asked');

    $question = ReviewQuestion::query()->sole();

    expect($question->only(['comment_id', 'user_id', 'author', 'question', 'pull_request_id', 'status']))
        ->toBe(['comment_id' => null, 'user_id' => $user->id, 'author' => 'Sam Sample', 'question' => 'Why is F1 a problem?', 'pull_request_id' => 570, 'status' => ReviewQuestion::STATUS_ASKED]);
    Queue::assertPushed(AnswerReviewQuestion::class, 1);

    // Answered: on the page, and not on the pull request.
    $steps = fakeReviewer(['type' => 'result', 'result' => 'It escapes nothing.', 'total_cost_usd' => 0.1, 'modelUsage' => ['claude-opus-5-5' => []]]);
    app(QuestionAnswer::class)->give($question);

    expect($question->refresh()->only(['status', 'answer', 'answer_comment_id']))->toBe(['status' => ReviewQuestion::STATUS_ANSWERED, 'answer' => 'It escapes nothing.', 'answer_comment_id' => null]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/comments'));

    livewire(ViewReviewRun::class, ['record' => $run->uuid])->assertSee('It escapes nothing.');
});

it('prints what the reviewer reads and says while it answers, and keeps the result for the worker', function () {
    $dir = storage_path('framework/testing/review-work/answer');
    File::ensureDirectoryExists("{$dir}/home/.local/bin");
    File::put("{$dir}/home/.local/bin/claude", <<<'SH'
        #!/bin/sh
        [ "$1" = "--version" ] && { echo "2.0.0 (Claude Code)"; exit 0; }
        cat > /dev/null
        echo '{"type":"system","subtype":"init","session_id":"s-1"}'
        echo '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Read","input":{"file_path":"app/Export.php"}}]}}'
        echo '{"type":"assistant","message":{"content":[{"type":"text","text":"It escapes nothing."}]}}'
        echo '{"type":"result","subtype":"success","is_error":false,"result":"It escapes nothing.","total_cost_usd":0.12,"session_id":"s-1"}'
        SH);
    chmod("{$dir}/home/.local/bin/claude", 0755);

    $result = Process::path($dir)
        ->env(['HOME' => "{$dir}/home", 'REVIEW_LOCAL' => 'true', 'REVIEW_QUESTION' => 'Does the export escape its cells?'])
        ->run(['sh', resource_path('review/skill/answer-question.sh')]);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('-> Read app/Export.php')
        ->and($result->output())->toContain('It escapes nothing.')
        ->and($result->output())->toContain('Answered, for $0.12.')
        ->and(json_decode(File::get("{$dir}/review-answer.json"), true))->toMatchArray(['type' => 'result', 'result' => 'It escapes nothing.']);
});

it('gives the question vendor/ from what the review keeps, installing without the packages\' scripts', function () {
    $dir = storage_path('framework/testing/question-vendor');
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/work/vendor/acme/lib");
    File::ensureDirectoryExists("{$dir}/bin");
    File::put("{$dir}/work/vendor/acme/lib/Lib.php", '<?php');
    Process::path("{$dir}/work")->run(['tar', '-cf', "{$dir}/vendor-base.tar", 'vendor'])->throw();
    File::deleteDirectory("{$dir}/work/vendor");
    File::put("{$dir}/work/composer.json", '{}');
    // A Composer that says how it was asked.
    File::put("{$dir}/bin/composer", "#!/bin/sh\necho \"composer \$*\" > \"{$dir}/composer-called\"\n");
    chmod("{$dir}/bin/composer", 0755);
    Process::path("{$dir}/work")->run(['git', 'init', '-q'])->throw();
    $skill = realpath(__DIR__.'/../../resources/review/skill');

    $result = Process::path("{$dir}/work")
        ->env(['REVIEW_VENDOR_BASE' => "{$dir}/vendor-base.tar", 'COMPOSER_AUTH' => '{"secret":1}', 'PATH' => "{$dir}/bin:".getenv('PATH')])
        ->run(['sh', '-c', ". {$skill}/reviewer-env.sh; reviewer_vendor; echo \"auth:\${COMPOSER_AUTH:-gone}\""])->throw();

    expect($result->output())->toContain('vendor/ of the destination branch unpacked')
        ->toContain('auth:gone')
        ->and(File::exists("{$dir}/work/vendor/acme/lib/Lib.php"))->toBeTrue()
        ->and(File::get("{$dir}/composer-called"))->toContain('install')->toContain('--no-scripts')->toContain('--no-plugins')->toContain('--no-dev');

    File::deleteDirectory($dir);
});

it('installs no binary where the pull request\'s composer.json points, such as over the reviewer\'s CLI', function () {
    $dir = storage_path('framework/testing/question-bin-dir');
    File::deleteDirectory($dir);
    // A package with a binary named claude, and a project that puts binaries and
    // packages beside the reviewer's CLI.
    File::ensureDirectoryExists("{$dir}/work/pkg");
    File::ensureDirectoryExists("{$dir}/home/.local/bin");
    File::put("{$dir}/work/pkg/composer.json", json_encode(['name' => 'evil/pkg', 'version' => '1.0.0', 'bin' => ['claude']]));
    File::put("{$dir}/work/pkg/claude", "#!/bin/sh\necho stolen\n");
    File::put("{$dir}/work/composer.json", json_encode([
        'repositories' => [['type' => 'path', 'url' => 'pkg', 'options' => ['symlink' => false]], ['packagist.org' => false]],
        'require' => ['evil/pkg' => '1.0.0'],
        'config' => ['bin-dir' => "{$dir}/home/.local/bin", 'vendor-dir' => "{$dir}/home/.local/vendor"],
    ]));
    Process::path("{$dir}/work")->run(['git', 'init', '-q'])->throw();
    $skill = realpath(__DIR__.'/../../resources/review/skill');

    Process::path("{$dir}/work")->env(['HOME' => "{$dir}/home", 'COMPOSER_HOME' => "{$dir}/composer"])
        ->timeout(120)->run(['sh', '-c', ". {$skill}/reviewer-env.sh; reviewer_vendor"])->throw();

    expect(File::exists("{$dir}/home/.local/bin/claude"))->toBeFalse()
        ->and(File::exists("{$dir}/home/.local/vendor"))->toBeFalse()
        ->and(File::exists("{$dir}/work/vendor/evil/pkg/claude"))->toBeTrue();

    File::deleteDirectory($dir);
});

it('installs the rest of vendor/ for a repository that commits part of it', function () {
    $dir = storage_path('framework/testing/question-committed-vendor');
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/work/vendor/own");
    File::ensureDirectoryExists("{$dir}/bin");
    File::put("{$dir}/work/vendor/own/Patch.php", '<?php');
    File::put("{$dir}/work/composer.json", '{}');
    File::put("{$dir}/bin/composer", "#!/bin/sh\necho \"composer \$*\" > \"{$dir}/composer-called\"\n");
    chmod("{$dir}/bin/composer", 0755);
    Process::path("{$dir}/work")->run(['sh', '-c', 'git init -q && git add -A && git -c user.name=t -c user.email=t@example.com commit -qm vendor'])->throw();
    $skill = realpath(__DIR__.'/../../resources/review/skill');

    Process::path("{$dir}/work")->env(['PATH' => "{$dir}/bin:".getenv('PATH')])
        ->run(['sh', '-c', ". {$skill}/reviewer-env.sh; reviewer_vendor"])->throw();

    expect(File::get("{$dir}/composer-called"))->toContain('install');

    File::deleteDirectory($dir);
});

it('lets an answer search the web, and never fetch a page, only where the team allows it', function (bool $allowed) {
    $dir = storage_path('framework/testing/review-work/answer-search');
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/home/.local/bin");
    // A Claude Code that keeps the settings and the prompt it was given.
    File::put("{$dir}/home/.local/bin/claude", <<<SH
        #!/bin/sh
        [ "\$1" = "--version" ] && { echo "2.0.0 (Claude Code)"; exit 0; }
        while [ \$# -gt 0 ]; do [ "\$1" = "--settings" ] && cp "\$2" "{$dir}/settings.json"; shift; done
        cat > "{$dir}/prompt.txt"
        echo '{"type":"result","subtype":"success","is_error":false,"result":"Laravel 12.73 changed it.","total_cost_usd":0.1}'
        SH);
    chmod("{$dir}/home/.local/bin/claude", 0755);

    Process::path($dir)
        ->env(['HOME' => "{$dir}/home", 'REVIEW_QUESTION' => 'Which release changed route()?'] + ($allowed ? ['REVIEW_QUESTION_WEB_SEARCH' => 'true'] : []))
        ->run(['sh', resource_path('review/skill/answer-question.sh')])->throw();
    $permissions = json_decode(File::get("{$dir}/settings.json"), true)['permissions'];

    $settings = json_decode(File::get("{$dir}/settings.json"), true);

    expect($permissions['deny'])->toContain('WebFetch')
        // Every search past the guard first, which refuses where it cannot run.
        ->and($settings['disableAllHooks'])->toBe(! $allowed)
        ->and($settings['hooks']['PreToolUse'][0] ?? null)->toBe($allowed ? ['matcher' => 'WebSearch', 'hooks' => [['type' => 'command', 'command' => "php -n '".realpath(resource_path('review/skill'))."/search-guard.php' || exit 2"]]] : null)
        ->and(in_array('WebSearch', $permissions['allow'], true))->toBe($allowed)
        ->and(in_array('WebSearch', $permissions['deny'], true))->toBe(! $allowed)
        ->and(str_contains(File::get("{$dir}/prompt.txt"), 'never with code, names, paths or anything else from this repository'))->toBe($allowed);

    File::deleteDirectory($dir);
})->with(['allowed' => true, 'not allowed' => false]);

it('hands the answer the team\'s choice on searching the web, and the review never', function () {
    $this->repository->reviewSetting()->create(['tenant_id' => $this->repository->tenant_id, 'question_web_search' => 'true']);
    $question = ReviewQuestion::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'branch' => 'feature/export', 'comment_id' => 77, 'question' => 'Which release changed this?',
    ]);
    $steps = fakeReviewer(['type' => 'result', 'result' => 'Release 12.73.', 'total_cost_usd' => 0.1, 'modelUsage' => ['claude-opus-5-5' => []]]);

    app(QuestionAnswer::class)->give($question);

    $step = collect($steps)->first(fn (array $step): bool => $step['command'][1] === 'run');
    expect($step['environment'])->toMatchArray(['REVIEW_QUESTION_WEB_SEARCH' => 'true'])
        ->and(file_get_contents(resource_path('review/skill/review-settings.sh')))->not->toContain('REVIEW_QUESTION_WEB_SEARCH');
});

it('lets a search go with public names only, and refuses one with a name of the repository\'s own', function (string $query, ?string $refused) {
    $dir = storage_path('framework/testing/search-guard');
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/app/Http/Controllers");
    File::ensureDirectoryExists("{$dir}/vendor/laravel/framework/src");
    File::put("{$dir}/app/Http/Controllers/UpsellController.php", "<?php\n\nclass UpsellController\n{\n    public function store()\n    {\n        return route('upsell').'?'.\$query;\n    }\n}\n");
    File::put("{$dir}/vendor/laravel/framework/src/RouteUrlGenerator.php", "<?php\n\nclass RouteUrlGenerator\n{\n    // encodes the query string of a route\n}\n");
    File::put("{$dir}/composer.lock", '{"packages":[{"name":"laravel/framework","version":"v12.74.0"}]}');
    File::put("{$dir}/.gitignore", "/vendor\n");
    Process::path($dir)->run(['sh', '-c', 'git init -q && git add -A && git -c user.name=t -c user.email=t@example.com commit -qm app'])->throw();

    $result = Process::path($dir)->input(json_encode(['tool_name' => 'WebSearch', 'tool_input' => ['query' => $query]]))
        ->run(['php', '-n', resource_path('review/skill/search-guard.php')]);

    expect($result->exitCode())->toBe($refused === null ? 0 : 2)
        ->and($result->errorOutput())->toContain($refused ?? '');

    File::deleteDirectory($dir);
})->with([
    'a framework class and a release' => ['laravel 12.74 RouteUrlGenerator query string encoding', null],
    'the repository\'s own class' => ['UpsellController store route query', '`UpsellController` is in this repository and in no installed package'],
    'code' => ['laravel route() $query encoding', 'it holds code'],
    'a passage' => [str_repeat('laravel route encoding ', 12), 'a search is a few public words'],
]);

it('follows no symlink the pull request commits under vendor/, such as vendor/bin to the reviewer\'s CLI', function () {
    $dir = storage_path('framework/testing/question-vendor-link');
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/work/pkg");
    File::ensureDirectoryExists("{$dir}/work/vendor/own");
    File::ensureDirectoryExists("{$dir}/home/.local/bin");
    File::put("{$dir}/home/.local/bin/claude", "#!/bin/sh\necho real\n");
    File::put("{$dir}/work/vendor/own/Patch.php", '<?php');
    symlink("{$dir}/home/.local/bin", "{$dir}/work/vendor/bin");
    File::put("{$dir}/work/pkg/composer.json", json_encode(['name' => 'evil/pkg', 'version' => '1.0.0', 'bin' => ['claude']]));
    File::put("{$dir}/work/pkg/claude", "#!/bin/sh\necho stolen\n");
    File::put("{$dir}/work/composer.json", json_encode([
        'repositories' => [['type' => 'path', 'url' => 'pkg', 'options' => ['symlink' => false]], ['packagist.org' => false]],
        'require' => ['evil/pkg' => '1.0.0'],
    ]));
    Process::path("{$dir}/work")->run(['sh', '-c', 'git init -q && git add -A && git -c user.name=t -c user.email=t@example.com commit -qm vendor'])->throw();
    $skill = realpath(__DIR__.'/../../resources/review/skill');

    Process::path("{$dir}/work")->env(['HOME' => "{$dir}/home", 'COMPOSER_HOME' => "{$dir}/composer"])
        ->timeout(120)->run(['sh', '-c', ". {$skill}/reviewer-env.sh; reviewer_vendor"])->throw();

    expect(File::get("{$dir}/home/.local/bin/claude"))->toBe("#!/bin/sh\necho real\n")
        ->and(is_link("{$dir}/work/vendor/bin"))->toBeFalse()
        ->and(File::exists("{$dir}/work/vendor/evil/pkg/claude"))->toBeTrue();

    File::deleteDirectory($dir);
});

it('answers nothing when Claude Code changed while vendor/ was installed', function () {
    $dir = storage_path('framework/testing/review-work/answer-swap');
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/home/.local/bin");
    File::ensureDirectoryExists("{$dir}/bin");
    File::put("{$dir}/home/.local/bin/claude", "#!/bin/sh\n[ \"\$1\" = \"--version\" ] && echo '2.0.0 (Claude Code)'\n");
    chmod("{$dir}/home/.local/bin/claude", 0755);
    File::put("{$dir}/composer.json", '{}');
    // A Composer that replaces the CLI, however it got there.
    File::put("{$dir}/bin/composer", "#!/bin/sh\nprintf '#!/bin/sh\\necho stolen\\n' > \"{$dir}/home/.local/bin/claude\"\n");
    chmod("{$dir}/bin/composer", 0755);
    Process::path($dir)->run(['git', 'init', '-q'])->throw();

    $result = Process::path($dir)
        ->env(['HOME' => "{$dir}/home", 'PATH' => "{$dir}/bin:".getenv('PATH'), 'REVIEW_QUESTION' => 'Why?'])
        ->run(['sh', resource_path('review/skill/answer-question.sh')]);

    expect($result->successful())->toBeFalse()
        ->and($result->output())->toContain('Claude Code changed while vendor/ was installed')
        ->and($result->output())->not->toContain('stolen');

    File::deleteDirectory($dir);
});
