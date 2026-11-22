<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Exceptions\RepositoryNotFound;
use App\Filament\TenantRole;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a user may reach outside the panel, where no tenant is open: the
 * repositories and reviews of the tenants they are in, all of them for an
 * administrator of the installation. For whatever reads as the user, such as
 * the MCP servers or an API.
 */
class RepositoryAccess
{
    /**
     * The tenants the user is in, by name.
     *
     * @return Collection<int, Tenant>
     */
    public function tenants(User $user): Collection
    {
        return $user->is_admin ? Tenant::query()->orderBy('name')->get() : $user->tenants()->orderBy('name')->get();
    }

    /**
     * @return Builder<Repository>
     */
    public function repositories(User $user): Builder
    {
        return Repository::query()->when(! $user->is_admin, fn (Builder $query) => $query->whereIn('tenant_id', $user->tenants()->select('tenants.id')));
    }

    /**
     * The repository a name points at: workspace/slug, a git remote, or the address
     * of the repository or of one of its pull requests on the host. A name two of
     * the user's tenants both have needs the tenant, by its slug.
     *
     * @throws RepositoryNotFound
     */
    public function find(User $user, string $name, ?string $tenant = null): Repository
    {
        $fullName = self::fullName($name);

        if ($fullName === null) {
            throw new RepositoryNotFound("\"{$name}\" names no repository: give it as workspace/slug, such as fruitcake/review-central, or as its git remote.");
        }

        [$workspace, $slug] = $fullName;

        $found = $this->repositories($user)
            ->with('tenant')
            ->whereRaw('lower(workspace) = ?', [mb_strtolower($workspace)])
            ->whereRaw('lower(slug) = ?', [mb_strtolower($slug)])
            ->when(filled($tenant), fn (Builder $query) => $query->whereHas('tenant', fn (Builder $scope) => $scope->where('slug', $tenant)))
            ->get();

        if ($found->isEmpty()) {
            throw new RepositoryNotFound("{$workspace}/{$slug} is not among the repositories you can reach".(filled($tenant) ? " in the tenant {$tenant}" : '').'. It may not be added to Review Central yet.');
        }

        if ($found->count() > 1) {
            throw new RepositoryNotFound("{$workspace}/{$slug} is in more than one of your tenants (".$found->map(fn (Repository $repository): string => $repository->tenant->slug)->implode(', ').'): name the tenant too.');
        }

        return $found->first();
    }

    /**
     * A review by its id, when it is of one of the user's repositories.
     *
     * @throws RepositoryNotFound
     */
    public function run(User $user, string $uuid): ReviewRun
    {
        $run = ReviewRun::query()
            ->where('uuid', $uuid)
            ->whereIn('repository_id', $this->repositories($user)->withTrashed()->select('id'))
            ->first();

        return $run ?? throw new RepositoryNotFound("There is no review {$uuid} among yours.");
    }

    /**
     * Whether the user may manage the tenant: its rules, settings and members.
     */
    public function manages(User $user, Tenant $tenant): bool
    {
        return TenantRole::isAdmin($user, $tenant);
    }

    /**
     * @throws ActionRefused when the user may not manage the tenant
     */
    public function mustManage(User $user, Tenant $tenant): void
    {
        if (! $this->manages($user, $tenant)) {
            throw new ActionRefused("Only an admin of {$tenant->name} may do this. Ask one of them, or do it in Review Central yourself once you are one.");
        }
    }

    /**
     * Workspace and slug from what someone may paste for a repository.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function fullName(string $name): ?array
    {
        $name = trim($name);

        // git@github.com:workspace/slug.git, ssh://git@host/workspace/slug.git, an address.
        $path = preg_match('#^[\w.-]+@[\w.-]+:(.+)$#', $name, $ssh) === 1 ? $ssh[1] : (string) (parse_url($name, PHP_URL_PATH) ?? $name);

        if (str_contains($name, '://') && parse_url($name, PHP_URL_HOST) === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        // The address of a pull request, or of a page within the repository.
        foreach (['pull', 'pulls', 'pull-requests', 'src', 'tree', 'blob', 'commits', '-'] as $marker) {
            $at = array_search($marker, $segments, true);

            if (is_int($at) && $at >= 2) {
                $segments = array_slice($segments, 0, $at);
            }
        }

        if (count($segments) !== 2) {
            return null;
        }

        $slug = (string) preg_replace('/\.git$/', '', $segments[1]);

        return $slug !== '' ? [$segments[0], $slug] : null;
    }
}
