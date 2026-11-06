<?php

declare(strict_types=1);

use Atannex\DomainRouting\Sites\Site;

it('picks hosts for the environment with a * fallback', function () {
    $config = ['hosts' => ['production' => ['atannex.com', 'www.atannex.com'], '*' => ['atannex.test']]];

    expect(Site::fromArray('marketing', $config, 'production')->hosts)->toBe(['atannex.com', 'www.atannex.com'])
        ->and(Site::fromArray('marketing', $config, 'local')->hosts)->toBe(['atannex.test']);
});

it('accepts a plain list for every environment and marks dynamic sites', function () {
    $site = Site::fromArray('tenant', ['hosts' => ['dynamic']], 'production');

    expect($site->dynamic)->toBeTrue()->and($site->hosts)->toBe([]);
});

it('lets an environment variable replace the hosts', function () {
    putenv('DOMAIN_ROUTING_HOSTS_MY_APP=one.test, two.test');

    expect(Site::fromArray('my-app', ['hosts' => ['x.test']], 'local')->hosts)->toBe(['one.test', 'two.test']);

    putenv('DOMAIN_ROUTING_HOSTS_MY_APP');
});

it('derives the canonical host', function () {
    expect(Site::fromArray('m', ['hosts' => ['www.a.com', 'a.com'], 'canonical' => 'A.com'], 'x')->canonicalHost())->toBe('a.com')
        ->and(Site::fromArray('m', ['hosts' => ['www.a.com', 'a.com']], 'x')->canonicalHost())->toBe('www.a.com')
        ->and(Site::fromArray('m', ['hosts' => ['{x}.a.com']], 'x')->canonicalHost())->toBeNull()
        ->and(Site::fromArray('m', ['hosts' => ['{x}.a.com']], 'x')->hostParameterNames())->toBe(['x']);
});

it('prepends middleware without duplicates', function () {
    $site = Site::fromArray('t', ['hosts' => ['dynamic'], 'middleware' => ['web']], 'x')
        ->withPrependedMiddleware('tenant')->withPrependedMiddleware('tenant');

    expect($site->middleware)->toBe(['tenant', 'web']);
});
