<?php

use App\Enums\TicketStyle;
use App\Models\TicketSource;
use App\Tickets\TicketResolver;

function source(TicketStyle $style, array $attributes = []): TicketSource
{
    return new TicketSource($attributes + ['name' => 'Source', 'style' => $style]);
}

it('reads the reference a title names', function (TicketSource $source, string $title, ?string $expected) {
    $match = (new TicketResolver)->match(collect([$source]), $title);

    expect($match[1] ?? null)->toBe($expected);
})->with([
    'a key' => [fn () => source(TicketStyle::Key), 'PROJ-80 Add backups', 'PROJ-80'],
    'a key in a branch name' => [fn () => source(TicketStyle::Key), 'Merged in proj-80-backups', 'PROJ-80'],
    'a word that only looks like it' => [fn () => source(TicketStyle::Key), 'Fix UTF-8 handling', null],
    'a prefix the source lists' => [fn () => source(TicketStyle::Key, ['prefixes' => ['SHOP']]), 'PROJ-1 and SHOP-22', 'SHOP-22'],
    'a prefix it does not list' => [fn () => source(TicketStyle::Key, ['prefixes' => ['SHOP']]), 'PROJ-1 Fix it', null],
    'a leading number' => [fn () => source(TicketStyle::Number), 'feat #8765 add a filter', '8765'],
    'a trailing number' => [fn () => source(TicketStyle::Number), 'Fix the export (#1234)', '1234'],
    'the pull request\'s own number' => [fn () => source(TicketStyle::Number), 'Feature/export (pull request #57)', null],
    'a number with a set prefix' => [fn () => source(TicketStyle::Number, ['reference_prefix' => 'SHOP-']), '#4321 - Add the order export', 'SHOP-4321'],
    'no key for a number source' => [fn () => source(TicketStyle::Number), 'PROJ-80 Add backups', null],
]);

it('takes the first source that matches', function () {
    $jira = source(TicketStyle::Key, ['name' => 'Jira', 'prefixes' => ['SHOP']]);
    $github = source(TicketStyle::Number, ['name' => 'GitHub']);

    $match = (new TicketResolver)->match(collect([$jira, $github]), 'Fix the cart (#12)');

    expect($match[0]->name)->toBe('GitHub')->and($match[1])->toBe('12');
});
