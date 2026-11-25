<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\CurrentTenant;
use App\Filament\TenantRole;
use App\Models\User;
use App\Services\RelevantRepositories;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A list's tab of what belongs to the repositories the signed-in user works on:
 * see RelevantRepositories. A member, who has no use for the rest of the team's,
 * opens the list on it once they work on one.
 */
trait HasMyRepositoriesTab
{
    public const MY_REPOSITORIES = 'my-repositories';

    /**
     * The ids of the repositories the signed-in user works on, read once a request.
     *
     * @return array<int, int>
     */
    protected function myRepositoryIds(): array
    {
        return once(function (): array {
            $user = auth()->user();

            return $user instanceof User ? app(RelevantRepositories::class)->for($user, CurrentTenant::get()) : [];
        });
    }

    /**
     * Limits a query to the user's repositories, by the column that names the repository.
     *
     * @return Closure(Builder<Model>): Builder<Model>
     */
    protected function inMyRepositories(string $column = 'repository_id'): Closure
    {
        return fn (Builder $query): Builder => $query->whereIn($query->qualifyColumn($column), $this->myRepositoryIds());
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return ! TenantRole::isAdmin() && $this->myRepositoryIds() !== [] && array_key_exists(self::MY_REPOSITORIES, $this->getCachedTabs())
            ? self::MY_REPOSITORIES
            : parent::getDefaultActiveTab();
    }
}
