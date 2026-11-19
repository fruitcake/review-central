<?php

declare(strict_types=1);

namespace App\Runners\Diff;

use Illuminate\Support\Facades\File;

/**
 * The review's report, as a schema an AI provider's structured output takes.
 *
 * One schema for every reviewer: ai-review.schema.json, which the reviewers in a
 * container are given too. A provider's API takes it in a stricter form than it
 * is written in.
 */
class ReportSchema
{
    /** What a provider's structured output does not take in a schema. */
    protected const UNSUPPORTED = ['pattern', 'minimum', 'maximum', 'minLength', 'maxLength', 'title', 'description', '$schema'];

    /**
     * Every object closed, and nothing a provider refuses. A property the schema
     * leaves optional stays optional.
     *
     * @return array<string, mixed>
     */
    public static function closed(): array
    {
        return self::close(self::read(), strict: false);
    }

    /**
     * Every object closed and every property required, as OpenAI's strict
     * structured outputs want it: a property the schema leaves optional becomes
     * required and nullable, and the reviewer answers null where it would have
     * left the field out. {@see self::withoutNulls()} undoes that in the answer.
     *
     * @return array<string, mixed>
     */
    public static function strict(): array
    {
        return self::close(self::read(), strict: true);
    }

    /**
     * The report as though every null field had been left out, which is what every
     * reader of a report expects.
     *
     * @param  array<mixed>  $report
     * @return array<mixed>
     */
    public static function withoutNulls(array $report): array
    {
        $clean = [];

        foreach ($report as $key => $value) {
            if ($value === null) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::withoutNulls($value) : $value;
        }

        return array_is_list($report) ? array_values($clean) : $clean;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function read(): array
    {
        return File::json(resource_path('review/skill/ai-review.schema.json'));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function close(array $schema, bool $strict): array
    {
        $schema = array_diff_key($schema, array_flip(self::UNSUPPORTED));

        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            $schema['items'] = self::close($schema['items'], $strict);
        }

        if (($schema['type'] ?? null) !== 'object') {
            return $schema;
        }

        $required = $schema['required'] ?? [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $property = self::close($property, $strict);
            $schema['properties'][$name] = $strict && ! in_array($name, $required, true) ? self::nullable($property) : $property;
        }

        if ($strict) {
            $schema['required'] = array_keys($schema['properties'] ?? []);
        }

        $schema['additionalProperties'] = false;

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $property
     * @return array<string, mixed>
     */
    protected static function nullable(array $property): array
    {
        if (array_key_exists('const', $property)) {
            return ['type' => [gettype($property['const']) === 'boolean' ? 'boolean' : 'string', 'null'], 'enum' => [$property['const'], null]];
        }

        if (isset($property['enum'])) {
            $property['enum'][] = null;
        }

        $property['type'] = array_values(array_unique([...(array) ($property['type'] ?? 'string'), 'null']));

        return $property;
    }
}
