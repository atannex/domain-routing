<?php

declare(strict_types=1);

use Atannex\DomainRouting\Tests\Combo\RoutingTestCase;

uses(RoutingTestCase::class);

it('puts #[Site] controllers on their site', function () {
    $this->get('http://api.atannex.test/attributed')->assertOk()->assertJson(['site' => 'api', 'route' => 'api.attributed']);
    $this->get('http://atannex.test/attributed')->assertNotFound();
    expect(route('api.attributed'))->toBe('http://api.atannex.test/attributed');
});
