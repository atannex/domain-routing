<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests\Combo;

use Atannex\DomainRouting\Tests\TestCase;

/** Runs only when the optional packages are installed (the standalone CI leg removes them). */
abstract class ComboTestCase extends TestCase
{
    /** @var list<class-string> */
    protected array $requires = [];

    protected function setUp(): void
    {
        foreach ($this->requires as $class) {
            if (! class_exists($class)) {
                $this->markTestSkipped("{$class} is not installed.");
            }
        }

        parent::setUp();
    }
}
