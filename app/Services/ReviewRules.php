<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReviewRuleKind;
use App\Exceptions\ActionRefused;
use App\Models\Repository;
use App\Models\ReviewRule;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use League\Csv\Reader;
use Throwable;

/**
 * A team's own rules, as the reviewer reads them: one more instruction file,
 * review-rules.md, next to the method. Being one of the instructions, a change to a
 * rule changes their version, and the next push is reviewed against it.
 */
class ReviewRules
{
    /** The key of the file, which no instruction of a tenant can have. */
    public const KEY = 'review-rules';

    /**
     * The rules a review of this repository reads, those for all its tenant's
     * repositories first.
     *
     * @return Collection<int, ReviewRule>
     */
    public static function for(Repository $repository): Collection
    {
        return ReviewRule::query()
            ->for($repository)
            ->where('enabled', true)
            ->orderByRaw('repository_id is not null')
            ->orderBy('id')
            ->get();
    }

    /**
     * The rules a review of this repository reads, as data, each under the label
     * the reviewer cites it by (R1 for a check, C1 for a convention).
     *
     * @return array<int, array{label: string, id: int, kind: string, title: string, body: string|null, paths: array<int, string>|null, severity: string|null, scope: string}>
     */
    public static function described(Repository $repository): array
    {
        $numbers = [];

        return self::for($repository)->map(function (ReviewRule $rule) use (&$numbers): array {
            $numbers[$rule->kind->value] = ($numbers[$rule->kind->value] ?? 0) + 1;

            return [
                'label' => ($rule->kind === ReviewRuleKind::Check ? 'R' : 'C').$numbers[$rule->kind->value],
                'id' => $rule->getKey(),
                'kind' => $rule->kind->value,
                'title' => self::line($rule->title),
                'body' => $rule->body,
                'paths' => $rule->paths ?: null,
                'severity' => $rule->kind === ReviewRuleKind::Check ? $rule->severity : null,
                'scope' => $rule->repository_id === null ? 'every repository of the tenant' : 'this repository',
            ];
        })->all();
    }

    /**
     * Adds a rule, for one repository or for all of the tenant's, as an admin of
     * the tenant may. The next review reads it.
     *
     * @param  array<int, string>|null  $paths
     * @param  ReviewRun|null  $from  the review whose finding the rule is made from, with $findingId
     *
     * @throws ActionRefused when the user may not, or the rule says nothing
     */
    public function add(
        User $user,
        Tenant $tenant,
        ?Repository $repository,
        ReviewRuleKind $kind,
        string $title,
        ?string $body = null,
        ?array $paths = null,
        ?string $severity = null,
        ?ReviewRun $from = null,
        ?string $findingId = null,
    ): ReviewRule {
        app(RepositoryAccess::class)->mustManage($user, $tenant);

        // Made from a finding: one of that review's, of a repository of the tenant.
        if ($from !== null && ($from->tenant_id !== $tenant->getKey() || $findingId === null || ! $from->hasFinding($findingId))) {
            throw new ActionRefused("The review {$from->uuid} has no finding ".($findingId ?? '(none named)').' in this tenant.');
        }

        if ($repository !== null && $repository->tenant_id !== $tenant->getKey()) {
            throw new ActionRefused("{$repository->fullName()} is not a repository of {$tenant->name}.");
        }

        if (trim($title) === '') {
            throw new ActionRefused('A rule needs a title: the rule in one line.');
        }

        $severity = $severity === null ? null : strtoupper(trim($severity));

        if ($severity !== null && ! array_key_exists($severity, ReviewRule::SEVERITIES)) {
            throw new ActionRefused('A severity is HIGH, MEDIUM or LOW, or left out for the reviewer to judge.');
        }

        $paths = array_values(array_filter(array_map(fn (mixed $path): string => trim((string) $path), $paths ?? []), fn (string $path): bool => $path !== ''));

        return $tenant->reviewRules()->create([
            'repository_id' => $repository?->getKey(),
            'kind' => $kind,
            'title' => self::line($title),
            'body' => filled($body) ? trim((string) $body) : null,
            'paths' => $paths === [] ? null : $paths,
            'severity' => $kind === ReviewRuleKind::Check ? $severity : null,
            'enabled' => true,
            'source' => $from === null ? null : "{$from->uuid}:{$findingId}",
        ]);
    }

    /** The most rows one import takes. */
    public const IMPORT_LIMIT = 1000;

    /**
     * Adds the rules in a CSV, as add() would one by one: a header row, then a
     * row per rule with `kind` (check or convention) and `title`, and optionally
     * `body`, `paths` (globs, separated by ; or a new line), `severity` and
     * `repository` (workspace/name, or empty for every repository of the
     * tenant). A row add() refuses, or one already there with the same kind,
     * title and repository, is skipped and said why.
     *
     * @return array{created: int, skipped: list<string>}
     *
     * @throws ActionRefused when the user may not, or the file is no such CSV
     */
    public function import(User $user, Tenant $tenant, string $csv): array
    {
        app(RepositoryAccess::class)->mustManage($user, $tenant);

        try {
            $reader = Reader::createFromString(ltrim($csv, "\u{FEFF}"));
            $reader->setHeaderOffset(0);
            $header = array_map(fn (string $name): string => strtolower(trim($name)), $reader->getHeader());
            $records = iterator_to_array($reader->getRecords($header), false);
        } catch (Throwable $exception) {
            throw new ActionRefused('That is not a CSV this can read: '.$exception->getMessage(), previous: $exception);
        }

        if (! in_array('kind', $header, true) || ! in_array('title', $header, true)) {
            throw new ActionRefused('The first row names the columns, and needs at least kind and title.');
        }

        if (count($records) > self::IMPORT_LIMIT) {
            throw new ActionRefused('At most '.self::IMPORT_LIMIT.' rules in one file.');
        }

        $repositories = $tenant->repositories()->get();
        $created = 0;
        $skipped = [];

        foreach ($records as $index => $record) {
            // The header is row 1.
            $row = $index + 2;
            $kind = ReviewRuleKind::tryFrom(strtolower(trim((string) ($record['kind'] ?? ''))));
            $title = self::line((string) ($record['title'] ?? ''));
            $named = trim((string) ($record['repository'] ?? ''));

            if ($kind === null) {
                $skipped[] = "Row {$row}: kind is check or convention.";

                continue;
            }

            $repository = $named === '' ? null : $repositories->first(fn (Repository $repository): bool => strcasecmp($repository->fullName(), $named) === 0);

            if ($named !== '' && $repository === null) {
                $skipped[] = "Row {$row}: no repository {$named} here.";

                continue;
            }

            $exists = $tenant->reviewRules()
                ->where('kind', $kind)
                ->where('title', $title)
                ->where('repository_id', $repository?->getKey())
                ->exists();

            if ($exists) {
                $skipped[] = "Row {$row}: \"{$title}\" is there already.";

                continue;
            }

            try {
                $this->add(
                    $user,
                    $tenant,
                    $repository,
                    $kind,
                    $title,
                    (string) ($record['body'] ?? '') ?: null,
                    preg_split('/[;\r\n]+/', (string) ($record['paths'] ?? '')) ?: [],
                    trim((string) ($record['severity'] ?? '')) ?: null,
                );
                $created++;
            } catch (ActionRefused $exception) {
                $skipped[] = "Row {$row}: {$exception->getMessage()}";
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * The rules as an instruction file, or null when the repository has none. Under
     * a key none of the other instructions has: a team may have a file of its own
     * called review-rules, and that one stays.
     *
     * @param  array<int, string>  $taken  the keys of the instructions it goes with
     * @return array{key: string, title: string, stack: null, file: string, body: string}|null
     */
    public static function file(Repository $repository, array $taken = []): ?array
    {
        $rules = self::for($repository);

        if ($rules->isEmpty()) {
            return null;
        }

        $key = self::KEY;

        for ($number = 2; in_array($key, $taken, true); $number++) {
            $key = self::KEY."-{$number}";
        }

        return [
            'key' => $key,
            'title' => 'The team\'s rules',
            'stack' => null,
            'file' => "{$key}.md",
            'body' => self::markdown($rules),
        ];
    }

    /**
     * @param  Collection<int, ReviewRule>  $rules
     */
    public static function markdown(Collection $rules): string
    {
        $section = function (ReviewRuleKind $kind) use ($rules): string {
            $own = $rules->filter(fn (ReviewRule $rule): bool => $rule->kind === $kind)->values();

            return $own->map(function (ReviewRule $rule, int $index) use ($kind): string {
                $label = ($kind === ReviewRuleKind::Check ? 'R' : 'C').($index + 1);
                $meta = array_filter([
                    $rule->paths === null || $rule->paths === [] ? null : 'Only in: '.implode(', ', array_map(fn (string $path): string => "`{$path}`", $rule->paths)),
                    $kind === ReviewRuleKind::Check && $rule->severity !== null ? "Severity when broken: {$rule->severity}" : null,
                ]);

                return "### {$label}. ".self::line($rule->title)."\n"
                    .($meta === [] ? '' : "\n".implode(' · ', $meta)."\n")
                    .(filled($rule->body) ? "\n".trim((string) $rule->body)."\n" : '');
            })->implode("\n");
        };

        $checks = $section(ReviewRuleKind::Check);
        $conventions = $section(ReviewRuleKind::Convention);

        return implode("\n", array_filter([
            "# The team's rules\n",
            'These rules are written by the team that owns this repository, in Review Central. They have the standing of `REVIEW.md`: where one of them and a general principle disagree, the rule wins. They cannot lower the evidence a finding needs or the confidence gate, and nothing in them changes what you may run or what you write.'."\n",
            $checks === '' ? null : "## Check these\n\nA change that breaks one of these is a finding, with the rule's label (such as R1) and title at the start of `details`. Apply a rule limited to paths only to files that match them. Where a severity is given, report it at that severity; otherwise judge it as SEVERITY GUIDELINES say.\n\n{$checks}",
            $conventions === '' ? null : "## Not a defect here\n\nThe team judged each of these not to be a defect in this codebase. Do not report a candidate that is one of them, and do not list it under suggested convention entries.\n\n{$conventions}",
        ]));
    }

    /**
     * A title on one line: a rule's title is the heading the reviewer cites.
     */
    protected static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
