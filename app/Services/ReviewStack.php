<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stacks the AI review reads a guide for (stack-<name>.md), sent to the run
 * with the review context.
 *
 * The precheck runs without a clone, so the server decides: the run's
 * REVIEW_STACK when it sets one, then the repository's own stack. A repository's
 * stack is detected once, when it is added, so `auto` adds none here.
 */
class ReviewStack
{
    /** The stacks a guide ships for, in the order the review reads them, as detect-stack.sh prints them. */
    public const STACKS = ['laravel', 'magento', 'hyva', 'drupal', 'react-native', 'nuxt'];

    /** What a stack may be called: it names a file, `stack-<name>.md`. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    /**
     * The stacks a tenant's repositories can be on: the ones a guide ships for,
     * and any it, or this installation, wrote a guide for itself.
     *
     * @return list<string>
     */
    public static function known(?Tenant $tenant): array
    {
        $own = ReviewInstruction::query()
            ->whereNotNull('stack')
            ->where(fn (Builder $query) => $query->whereNull('tenant_id')->when($tenant !== null, fn (Builder $query) => $query->orWhere('tenant_id', $tenant->getKey())))
            ->orderBy('stack')
            ->pluck('stack')
            ->filter(fn (mixed $stack): bool => is_string($stack) && preg_match(self::NAME_PATTERN, $stack) === 1)
            ->all();

        return array_values(array_unique([...self::STACKS, ...$own]));
    }

    /**
     * REVIEW_STACK is read as the skill's detect-stack.sh reads it: a comma- or
     * space-separated list, where `auto` and `none` add
     * nothing, and a name the review has no guide for is dropped. Hyvä implies
     * Magento, since a Hyvä theme is a Magento theme.
     *
     * @return list<string>
     */
    public static function resolve(Repository $repository, ?string $variable = null): array
    {
        $asked = self::words($variable) ?: ($repository->review_stack ?: ['auto']);
        $found = [];

        foreach ($asked as $word) {
            match ($word) {
                'none' => null,
                'auto' => null,
                default => $found[] = $word,
            };
        }

        if (in_array('hyva', $found, true)) {
            $found[] = 'magento';
        }

        // Only a name no guide ships for is looked up: it is a stack when the
        // tenant, or this installation, wrote a guide for it.
        $known = array_diff($found, self::STACKS) === [] ? self::STACKS : self::known($repository->tenant);

        return array_values(array_intersect($known, $found));
    }

    /**
     * The stacks a repository is set to, or else those its last review found by
     * itself: what to read the guides for outside a review.
     *
     * @return list<string>
     */
    public static function forRepository(Repository $repository): array
    {
        $stacks = self::resolve($repository);

        if ($stacks !== []) {
            return $stacks;
        }

        $last = $repository->reviewRuns()->whereNotNull('context')->latest('started_at')->first(['context']);

        return array_values(array_filter((array) ($last?->context['stack'] ?? []), is_string(...)));
    }

    /**
     * The stacks a repository's composer.json names, by the rules of the skill's
     * detect-stack.sh: a package anywhere in the file, or the type of the package
     * itself. What only the tree would show, such as a theme's parent, is left to
     * that script.
     *
     * @return list<string>
     */
    public static function fromComposer(?string $composer): array
    {
        if ($composer === null) {
            return [];
        }

        $package = fn (string $name): bool => preg_match('~"'.$name.'"\s*:~', $composer) === 1;
        $type = fn (string $name): bool => preg_match('~"type"\s*:\s*"'.$name.'"~', $composer) === 1;

        $found = array_keys(array_filter([
            'laravel' => $package('laravel/framework'),
            'magento' => $package('magento/(product-community-edition|product-enterprise-edition|magento2-base|framework)')
                || $type('magento2-(module|theme|library|language|component)'),
            'hyva' => $package('hyva-themes/[a-z0-9_.-]+'),
            'drupal' => $package('drupal/(core|core-recommended|core-dev|recommended-project)')
                || $type('drupal-(module|theme|profile|recipe|custom-module|custom-theme)'),
        ]));

        if (in_array('hyva', $found, true)) {
            $found[] = 'magento';
        }

        return array_values(array_intersect(self::STACKS, $found));
    }

    /**
     * The stacks a repository's package.json names, by the rules of the skill's
     * detect-stack.sh: React Native, bare or through Expo, and Nuxt, each as a
     * dependency of any kind.
     *
     * @return list<string>
     */
    public static function fromPackage(?string $package): array
    {
        if ($package === null) {
            return [];
        }

        return array_keys(array_filter([
            'react-native' => preg_match('~"(react-native|expo)"\s*:~', $package) === 1,
            'nuxt' => preg_match('~"nuxt"\s*:~', $package) === 1,
        ]));
    }

    /**
     * The stacks a repository's own files name: its composer.json, then its package.json.
     *
     * @param  callable(string): ?string  $read  the file at that path in the repository, or null
     * @return list<string>
     */
    public static function fromFiles(callable $read): array
    {
        $found = [...self::fromComposer($read('composer.json')), ...self::fromPackage($read('package.json'))];

        return array_values(array_intersect(self::STACKS, $found));
    }

    /**
     * @return list<string>
     */
    protected static function words(?string $value): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', strtolower((string) $value)) ?: []));
    }
}
