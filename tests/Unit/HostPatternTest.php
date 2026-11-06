<?php

declare(strict_types=1);

use Atannex\DomainRouting\Exceptions\InvalidHostParameter;
use Atannex\DomainRouting\Exceptions\InvalidHostPattern;
use Atannex\DomainRouting\Exceptions\MissingHostParameter;
use Atannex\DomainRouting\Hosts\HostPattern;

it('matches exact hosts', function () {
    $p = HostPattern::parse('Atannex.COM');

    expect($p->isExact())->toBeTrue()
        ->and($p->pattern)->toBe('atannex.com')
        ->and($p->matches('atannex.com'))->toBe([])
        ->and($p->matches('www.atannex.com'))->toBeNull();
});

it('extracts parameters, one label by default', function () {
    $p = HostPattern::parse('{account}.atannex.com');

    expect($p->parameters)->toBe(['account'])
        ->and($p->matches('acme.atannex.com'))->toBe(['account' => 'acme'])
        ->and($p->matches('a.b.atannex.com'))->toBeNull()
        ->and($p->matches('atannex.com'))->toBeNull();
});

it('keeps parameter name case and honours where constraints', function () {
    $p = HostPattern::parse('{accountId}.Atannex.com', ['accountId' => '[0-9]+']);

    expect($p->matches('42.atannex.com'))->toBe(['accountId' => '42'])
        ->and($p->matches('acme.atannex.com'))->toBeNull();
});

it('computes specificity and shape', function () {
    $a = HostPattern::parse('{a}.shop.example.com');
    $b = HostPattern::parse('{b}.example.com');

    expect($a->literalLength)->toBeGreaterThan($b->literalLength)
        ->and(HostPattern::parse('{x}.example.com')->shape)->toBe($b->shape);
});

it('builds hosts from values', function () {
    expect(HostPattern::parse('{account}.atannex.com')->build(['account' => 'ACME']))->toBe('acme.atannex.com');
});

it('refuses missing and unsafe values', function () {
    $p = HostPattern::parse('{account}.atannex.com');

    expect(fn () => $p->build([], 'app'))->toThrow(MissingHostParameter::class, 'account')
        ->and(fn () => $p->build(['account' => 'evil.com/x'], 'app'))->toThrow(InvalidHostParameter::class)
        ->and(fn () => $p->build(['account' => 'a.b'], 'app'))->toThrow(InvalidHostParameter::class);
});

it('rejects non-ascii literals next to parameters', function () {
    HostPattern::parse('{a}.bücher.example');
})->throws(InvalidHostPattern::class);

it('rejects broken constraints and repeated parameters', function () {
    expect(fn () => HostPattern::parse('{a}.x.test', ['a' => '[unclosed']))->toThrow(InvalidHostPattern::class, 'regular expression')
        ->and(fn () => HostPattern::parse('{a}.{a}.x.test'))->toThrow(InvalidHostPattern::class, 'twice');
});

it('rejects malformed patterns', function (string $raw) {
    HostPattern::parse($raw);
})->throws(InvalidHostPattern::class)->with(['', '{a', 'exa mple.com', '{a}{b}.example.com']);
