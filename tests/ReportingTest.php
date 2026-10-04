<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Ontob\Moonwatch\EventFactory;
use Ontob\Moonwatch\Reporter;

function queuedJob(string $name): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

/**
 * An exception that claims to come from the database driver in vendor.
 */
function vendorException(): RuntimeException
{
    $exception = new RuntimeException('SQLSTATE[42S02]: Base table not found');
    (new ReflectionProperty(Exception::class, 'file'))->setValue($exception, base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'));
    (new ReflectionProperty(Exception::class, 'line'))->setValue($exception, 825);

    return $exception;
}

it('sends a log entry with its request when the request ends', function () {
    $this->servingHttp();
    Route::get('orders/{order}', function () {
        Log::error('Order 5 could not be paid', ['order' => 5]);

        return 'ok';
    })->name('orders.show');

    $this->actingAs(new GenericUser(['id' => 17]))->get('orders/5?token=secret')->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://moonwatch.test/api/ingest/errors'
        && $request->hasHeader('Authorization', 'Bearer mw_secret')
        && $this->payload($request)['path'] === '/home/forge/shop.test'
        && $this->payload($request)['name'] === 'Shop');

    expect($this->sentEvents())->toHaveCount(1)
        ->and($this->sentEvents()[0])->toMatchArray([
            'level' => 'error',
            'message' => 'Order 5 could not be paid',
            'exception' => null,
            'url' => 'http://localhost/orders/5',
            'method' => 'GET',
            'route' => 'orders.show',
            'user' => '17',
            'ip' => '127.0.0.1',
            'release' => 'abc123',
            'environment' => 'production',
            'context' => ['order' => 5],
        ]);
});

it('leaves out the user and the ip when their capture is off', function () {
    $this->servingHttp();
    config(['moonwatch.capture.user' => false, 'moonwatch.capture.ip' => false]);
    Route::get('fail', fn () => Log::error('Failed') ? 'ok' : 'ok');

    $this->actingAs(new GenericUser(['id' => 17]))->get('fail');

    expect($this->sentEvents()[0])->toMatchArray(['user' => null, 'ip' => null]);
});

it('sends log entries from the configured level up', function (string $level, bool $sent) {
    config(['logging.channels.moonwatch.level' => $level]);

    Log::warning('Disk almost full');
    app(Reporter::class)->flush();

    expect($this->sentEvents())->toHaveCount($sent ? 1 : 0);
})->with([
    'error leaves out a warning' => ['error', false],
    'warning sends a warning' => ['warning', true],
]);

it('sends a reported exception once although the channel is in the log stack', function () {
    $this->servingHttp();
    Route::get('checkout', fn () => throw new RuntimeException('Payment gateway timed out'));

    $this->get('checkout')->assertServerError();

    expect($this->sentEvents())->toHaveCount(1)
        ->and($this->sentEvents()[0])->toMatchArray([
            'level' => 'error',
            'message' => 'Payment gateway timed out',
            'exception' => RuntimeException::class,
            'file' => __FILE__,
        ])
        ->and($this->sentEvents()[0]['trace'])->toContain('#0');
});

it('appends the previous exceptions to the trace', function () {
    report(new RuntimeException('Checkout failed', 0, new LogicException('Card declined')));
    app(Reporter::class)->flush();

    expect($this->sentEvents()[0]['trace'])->toContain('Caused by LogicException: Card declined');
});

it('skips the exception classes in the ignore list', function () {
    config(['moonwatch.ignore' => [LogicException::class]]);

    report(new InvalidArgumentException('Ignored, a LogicException'));
    report(new RuntimeException('Sent'));
    app(Reporter::class)->flush();

    expect($this->sentEvents())->toHaveCount(1)
        ->and($this->sentEvents()[0]['message'])->toBe('Sent');
});

it('points an exception thrown in a vendor package to the application code that called it', function () {
    $this->app->setBasePath(dirname(__DIR__));

    $exception = vendorException();
    $line = __LINE__ - 1;

    $event = app(EventFactory::class)->fromException($exception);

    expect($event['file'])->toBe(__FILE__)
        ->and($event['line'])->toBe($line);
});

it('sends nothing unless enabled and configured', function (array $config) {
    config($config);

    report(new RuntimeException('Not sent'));
    app(Reporter::class)->flush();

    Http::assertNothingSent();
})->with([
    'disabled' => [['moonwatch.enabled' => false]],
    'without a token' => [['moonwatch.token' => null]],
    'without a url' => [['moonwatch.url' => '']],
]);

it('sends nothing while the application runs its tests', function () {
    $this->app['env'] = 'testing';

    report(new RuntimeException('Not sent'));
    app(Reporter::class)->flush();

    Http::assertNothingSent();
});

it('posts in batches of 50 and keeps at most max_events', function () {
    config(['moonwatch.max_events' => 120]);

    foreach (range(1, 130) as $number) {
        Log::error("Failure {$number}");
    }
    app(Reporter::class)->flush();

    Http::assertSentCount(3);
    expect($this->sentEvents())->toHaveCount(120);
});

it('drops the events when Moonwatch cannot be reached', function () {
    $this->moonwatchResponse = fn () => throw new ConnectionException('Connection refused');

    Log::error('Lost');
    app(Reporter::class)->flush();

    expect(app(Reporter::class)->pending())->toBe([]);
});

it('sends after each queued job, named after the job', function () {
    $job = queuedJob('App\Jobs\ChargeOrder');

    event(new JobProcessing('redis', $job));
    Log::error('Charge failed');
    event(new JobProcessed('redis', $job));

    expect($this->sentEvents())->toHaveCount(1)
        ->and($this->sentEvents()[0])->toMatchArray(['route' => 'App\Jobs\ChargeOrder', 'url' => null]);
});

it('sends a failed job reported by the worker when it loops to the next job', function () {
    event(new JobProcessing('redis', queuedJob('App\Jobs\ChargeOrder')));
    report(new RuntimeException('Charge failed'));

    Http::assertNothingSent();

    event(new Looping('redis', 'default', new WorkerOptions));

    expect($this->sentEvents())->toHaveCount(1)
        ->and($this->sentEvents()[0]['route'])->toBe('App\Jobs\ChargeOrder');
});
