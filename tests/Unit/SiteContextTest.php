<?php

declare(strict_types=1);

use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Sites\Site;

it('runs a callback inside a site and restores the previous one', function () {
    $context = new SiteContext;
    $a = new SiteMatch(new Site('a'), 'a.test');
    $b = new SiteMatch(new Site('b'), 'b.test');
    $context->set($a);

    $inside = $context->run($b, fn () => $context->current()->name());

    expect($inside)->toBe('b')->and($context->current()->name())->toBe('a')
        ->and($context->is('a', 'c'))->toBeTrue()->and($context->is('b'))->toBeFalse();
});

it('restores the previous site when the callback throws', function () {
    $context = new SiteContext;
    try {
        $context->run(new SiteMatch(new Site('b'), 'b.test'), fn () => throw new RuntimeException);
    } catch (RuntimeException) {
    }

    expect($context->current())->toBeNull();
});
