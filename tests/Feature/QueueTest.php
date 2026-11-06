<?php

declare(strict_types=1);

use Atannex\DomainRouting\Facades\DomainRouting;
use Atannex\DomainRouting\Queue\QueueContext;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

final class RecordSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = [current_site()?->name(), route('app.dashboard')];
    }
}

it('keeps the request site through a sync job and gives the job the site', function () {
    config(['queue.default' => 'sync']);
    RecordSiteJob::$seen = [];
    Route::site('app', fn () => Route::get('/dashboard', EchoController::class)->name('dashboard'));
    Route::site('app', fn () => Route::get('/dispatch', function () {
        RecordSiteJob::dispatch();

        return response()->json(['after' => current_site()?->name()]);
    }));

    $this->get('http://acme.app.test/dispatch')->assertJson(['after' => 'app']);

    expect(RecordSiteJob::$seen)->toBe([['app', 'http://acme.app.test/dashboard']]);
});

it('restores the site in a worker from the payload', function () {
    config(['queue.default' => 'database']);
    Route::site('app', fn () => Route::get('/dashboard', EchoController::class)->name('dashboard'));

    $payload = null;
    Queue::createPayloadUsing(null);
    // Build the payload as the provider would, inside a site.
    DomainRouting::run('app', function () use (&$payload) {
        $payload = app(QueueContext::class)->payload();
    }, ['account' => 'acme']);

    expect($payload['domainRouting'])->toMatchArray(['site' => 'app', 'host' => 'acme.app.test', 'parameters' => ['account' => 'acme']]);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);

    $context = app(QueueContext::class);
    $context->enter($job);
    expect(route('app.dashboard'))->toBe('http://acme.app.test/dashboard');
    $context->leave();
    expect(current_site())->toBeNull();
});
