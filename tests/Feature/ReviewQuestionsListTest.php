<?php

use App\Filament\App\Resources\ReviewQuestions\Pages\ListReviewQuestions;
use App\Filament\App\Resources\ReviewQuestions\ReviewQuestionResource;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Models\Repository;
use App\Models\ReviewQuestion;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop', 'workspace' => 'acme']);
    $this->tenant = $this->repository->tenant;
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    $this->question = fn (string $status, array $more = []): ReviewQuestion => ReviewQuestion::query()->create($more + [
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570, 'branch' => 'feature/export',
        'comment_id' => random_int(1, 99999), 'question' => "A question that is {$status}", 'status' => $status,
    ]);
});

it('lists the questions asked of the reviewer, what is being answered first, with the answer and where to find it', function () {
    $pending = ($this->question)(ReviewQuestion::STATUS_ASKED);
    $answered = ($this->question)(ReviewQuestion::STATUS_ANSWERED, ['answer' => 'Because it escapes nothing.', 'about' => 'The export escapes nothing.']);
    $failed = ($this->question)(ReviewQuestion::STATUS_FAILED, ['comment_id' => null]);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
    ]);
    // Another tenant's is not here.
    $elsewhere = ReviewQuestion::query()->create([
        'repository_id' => $this->repository->id, 'pull_request_id' => 1,
        'branch' => 'main', 'comment_id' => 5, 'question' => 'Elsewhere', 'status' => ReviewQuestion::STATUS_ASKED,
    ]);
    // Filament gives a record made in the panel the open tenant; this one is another's.
    ReviewQuestion::query()->whereKey($elsewhere->id)->update(['tenant_id' => Tenant::factory()->create()->id]);

    expect(ReviewQuestionResource::getNavigationBadge())->toBe('1');

    livewire(ListReviewQuestions::class)
        ->assertCanSeeTableRecords([$pending, $answered, $failed])
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->assertSee('Being answered')
        ->assertSee('under a finding')
        ->assertSee('asked here')
        ->assertTableActionVisible('answer', $answered)
        ->assertTableActionHidden('answer', $pending)
        ->assertTableActionHasUrl('pullRequest', 'https://bitbucket.org/acme/shop/pull-requests/570', $pending)
        ->assertTableActionHasUrl('review', ReviewRunResource::getUrl('view', ['record' => $run]), $answered);

    livewire(ListReviewQuestions::class, ['activeTab' => 'pending'])->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$answered, $failed]);
    livewire(ListReviewQuestions::class, ['activeTab' => 'unanswered'])->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$pending]);
});
