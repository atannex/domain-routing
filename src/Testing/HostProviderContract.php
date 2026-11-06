<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Testing;

use Atannex\DomainRouting\Contracts\HostProvider;
use Atannex\DomainRouting\Sites\SiteRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * Behaviour every HostProvider must have. Use it in a PHPUnit test case (or
 * Pest's uses()) and implement the four methods.
 */
trait HostProviderContract
{
    abstract protected function hostProvider(): HostProvider;

    abstract protected function hostProviderSites(): SiteRegistry;

    /** A host the provider must resolve (normalised). */
    abstract protected function knownHost(): string;

    /** A host the provider must not resolve. */
    abstract protected function unknownHost(): string;

    #[Test]
    public function it_resolves_a_known_host_to_a_dynamic_site(): void
    {
        $match = $this->hostProvider()->match($this->knownHost(), $this->hostProviderSites());

        $this->assertNotNull($match);
        $this->assertTrue($match->site->dynamic, 'Providers may only return sites that declare dynamic hosts.');
        $this->assertSame($this->knownHost(), $match->host);
    }

    #[Test]
    public function it_returns_null_for_unknown_hosts(): void
    {
        $this->assertNull($this->hostProvider()->match($this->unknownHost(), $this->hostProviderSites()));
    }

    #[Test]
    public function it_survives_odd_hosts(): void
    {
        foreach (['localhost', '127.0.0.1', 'xn--bcher-kva.example', str_repeat('a', 63).'.example'] as $host) {
            $this->hostProvider()->match($host, $this->hostProviderSites());
        }

        $this->addToAssertionCount(1);
    }
}
