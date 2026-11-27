<?php

/**
 * Whether a path matches a rule's glob as the agent tools read one: ** across
 * folders, * and ? within one, {a,b} for either, and a folder naming everything
 * under it. Shared by rule-files.php and team-rules.php, so a rule's paths mean
 * the same wherever the rule is kept.
 */
function review_rule_glob_matches(string $glob, string $path): bool
{
    // Only a ./ or / prefix: the dot of .github/ belongs to the folder.
    $glob = (string) preg_replace('#^(?:\./|/)+#', '', $glob);
    $pattern = '';

    for ($i = 0, $length = strlen($glob); $i < $length; $i++) {
        $char = $glob[$i];

        if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
            $i++;

            // "**/" matches no folder as well as any number of them.
            if (($glob[$i + 1] ?? '') === '/') {
                $i++;
                $pattern .= '(?:.*/)?';
            } else {
                $pattern .= '.*';
            }
        } elseif ($char === '*') {
            $pattern .= '[^/]*';
        } elseif ($char === '?') {
            $pattern .= '[^/]';
        } elseif ($char === '{' && ($close = strpos($glob, '}', $i)) !== false) {
            $pattern .= '(?:'.implode('|', array_map(fn (string $part): string => preg_quote($part, '#'), explode(',', substr($glob, $i + 1, $close - $i - 1)))).')';
            $i = $close;
        } else {
            $pattern .= preg_quote($char, '#');
        }
    }

    return preg_match('#^'.$pattern.'(?:/.*)?$#', $path) === 1;
}
