<?php

namespace Ontob\Moonwatch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Monolog\LogRecord;
use Throwable;
use WeakMap;

/**
 * Collects the events of a request, job or command in memory and hands
 * them to the transport in one go when it ends. An exception reaches it
 * twice when the log channel is in the stack, once from the exception
 * handler and once as a log record, and is kept once.
 */
class Reporter
{
    /**
     * @var list<array<string, mixed>>
     */
    protected array $events = [];

    /**
     * @var WeakMap<Throwable, true>
     */
    protected WeakMap $seen;

    protected bool $flushing = false;

    public function __construct(
        protected Application $app,
        protected Repository $config,
        protected EventFactory $factory,
        protected Transport $transport,
    ) {
        $this->seen = new WeakMap;
    }

    /**
     * Whether events are collected at all: enabled, configured and not
     * running the application's tests.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('moonwatch.enabled', true)
            && $this->transport->isConfigured()
            && ! $this->app->runningUnitTests();
    }

    public function reportException(Throwable $exception): void
    {
        if (! $this->accepts($exception)) {
            return;
        }

        $this->guard(fn () => $this->push($this->factory->fromException($exception)));
    }

    public function reportRecord(LogRecord $record): void
    {
        $exception = $record->context['exception'] ?? null;

        if (! $this->accepts($exception instanceof Throwable ? $exception : null)) {
            return;
        }

        $this->guard(fn () => $this->push($this->factory->fromRecord($record)));
    }

    /**
     * Send what has been collected, if anything.
     */
    public function flush(): void
    {
        if ($this->flushing || $this->events === []) {
            return;
        }

        $events = $this->events;
        $this->events = [];
        $this->seen = new WeakMap;
        $this->flushing = true;

        try {
            $this->transport->send($events);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Name the queued job the following events happen in, or null once it is done.
     */
    public function setJob(?string $job): void
    {
        $this->factory->setJob($job);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->events;
    }

    protected function accepts(?Throwable $exception): bool
    {
        if ($this->flushing || ! $this->isEnabled()) {
            return false;
        }

        if ($exception === null) {
            return true;
        }

        if (isset($this->seen[$exception]) || $this->isIgnored($exception)) {
            return false;
        }

        $this->seen[$exception] = true;

        return true;
    }

    protected function isIgnored(Throwable $exception): bool
    {
        foreach ((array) $this->config->get('moonwatch.ignore', []) as $class) {
            if (is_string($class) && $class !== '' && $exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function push(array $event): void
    {
        if (count($this->events) < max(1, (int) $this->config->get('moonwatch.max_events', 100))) {
            $this->events[] = $event;
        }
    }

    /**
     * Run without letting anything escape: a broken report must not break
     * the exception handler or the logger that called it.
     */
    protected function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            //
        }
    }
}
