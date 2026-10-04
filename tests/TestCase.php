<?php

namespace Ontob\Moonwatch\Tests;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ontob\Moonwatch\MoonwatchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;

abstract class TestCase extends Orchestra
{
    /**
     * What the fake Moonwatch answers instead of accepting, a response or a callback.
     */
    protected mixed $moonwatchResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $response = $this->moonwatchResponse ?? Http::response(['application' => 'Shop', 'accepted' => 1], 202);

            return $response instanceof Closure ? $response($request) : $response;
        });
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MoonwatchServiceProvider::class];
    }

    /**
     * The package never sends while an application runs its tests, so its
     * own tests pretend to be production.
     */
    protected function defineEnvironment($app): void
    {
        $app['env'] = 'production';

        $app['config']->set('app.name', 'Shop');
        $app['config']->set('moonwatch.url', 'https://moonwatch.test');
        $app['config']->set('moonwatch.token', 'mw_secret');
        $app['config']->set('moonwatch.path', '/home/forge/shop.test');
        $app['config']->set('moonwatch.release', 'abc123');
        $app['config']->set('logging.default', 'stack');
        $app['config']->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['moonwatch']]);
    }

    /**
     * Have the application behave as it does behind a web server, where
     * events carry the request instead of the console command.
     */
    protected function servingHttp(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
    }

    /**
     * The events of every request posted to the error endpoint.
     *
     * @return list<array<string, mixed>>
     */
    protected function sentEvents(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/api/ingest/errors'))
            ->flatMap(fn (array $pair): array => $this->payload($pair[0])['events'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        return json_decode($request->body(), true);
    }
}
