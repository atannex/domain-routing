<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as Builder;

/** A users table for the auth tests, safe to call again on a real (persistent) database. */
final class Schema
{
    public static function users(?string $connection = null): void
    {
        $schema = Builder::connection($connection);
        $schema->dropIfExists('users');
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
