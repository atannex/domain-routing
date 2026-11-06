<?php

declare(strict_types=1);

use Atannex\DomainRouting\Hosts\HostNormalizer;

it('normalizes hosts', function (string $raw, string $expected) {
    expect(HostNormalizer::normalize($raw))->toBe($expected);
})->with([
    ['Acme.Example.COM', 'acme.example.com'],
    ['https://acme.example.com:8443/path?x=1', 'acme.example.com'],
    ['acme.example.com.', 'acme.example.com'],
    ['  acme.example.com  ', 'acme.example.com'],
    ['bücher.example', 'xn--bcher-kva.example'],
]);

it('validates hosts', function (string $host, bool $valid) {
    expect(HostNormalizer::isValid($host))->toBe($valid);
})->with([
    ['example.com', true],
    ['localhost', true],
    ['127.0.0.1', true],
    ['a-b.example.com', true],
    ['-bad.example.com', false],
    ['bad_.example.com', false],
    ['', false],
    [str_repeat('a', 64).'.com', false],
    ['exa mple.com', false],
]);
