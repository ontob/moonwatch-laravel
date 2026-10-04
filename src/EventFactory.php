<?php

namespace Ontob\Moonwatch;

use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * Turns an exception or a log record into the event Moonwatch accepts,
 * with the request or job it happened in. Values are cut to the limits
 * the endpoint validates, so one oversized event cannot get the whole
 * batch rejected. Grouping is left to Moonwatch.
 *
 * @phpstan-type Event array{
 *     level: string,
 *     message: string,
 *     exception: string|null,
 *     file: string|null,
 *     line: int|null,
 *     trace: string|null,
 *     occurred_at: string,
 *     release: string|null,
 *     environment: string|null,
 *     url: string|null,
 *     method: string|null,
 *     route: string|null,
 *     user: string|null,
 *     ip: string|null,
 *     hostname: string|null,
 *     context: array<string, mixed>|null,
 *     fingerprint?: string,
 * }
 */
class EventFactory
{
    public const MAX_TRACE_BYTES = 65536;

    /**
     * How many previous exceptions are appended to the trace.
     */
    protected const MAX_PREVIOUS = 3;

    protected ?string $job = null;

    public function __construct(
        protected Application $app,
        protected Release $release,
    ) {}

    /**
     * Name the queued job the following events happen in, or null once it is done.
     */
    public function setJob(?string $job): void
    {
        $this->job = $job;
    }

    /**
     * @return Event
     */
    public function fromException(Throwable $exception, string $level = 'error'): array
    {
        return $this->event($level, $exception->getMessage(), $exception, []);
    }

    /**
     * @return Event
     */
    public function fromRecord(LogRecord $record): array
    {
        $context = $record->context;
        $exception = ($context['exception'] ?? null) instanceof Throwable ? $context['exception'] : null;
        unset($context['exception']);

        $normaliser = new class(DateTimeInterface::ATOM) extends NormalizerFormatter
        {
            public function toPlain(mixed $data): mixed
            {
                return $this->normalize($data);
            }
        };

        $normalised = $normaliser->toPlain(array_filter(['context' => $context, 'extra' => $record->extra]));

        $extra = is_array($normalised) ? ($normalised['extra'] ?? []) : [];
        $context = is_array($normalised) ? ($normalised['context'] ?? []) : [];

        if (is_array($extra) && $extra !== []) {
            $context['extra'] = $extra;
        }

        return $this->event(strtolower($record->level->getName()), $record->message, $exception, is_array($context) ? $context : []);
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return Event
     */
    protected function event(string $level, string $message, ?Throwable $exception, array $context): array
    {
        [$file, $line] = $exception !== null ? $this->location($exception) : [null, null];

        if (trim($message) === '') {
            $message = $exception !== null ? $exception::class : '(empty message)';
        }

        return array_merge([
            'level' => $level,
            'message' => $this->cut($message, 10000),
            'exception' => $exception !== null ? $this->cut($exception::class, 255) : null,
            'file' => $this->cut($file, 1024),
            'line' => $line,
            'trace' => $exception !== null ? $this->cut($this->trace($exception), self::MAX_TRACE_BYTES) : null,
            'occurred_at' => Carbon::now()->format('Y-m-d\TH:i:s.uP'),
            'release' => $this->cut($this->release->current(), 64),
            'environment' => $this->cut($this->app->environment(), 32),
            'hostname' => $this->cut(gethostname() ?: null, 255),
            'context' => $context === [] ? null : $context,
        ], $this->origin());
    }

    /**
     * Where the event happened: the request, or the job or command.
     *
     * @return array{url: string|null, method: string|null, route: string|null, user: string|null, ip: string|null}
     */
    protected function origin(): array
    {
        $origin = ['url' => null, 'method' => null, 'route' => null, 'user' => $this->userId(), 'ip' => null];

        if ($this->job !== null) {
            return array_merge($origin, ['route' => $this->cut($this->job, 255)]);
        }

        if ($this->app->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? null;

            return array_merge($origin, ['route' => is_string($command) ? $this->cut('artisan '.$command, 255) : null]);
        }

        if (! $this->app->bound('request')) {
            return $origin;
        }

        /** @var Request $request */
        $request = $this->app->make('request');
        $route = $request->route();

        return array_merge($origin, [
            'url' => $this->cut($request->url(), 2048),
            'method' => $this->cut($request->getMethod(), 8),
            'route' => $this->cut(is_object($route) && method_exists($route, 'getName') ? ($route->getName() ?? $route->uri()) : null, 255),
            'ip' => $this->app['config']->get('moonwatch.capture.ip', true) ? $this->cut($request->ip(), 45) : null,
        ]);
    }

    /**
     * The signed in user, without loading one that is not loaded yet: that
     * could query the database while the database is what failed.
     */
    protected function userId(): ?string
    {
        if (! $this->app['config']->get('moonwatch.capture.user', true) || ! $this->app->resolved('auth')) {
            return null;
        }

        try {
            $guard = $this->app->make('auth')->guard();

            if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
                return null;
            }

            $id = $guard->id();

            return is_scalar($id) ? $this->cut((string) $id, 64) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Where the exception comes from in the application. An exception
     * thrown inside a vendor package points to the first application
     * frame that called it, so a failed query is grouped by the code that
     * ran it rather than by the database driver.
     *
     * @return array{0: string, 1: int|null}
     */
    protected function location(Throwable $exception): array
    {
        if (! $this->isVendor($exception->getFile())) {
            return [$exception->getFile(), $exception->getLine()];
        }

        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && ! $this->isVendor($file) && ! $this->isEntryPoint($file)) {
                return [$file, isset($frame['line']) ? (int) $frame['line'] : null];
            }
        }

        return [$exception->getFile(), $exception->getLine()];
    }

    protected function trace(Throwable $exception): string
    {
        $trace = $exception->getTraceAsString();
        $previous = $exception->getPrevious();

        for ($depth = 0; $previous !== null && $depth < self::MAX_PREVIOUS; $depth++) {
            $trace .= sprintf(
                "\n\nCaused by %s: %s in %s:%d\n%s",
                $previous::class,
                $previous->getMessage(),
                $previous->getFile(),
                $previous->getLine(),
                $previous->getTraceAsString(),
            );

            $previous = $previous->getPrevious();
        }

        return $trace;
    }

    protected function isVendor(string $file): bool
    {
        return str_starts_with($this->normalisePath($file), $this->normalisePath($this->app->basePath('vendor')).'/');
    }

    protected function isEntryPoint(string $file): bool
    {
        $file = $this->normalisePath($file);

        return in_array($file, [
            $this->normalisePath($this->app->basePath('artisan')),
            $this->normalisePath($this->app->basePath('public/index.php')),
        ], true);
    }

    protected function normalisePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    protected function cut(?string $value, int $bytes): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_strcut($value, 0, $bytes, 'UTF-8');
    }
}
