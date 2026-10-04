<?php

namespace Ontob\Moonwatch;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use Ontob\Moonwatch\Commands\DeployCommand;
use Ontob\Moonwatch\Commands\InstallCommand;
use Ontob\Moonwatch\Commands\TestCommand;
use Ontob\Moonwatch\Logging\MoonwatchHandler;
use Throwable;

class MoonwatchServiceProvider extends ServiceProvider
{
    /**
     * Events after which a long-running Octane worker sends what it collected.
     */
    protected const OCTANE_EVENTS = [
        'Laravel\Octane\Events\RequestTerminated',
        'Laravel\Octane\Events\TaskTerminated',
        'Laravel\Octane\Events\TickTerminated',
    ];

    protected static bool $shutdownRegistered = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/moonwatch.php', 'moonwatch');

        $config = $this->app['config'];

        if (! $config->has('logging.channels.moonwatch')) {
            $config->set('logging.channels.moonwatch', [
                'driver' => 'monolog',
                'handler' => MoonwatchHandler::class,
                'level' => $config->get('moonwatch.level', 'error'),
            ]);
        }

        $this->app->singleton(Release::class, fn (Application $app): Release => new Release(
            $app->basePath(),
            $app['config']->get('moonwatch.release'),
        ));

        $this->app->singleton(EventFactory::class);
        $this->app->singleton(Transport::class);
        $this->app->singleton(Reporter::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/moonwatch.php' => $this->app->configPath('moonwatch.php'),
            ], 'moonwatch-config');

            $this->commands([InstallCommand::class, TestCommand::class, DeployCommand::class]);
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $exception): void {
                    $this->app->make(Reporter::class)->reportException($exception);
                });
            }
        });

        $this->registerFlushes();
    }

    /**
     * Send the collected events when a request, job or command is over.
     * The shutdown function covers scripts that exit without terminating
     * the application, and errors that end the process.
     */
    protected function registerFlushes(): void
    {
        $flush = function (): void {
            $this->app->make(Reporter::class)->flush();
        };

        $this->app->terminating($flush);

        if (! static::$shutdownRegistered) {
            static::$shutdownRegistered = true;

            register_shutdown_function(function (): void {
                try {
                    if ($this->app->resolved(Reporter::class)) {
                        $this->app->make(Reporter::class)->flush();
                    }
                } catch (Throwable) {
                    //
                }
            });
        }

        /** @var Dispatcher $events */
        $events = $this->app['events'];

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                $this->app->make(Reporter::class)->setJob($event->job->resolveName());
            }
        });

        $events->listen(JobProcessed::class, function (JobProcessed $event) use ($flush): void {
            if ($event->connectionName !== 'sync') {
                $flush();
            }
        });

        // A failed job is reported by the worker after its events, so it is
        // sent when the worker loops to the next job, still named after it.
        $events->listen(Looping::class, function () use ($flush): void {
            $flush();
            $this->app->make(Reporter::class)->setJob(null);
        });

        $events->listen(WorkerStopping::class, $flush);
        $events->listen(self::OCTANE_EVENTS, $flush);
    }
}
