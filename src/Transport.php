<?php

namespace Ontob\Moonwatch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Posts to Moonwatch with the server token.
 */
class Transport
{
    /**
     * The most events the endpoint accepts in one request.
     */
    public const BATCH_SIZE = 50;

    public function __construct(
        protected Application $app,
        protected Repository $config,
        protected Factory $http,
    ) {}

    /**
     * Whether the URL and the token are set.
     */
    public function isConfigured(): bool
    {
        return filled($this->config->get('moonwatch.url')) && filled($this->config->get('moonwatch.token'));
    }

    /**
     * Post the events in batches, swallowing every failure: reporting an
     * error must never cause another one.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function send(array $events): void
    {
        foreach (array_chunk($events, self::BATCH_SIZE) as $batch) {
            try {
                $this->postEvents($batch);
            } catch (Throwable) {
                return;
            }
        }
    }

    /**
     * Post one batch of events and return the response.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function postEvents(array $events, ?float $timeout = null): Response
    {
        $body = json_encode(
            ['path' => $this->path(), 'name' => $this->name(), 'events' => $events],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return $this->request($timeout ?? (float) $this->config->get('moonwatch.timeout', 2))
            ->withBody((string) $body, 'application/json')
            ->post($this->endpoint('api/ingest/errors'));
    }

    /**
     * A request to Moonwatch with the token, to be sent by the caller.
     */
    public function request(float $timeout): PendingRequest
    {
        return $this->http->withToken((string) $this->config->get('moonwatch.token'))
            ->acceptJson()
            ->connectTimeout(min($timeout, 5))
            ->timeout($timeout);
    }

    public function endpoint(string $uri): string
    {
        return rtrim((string) $this->config->get('moonwatch.url'), '/').'/'.$uri;
    }

    /**
     * The path Moonwatch knows the application by.
     */
    public function path(): string
    {
        $path = $this->config->get('moonwatch.path');

        return str_replace('\\', '/', filled($path) ? (string) $path : $this->app->basePath());
    }

    public function name(): ?string
    {
        $name = $this->config->get('moonwatch.name') ?? $this->config->get('app.name');

        return filled($name) ? mb_strcut((string) $name, 0, 255, 'UTF-8') : null;
    }
}
