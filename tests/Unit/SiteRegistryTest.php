<?php

declare(strict_types=1);

use Atannex\DomainRouting\Exceptions\SitesFrozen;
use Atannex\DomainRouting\Exceptions\UnknownSite;
use Atannex\DomainRouting\Sites\SiteRegistry;

it('builds from config and answers lookups', function () {
    $registry = SiteRegistry::fromConfig([
        'app' => ['hosts' => ['{account}.atannex.com']],
        'tenant' => ['hosts' => ['dynamic']],
    ], 'production');

    expect($registry->has('app'))->toBeTrue()
        ->and($registry->get('app')->name)->toBe('app')
        ->and($registry->hasDynamic())->toBeTrue()
        ->and($registry->isEmpty())->toBeFalse()
        ->and(array_keys($registry->all()))->toBe(['app', 'tenant']);
});

it('throws for unknown sites', function () {
    (new SiteRegistry)->get('nope');
})->throws(UnknownSite::class, 'nope');

it('refuses changes after freezing', function () {
    $registry = new SiteRegistry;
    $registry->freeze();
    $registry->define('late', ['hosts' => ['late.test']], 'local');
})->throws(SitesFrozen::class);

it('changes its hash when hosts change', function () {
    $a = SiteRegistry::fromConfig(['app' => ['hosts' => ['a.test']]], 'local');
    $b = SiteRegistry::fromConfig(['app' => ['hosts' => ['b.test']]], 'local');

    expect($a->hash())->not->toBe($b->hash());
});
