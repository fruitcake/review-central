<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A unified diff of two texts by line, for a person to read what an update
 * changes. Small texts only: the instructions are a few hundred lines.
 */
class LineDiff
{
    /**
     * @return list<array{type: string, text: string}> each line kept (' '), removed ('-') or added ('+'), with '@@' between the hunks
     */
    public static function lines(string $old, string $new, int $context = 3): array
    {
        $a = $old === '' ? [] : explode("\n", rtrim($old, "\n"));
        $b = $new === '' ? [] : explode("\n", rtrim($new, "\n"));
        $n = count($a);
        $m = count($b);

        // The longest common subsequence, from the end, so the walk below goes forward.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $all = [];

        for ($i = 0, $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $all[] = ['type' => ' ', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($i < $n && ($j === $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                // What goes before what comes in its place, as a diff reads.
                $all[] = ['type' => '-', 'text' => $a[$i]];
                $i++;
            } else {
                $all[] = ['type' => '+', 'text' => $b[$j]];
                $j++;
            }
        }

        // Only the changes, with a few lines around each.
        $keep = [];

        foreach ($all as $index => $line) {
            if ($line['type'] !== ' ') {
                for ($k = max(0, $index - $context); $k <= min(count($all) - 1, $index + $context); $k++) {
                    $keep[$k] = true;
                }
            }
        }

        $out = [];
        $last = null;

        foreach (array_keys($keep) as $index) {
            if ($last !== null && $index > $last + 1) {
                $out[] = ['type' => '@@', 'text' => ''];
            }

            $out[] = $all[$index];
            $last = $index;
        }

        return $out;
    }
}
