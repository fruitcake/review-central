<?php

use App\Models\Repository;
use App\Services\ReviewStack;

it('resolves the stacks the review reads a guide for', function (?string $variable, ?array $own, array $expected) {
    $repository = new Repository(['review_stack' => $own]);

    expect(ReviewStack::resolve($repository, $variable))->toBe($expected);
})->with([
    'nothing set is no stack' => [null, null, []],
    'the repository names it' => [null, ['magento', 'hyva'], ['magento', 'hyva']],
    'hyva implies magento' => ['hyva', null, ['magento', 'hyva']],
    'the variable beats the repository' => ['laravel', ['drupal'], ['laravel']],
    'none is none' => ['none', ['drupal'], []],
    'another spelling of a name is that name' => ['Laravel', null, ['laravel']],
]);

it('reads the stacks a repository is built on from its composer.json', function (?string $composer, array $expected) {
    expect(ReviewStack::fromComposer($composer))->toBe($expected);
})->with([
    'no composer.json' => [null, []],
    'nothing the review has a guide for' => ['{"require": {"symfony/console": "^7.0"}}', []],
    'a Laravel application' => ['{"require": {"php": "^8.3", "laravel/framework": "^13.0"}}', ['laravel']],
    'a package that only tests against Laravel' => ['{"require-dev": {"laravel/framework": "^13.0"}}', ['laravel']],
    'a package that merely starts with the name' => ['{"require": {"laravel/framework-extras": "^1.0"}}', []],
    'a Magento project' => ['{"require": {"magento/product-community-edition": "2.4.7"}}', ['magento']],
    'a Magento module of its own' => ['{"name": "acme/module", "type": "magento2-module"}', ['magento']],
    'a Hyva theme is a Magento theme' => ['{"require": {"hyva-themes/magento2-default-theme": "^1.3"}}', ['magento', 'hyva']],
    'a Drupal site' => ['{"require": {"drupal/core-recommended": "^11"}}', ['drupal']],
    'a Drupal module of its own' => ['{"type" : "drupal-custom-module"}', ['drupal']],
]);
