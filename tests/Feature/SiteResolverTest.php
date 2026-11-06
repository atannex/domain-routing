<?php

declare(strict_types=1);

use Atannex\DomainRouting\Contracts\HostProvider;
use Atannex\DomainRouting\Hosts\Extensions;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Atannex\DomainRouting\Sites\SiteRegistry;

function resolver(array $sites, array $providers = []): SiteResolver
{
    $extensions = new Extensions(app());
    foreach ($providers as $p) {
        $extensions->addProvider($p);
    }

    return new SiteResolver(SiteRegistry::fromConfig($sites, 'testing'), $extensions);
}

it('resolves static hosts after normalising', function () {
    $match = resolver(['app' => ['hosts' => ['{account}.atannex.com']]])->resolve('ACME.atannex.com:443');

    expect($match->name())->toBe('app')
        ->and($match->host)->toBe('acme.atannex.com')
        ->and($match->parameters)->toBe(['account' => 'acme'])
        ->and($match->source)->toBe(SiteMatch::PATTERN);
});

it('returns null for invalid or unknown hosts', function () {
    $r = resolver(['app' => ['hosts' => ['a.test']]]);

    expect($r->resolve('bad host'))->toBeNull()->and($r->resolve('b.test'))->toBeNull();
});

it('asks providers only for dynamic sites and stamps the source', function () {
    $provider = new class implements HostProvider
    {
        public int $calls = 0;

        public function match(string $host, SiteRegistry $sites): ?SiteMatch
        {
            $this->calls++;

            return $host === 'shop.example' ? new SiteMatch($sites->get('tenant'), $host, ['tenant' => 'shop'], SiteMatch::EXACT, 'www.shop.example') : null;
        }
    };

    $r = resolver(['tenant' => ['hosts' => ['dynamic']], 'app' => ['hosts' => ['a.test']]], [$provider]);

    expect($r->resolve('a.test')->name())->toBe('app')->and($provider->calls)->toBe(0);

    $match = $r->resolve('shop.example');
    expect($match->source)->toBe(SiteMatch::PROVIDER)
        ->and($match->canonicalHost)->toBe('www.shop.example')
        ->and($r->resolve('other.example'))->toBeNull();
});

it('skips providers when no site is dynamic', function () {
    $provider = new class implements HostProvider
    {
        public function match(string $host, SiteRegistry $sites): ?SiteMatch
        {
            throw new RuntimeException('must not be called');
        }
    };

    expect(resolver(['app' => ['hosts' => ['a.test']]], [$provider])->resolve('x.test'))->toBeNull();
});

it('treats a throwing provider as a miss and moves on', function () {
    $broken = new class implements HostProvider
    {
        public function match(string $host, SiteRegistry $sites): ?SiteMatch
        {
            throw new RuntimeException('db down');
        }
    };
    $working = new class implements HostProvider
    {
        public function match(string $host, SiteRegistry $sites): ?SiteMatch
        {
            return new SiteMatch($sites->get('tenant'), $host);
        }
    };

    expect(resolver(['tenant' => ['hosts' => ['dynamic']]], [$broken, $working])->resolve('x.example')->name())->toBe('tenant');
});

it('rejects providers that return a non-dynamic site', function () {
    $liar = new class implements HostProvider
    {
        public function match(string $host, SiteRegistry $sites): ?SiteMatch
        {
            return new SiteMatch($sites->get('admin'), $host);
        }
    };

    expect(resolver(['tenant' => ['hosts' => ['dynamic']], 'admin' => ['hosts' => ['admin.test']]], [$liar])->resolve('x.example'))->toBeNull();
});
