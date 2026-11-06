<?php

declare(strict_types=1);

it('merges the package config', function () {
    expect(config('domain-routing.unmatched'))->toBe('abort')
        ->and(config('domain-routing.except'))->toBe(['up'])
        ->and(config('domain-routing.canonical.non_get'))->toBe(421);
});
