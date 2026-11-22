<?php

use App\Enums\FindingVerdictKind;
use App\Enums\ReviewRuleKind;
use App\Filament\TenantRole;
use App\Jobs\AnswerReviewQuestion;
use App\Jobs\RunContainerReview;
use App\Mcp\Servers\AgentServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\AddReviewRuleTool;
use App\Mcp\Tools\AskReviewerTool;
use App\Mcp\Tools\JudgeFindingTool;
use App\Mcp\Tools\OpenFindingsTool;
use App\Mcp\Tools\PullRequestFeedbackTool;
use App\Mcp\Tools\PullRequestsTool;
use App\Mcp\Tools\RepositoriesTool;
use App\Mcp\Tools\RequestReviewTool;
use App\Mcp\Tools\ReviewerAnswerTool;
use App\Mcp\Tools\ReviewInstructionsTool;
use App\Mcp\Tools\ReviewRulesTool;
use App\Models\Connection;
use App\Models\FindingVerdict;
use App\Models\OpenFinding;
use App\Models\OpenPullRequest;
use App\Models\PlatformSetting;
use App\Models\Repository;
use App\Models\ReviewQuestion;
use App\Models\ReviewRule;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

beforeEach(function () {
    config(['review.docker.enabled' => true]);
    Queue::fake();

    $this->repository = Repository::factory()->create(['slug' => 'shop', 'review_runner' => 'docker']);
    $this->tenant = $this->repository->tenant;
    $this->member = User::factory()->create(['name' => 'Sam Sample', 'source_accounts' => ['sam']]);
    $this->member->tenants()->attach($this->tenant);
    $this->admin = User::factory()->create();
    $this->admin->tenants()->attach($this->tenant, ['role' => TenantRole::ADMIN]);

    OpenPullRequest::query()->create(['repository_id' => $this->repository->id, 'pull_request_id' => 570])
        ->forceFill(['title' => 'Export to CSV', 'author' => 'Sam Sample', 'author_nickname' => 'sam', 'branch' => 'feature/export', 'commit' => str_repeat('a', 40)])
        ->save();

    $this->run = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FINISHED,
        'result' => 'FAILED', 'summary' => '1 high.', 'started_at' => now(), 'finished_at' => now(),
        'findings' => [
            ['id' => 'F1', 'severity' => 'LOW', 'status' => 'new', 'title' => 'No test for the export', 'path' => 'tests/ExportTest.php'],
            ['id' => 'F2', 'severity' => 'HIGH', 'status' => 'new', 'title' => 'Unescaped CSV cell', 'path' => 'app/Export.php', 'line' => 12],
            ['id' => 'F3', 'severity' => 'MEDIUM', 'status' => 'fixed', 'title' => 'Wrong delimiter'],
        ],
    ]);

    // Another team's repository of the same name, which none of these users is in.
    $this->elsewhere = Repository::factory()->create(['workspace' => 'other', 'slug' => 'shop']);

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => [
            ['id' => 11, 'content' => ['raw' => "**HIGH** Unescaped CSV cell\n\n[//]: # (ai-finding id=F2 status=new severity=HIGH)"], 'user' => ['display_name' => 'Review'], 'created_on' => now()->subHour()->toIso8601String(), 'inline' => ['path' => 'app/Export.php', 'to' => 12]],
            ['id' => 12, 'parent' => ['id' => 11], 'content' => ['raw' => 'The cell is escaped by the writer, see CsvWriter::cell().'], 'user' => ['display_name' => 'Sam Sample'], 'created_on' => now()->addMinute()->toIso8601String()],
            ['id' => 14, 'parent' => ['id' => 12], 'content' => ['raw' => "It is not: cell() only quotes.\n\n[//]: # (ai-review-reply)"], 'user' => ['display_name' => 'Review'], 'created_on' => now()->addMinutes(2)->toIso8601String()],
            ['id' => 13, 'content' => ['raw' => 'Looks good otherwise.'], 'user' => ['display_name' => 'Alex'], 'created_on' => now()->addMinute()->toIso8601String()],
        ]]),
        '*' => Http::response([]),
    ]);
});

it('lets only a token issued for the MCP servers in', function () {
    $this->postJson('/mcp/agent', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');

    Passport::actingAs($this->member, ['user:read']);
    $this->postJson('/mcp/user', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();

    Passport::actingAs($this->member, ['mcp:use']);
    $this->postJson('/mcp/agent', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->assertJsonFragment(['name' => 'pull-request-feedback']);
});

it('sends someone who is not signed in to the sign-in page before the consent screen, and asks there for the MCP scope a client left out', function () {
    $client = $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:4567/callback']])->assertCreated()->json('client_id');
    $query = http_build_query([
        'client_id' => $client, 'redirect_uri' => 'http://localhost:4567/callback', 'response_type' => 'code', 'state' => 'abc',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
    ]);

    $this->get("/oauth/authorize?{$query}")->assertRedirect(route('filament.app.auth.login'));

    $this->actingAs($this->member)->get("/oauth/authorize?{$query}")
        ->assertOk()
        ->assertSee('Connect Claude Code')
        ->assertSee($this->member->email)
        ->assertSee('Use MCP server');
});

it('keeps one OAuth key pair for the whole installation, unless the environment gives one', function () {
    config(['passport.private_key' => null, 'passport.public_key' => null]);
    $settings = app(PlatformSettings::class);

    $settings->applyOAuthKeys();
    $private = config('passport.private_key');

    // Another server of the installation, with nothing in its configuration yet, reads the same.
    config(['passport.private_key' => null]);
    (new PlatformSettings)->applyOAuthKeys();

    expect($private)->toStartWith('-----BEGIN PRIVATE KEY-----')
        ->and(config('passport.private_key'))->toBe($private)
        ->and(PlatformSetting::query()->count())->toBe(1);

    // Kept under another APP_KEY, it cannot be read, and is replaced.
    PlatformSetting::query()->whereKey(PlatformSettings::OAUTH_KEYS)->update(['value' => json_encode('encrypted-elsewhere')]);
    config(['passport.private_key' => null]);
    (new PlatformSettings)->applyOAuthKeys();

    expect(config('passport.private_key'))->toStartWith('-----BEGIN PRIVATE KEY-----')->not->toBe($private);

    config(['passport.private_key' => 'from-the-environment']);
    $settings->applyOAuthKeys();

    expect(config('passport.private_key'))->toBe('from-the-environment');
});

it('reads a pull request by its branch: findings that hold the merge first, with the verdicts and the replies in their threads', function () {
    FindingVerdict::query()->create(['review_run_id' => $this->run->id, 'user_id' => $this->admin->id, 'finding_id' => 'F2', 'verdict' => FindingVerdictKind::Wrong]);
    ReviewQuestion::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'question' => 'Why is F2 high?', 'status' => ReviewQuestion::STATUS_ANSWERED, 'answer' => 'Because a cell can start with =.', 'author' => 'Alex',
    ]);

    AgentServer::actingAs($this->member)
        ->tool(PullRequestFeedbackTool::class, ['repository' => 'git@bitbucket.org:acme/shop.git', 'branch' => 'feature/export'])
        ->assertOk()
        ->assertStructuredContentPaths([
            'pull_request.id' => 570,
            'pull_request.turn.action' => 'Address the AI review',
            'review.id' => $this->run->uuid,
            'review.current' => true,
            'findings.0.id' => 'F2',
            'findings.0.blocks' => true,
            'findings.0.verdicts' => ['wrong' => 1],
            'findings.0.thread.replies.0.text' => 'The cell is escaped by the writer, see CsvWriter::cell().',
            'findings.0.thread.replies.0.after_last_review' => true,
            // The reviewer's answer to that reply, a reply to the reply.
            'findings.0.thread.replies.1.by_review' => true,
            'findings.1.id' => 'F1',
            'findings.2.id' => 'F3',
            'findings.2.open' => false,
            'questions.0.answer' => 'Because a cell can start with =.',
        ])
        ->assertStructuredContentMissingPath('comments');

    AgentServer::actingAs($this->member)
        ->tool(PullRequestFeedbackTool::class, ['repository' => 'acme/shop', 'pull_request_id' => 570, 'comments' => true])
        // People's comments, replies to findings among them, and none of the review's own.
        ->assertStructuredContentCount(2, 'comments')
        ->assertStructuredContentPath('comments.1.text', 'Looks good otherwise.');
});

it('keeps the findings of the last review that reported when a newer attempt failed, and says it did', function () {
    $failed = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FAILED, 'started_at' => now()->addMinute(),
    ]);

    AgentServer::actingAs($this->member)
        ->tool(PullRequestFeedbackTool::class, ['repository' => 'acme/shop', 'pull_request_id' => 570])
        ->assertStructuredContentPaths([
            'review.id' => $this->run->uuid,
            'findings.0.id' => 'F2',
            'latest_attempt.id' => $failed->uuid,
            'latest_attempt.status' => ReviewRun::STATUS_FAILED,
        ])
        // Neither is listed again as an earlier review.
        ->assertStructuredContentPath('earlier_reviews', []);
});

it('finds no repository or review of a team the user is not in', function () {
    AgentServer::actingAs($this->member)
        ->tool(PullRequestFeedbackTool::class, ['repository' => 'other/shop', 'pull_request_id' => 1])
        ->assertHasErrors(['other/shop is not among the repositories you can reach']);

    $theirs = ReviewRun::query()->create([
        'tenant_id' => $this->elsewhere->tenant_id, 'repository_id' => $this->elsewhere->id, 'workspace' => 'other', 'slug' => 'shop', 'branch' => 'main',
        'commit' => str_repeat('b', 40), 'pull_request_id' => 3, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(), 'findings' => [['id' => 'F1']],
    ]);

    AgentServer::actingAs($this->member)
        ->tool(JudgeFindingTool::class, ['review_id' => $theirs->uuid, 'finding_id' => 'F1', 'verdict' => 'wrong'])
        ->assertHasErrors(["There is no review {$theirs->uuid} among yours."]);

    expect(FindingVerdict::query()->count())->toBe(0);
});

it('asks for the tenant when a user reaches two repositories of the same name', function () {
    $second = Repository::factory()->create(['slug' => 'shop']);
    $this->member->tenants()->attach($second->tenant);

    AgentServer::actingAs($this->member)
        ->tool(ReviewRulesTool::class, ['repository' => 'acme/shop'])
        ->assertHasErrors(['acme/shop is in more than one of your tenants']);

    AgentServer::actingAs($this->member)
        ->tool(ReviewRulesTool::class, ['repository' => 'acme/shop', 'tenant' => $second->tenant->slug])
        ->assertOk();
});

it('tells a GitHub repository from a Bitbucket one of the same name by the remote it is named by', function () {
    $github = Repository::factory()->for(Connection::factory()->gitHubSource()->state(['tenant_id' => $this->tenant->id]), 'integration')->create(['tenant_id' => $this->tenant->id, 'slug' => 'shop']);

    AgentServer::actingAs($this->member)
        ->tool(ReviewRulesTool::class, ['repository' => 'acme/shop'])
        ->assertHasErrors(["acme/shop is in more than one connection (#{$this->repository->connection_id} Bitbucket, #{$github->connection_id} GitHub)"]);

    ReviewRule::factory()->create(['tenant_id' => $this->tenant->id, 'repository_id' => $github->id, 'title' => 'Only on GitHub']);

    AgentServer::actingAs($this->member)
        ->tool(ReviewRulesTool::class, ['repository' => 'git@github.com:acme/shop.git'])
        ->assertStructuredContentPath('rules.0.title', 'Only on GitHub');

    AgentServer::actingAs($this->member)
        ->tool(ReviewRulesTool::class, ['repository' => 'https://bitbucket.org/acme/shop'])
        ->assertStructuredContentCount(0, 'rules');

    // Two connections on one host, such as a token and the GitHub App: the number tells them apart.
    $app = Repository::factory()->for(Connection::factory()->state(['tenant_id' => $this->tenant->id, 'driver' => 'github-app', 'name' => 'GitHub App']), 'integration')->create(['tenant_id' => $this->tenant->id, 'slug' => 'shop']);

    AgentServer::actingAs($this->member)
        ->tool(ReviewRulesTool::class, ['repository' => 'git@github.com:acme/shop.git'])
        ->assertHasErrors(['acme/shop is in more than one connection']);

    AgentServer::actingAs($this->member)
        ->tool(ReviewRulesTool::class, ['repository' => 'git@github.com:acme/shop.git', 'connection' => $app->connection_id])
        ->assertStructuredContentCount(0, 'rules');
});

it('records the user\'s verdict on a finding, the same again changing nothing, and none taking it back', function () {
    $judge = fn (string $verdict) => AgentServer::actingAs($this->member)
        ->tool(JudgeFindingTool::class, ['review_id' => $this->run->uuid, 'finding_id' => 'F2', 'verdict' => $verdict]);

    $judge('valid')->assertOk();
    $judge('valid')->assertOk();

    expect(FindingVerdict::query()->sole()->only(['user_id', 'finding_id', 'verdict']))
        ->toBe(['user_id' => $this->member->id, 'finding_id' => 'F2', 'verdict' => FindingVerdictKind::Valid]);

    $judge('none')->assertOk();

    expect(FindingVerdict::query()->count())->toBe(0);

    AgentServer::actingAs($this->member)
        ->tool(JudgeFindingTool::class, ['review_id' => $this->run->uuid, 'finding_id' => 'F9', 'verdict' => 'valid'])
        ->assertHasErrors(['This review has no finding F9.']);
});

it('lets an admin add a rule made from a finding, and refuses a member', function () {
    $rule = ['repository' => 'acme/shop', 'kind' => 'convention', 'title' => 'CSV cells go through CsvWriter::cell()', 'paths' => ['app/Export.php'], 'review_id' => $this->run->uuid, 'finding_id' => 'F2'];

    AgentServer::actingAs($this->member)->tool(AddReviewRuleTool::class, $rule)
        ->assertHasErrors(["Only an admin of {$this->tenant->name} may do this."]);

    expect(ReviewRule::query()->count())->toBe(0);

    AgentServer::actingAs($this->admin)->tool(AddReviewRuleTool::class, ['finding_id' => 'F9'] + $rule)
        ->assertHasErrors(["The review {$this->run->uuid} has no finding F9 in this tenant."]);

    AgentServer::actingAs($this->admin)->tool(AddReviewRuleTool::class, $rule)
        ->assertOk()
        ->assertStructuredContentPath('rule.label', 'C1');

    AgentServer::actingAs($this->admin)->tool(AddReviewRuleTool::class, ['repository' => 'acme/shop', 'all_repositories' => true, 'kind' => 'check', 'title' => 'Every export is queued', 'severity' => 'medium'])
        ->assertOk();

    expect(ReviewRule::query()->orderBy('id')->get()->map->only(['tenant_id', 'repository_id', 'kind', 'paths', 'severity', 'source'])->all())->toBe([
        ['tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'kind' => ReviewRuleKind::Convention, 'paths' => ['app/Export.php'], 'severity' => null, 'source' => "{$this->run->uuid}:F2"],
        ['tenant_id' => $this->tenant->id, 'repository_id' => null, 'kind' => ReviewRuleKind::Check, 'paths' => null, 'severity' => 'MEDIUM', 'source' => null],
    ]);

    // Both are read by the review, and so by the agent before it writes.
    AgentServer::actingAs($this->member)->tool(ReviewInstructionsTool::class, ['repository' => 'acme/shop', 'stacks' => ['laravel']])
        ->assertOk()
        ->assertSee(['Stacks: laravel.', '### R1. Every export is queued', '### C1. CSV cells go through CsvWriter::cell()']);
});

it('starts the review of an open pull request without a push, and not when it would be skipped, land elsewhere or the pull request is closed', function () {
    AgentServer::actingAs($this->member)
        ->tool(RequestReviewTool::class, ['repository' => 'acme/shop', 'pull_request_id' => 570])
        ->assertOk()
        ->assertStructuredContentPath('branch', 'feature/export');

    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->branch === 'feature/export');

    // Nothing said since the review of the head: it would be skipped as reviewed.
    $this->run->update(['finished_at' => now()->addHour()]);

    AgentServer::actingAs($this->member)
        ->tool(RequestReviewTool::class, ['repository' => 'acme/shop', 'pull_request_id' => 570])
        ->assertHasErrors(['The newest review already covers the head of #570, and nobody commented since']);

    // A run finds its pull request by the branch, which another one shares.
    OpenPullRequest::query()->create(['repository_id' => $this->repository->id, 'pull_request_id' => 580])->forceFill(['branch' => 'feature/export'])->save();

    AgentServer::actingAs($this->member)
        ->tool(RequestReviewTool::class, ['repository' => 'acme/shop', 'pull_request_id' => 570])
        ->assertHasErrors(['feature/export has more than one open pull request (#570 and #580)']);

    OpenPullRequest::query()->delete();

    AgentServer::actingAs($this->member)
        ->tool(RequestReviewTool::class, ['repository' => 'acme/shop', 'pull_request_id' => 570])
        ->assertHasErrors(['Pull request #570 is not open']);
});

it('asks the reviewer about a review under the user\'s name, and reads the answer of one of the user\'s questions only', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);

    $asked = AgentServer::actingAs($this->member)
        ->tool(AskReviewerTool::class, ['review_id' => $this->run->uuid, 'question' => 'Does escaping in CsvWriter settle F2?'])
        ->assertOk()
        ->assertStructuredContentPaths(['asked_by' => 'Sam Sample', 'status' => ReviewQuestion::STATUS_ASKED, 'where' => 'review page']);

    $question = ReviewQuestion::query()->sole();
    Queue::assertPushed(AnswerReviewQuestion::class);
    expect($question->only(['user_id', 'review_run_id', 'comment_id']))->toBe(['user_id' => $this->member->id, 'review_run_id' => $this->run->id, 'comment_id' => null]);

    $question->update(['status' => ReviewQuestion::STATUS_ANSWERED, 'answer' => 'Yes, it prefixes a quote.']);

    AgentServer::actingAs($this->member)->tool(ReviewerAnswerTool::class, ['question_id' => $question->id])
        ->assertStructuredContentPath('answer', 'Yes, it prefixes a quote.');

    $outsider = User::factory()->create();
    $outsider->tenants()->attach($this->elsewhere->tenant);

    AgentServer::actingAs($outsider)->tool(ReviewerAnswerTool::class, ['question_id' => $question->id])
        ->assertHasErrors(["There is no question {$question->id} among yours."]);
});

it('lists the open findings backlog of the user\'s repositories, narrowed to a path or a tenant', function () {
    $finding = fn (Repository $repository, string $path, string $status = OpenFinding::PRESENT) => OpenFinding::query()->create([
        'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'pull_request_id' => 1, 'finding_id' => $path, 'title' => "In {$path}", 'path' => $path, 'status' => $status,
    ]);
    $finding($this->repository, 'app/Export.php');
    $finding($this->repository, 'app/Import.php', OpenFinding::RESOLVED);
    $finding($this->repository, 'routes/web.php');
    $finding($this->repository, 'app/my-module/Export.php');
    $finding($this->elsewhere, 'app/Export.php');

    AgentServer::actingAs($this->member)->tool(OpenFindingsTool::class, ['repository' => 'acme/shop', 'path' => 'app/E'])
        ->assertOk()
        ->assertStructuredContentCount(1, 'findings')
        ->assertStructuredContentPath('findings.0.title', 'In app/Export.php');

    // An underscore is an underscore, not any character.
    AgentServer::actingAs($this->member)->tool(OpenFindingsTool::class, ['repository' => 'acme/shop', 'path' => 'app/my_module/'])
        ->assertStructuredContentCount(0, 'findings');

    UserServer::actingAs($this->member)->tool(OpenFindingsTool::class)
        ->assertStructuredContentCount(3, 'findings');

    // In two tenants, a tenant narrows the list to its own.
    $this->member->tenants()->attach($this->elsewhere->tenant);

    UserServer::actingAs($this->member)->tool(OpenFindingsTool::class, ['tenant' => $this->elsewhere->tenant->slug])
        ->assertStructuredContentCount(1, 'findings')
        ->assertStructuredContentPath('findings.0.repository', 'other/shop');
});

it('lists the pull requests waiting for the user', function () {
    OpenPullRequest::query()->create(['repository_id' => $this->repository->id, 'pull_request_id' => 571])
        ->forceFill(['title' => 'Someone else\'s', 'author' => 'Alex', 'author_nickname' => 'alex', 'branch' => 'feature/other'])
        ->save();

    // An older attempt, which the newest review stands before.
    ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('c', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FAILED, 'started_at' => now()->subDay(),
    ]);

    UserServer::actingAs($this->member)->tool(PullRequestsTool::class, ['only' => 'my-turn'])
        ->assertOk()
        ->assertStructuredContentCount(1, 'pull_requests')
        ->assertStructuredContentPaths(['pull_requests.0.id' => 570, 'pull_requests.0.turn.yours' => true, 'pull_requests.0.review.id' => $this->run->uuid]);

    UserServer::actingAs($this->member)->tool(PullRequestsTool::class)
        ->assertStructuredContentCount(2, 'pull_requests');

    UserServer::actingAs($this->member)->tool(PullRequestsTool::class, ['limit' => 1])
        ->assertStructuredContentCount(1, 'pull_requests');
});

it('lists the user\'s tenants and repositories, each with its open pull requests and last review', function () {
    UserServer::actingAs($this->member)->tool(RepositoriesTool::class)
        ->assertOk()
        ->assertStructuredContentCount(1, 'tenants')
        ->assertStructuredContentCount(1, 'repositories')
        ->assertStructuredContentPaths([
            'tenants.0.you_are_admin' => false,
            'repositories.0.repository' => 'acme/shop',
            'repositories.0.connection' => $this->repository->connection_id,
            'repositories.0.open_pull_requests' => 1,
            'repositories.0.last_review.id' => $this->run->uuid,
        ]);
});
