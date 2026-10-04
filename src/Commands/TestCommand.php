<?php

namespace Ontob\Moonwatch\Commands;

use Illuminate\Console\Command;
use Ontob\Moonwatch\EventFactory;
use Ontob\Moonwatch\Transport;
use RuntimeException;
use Throwable;

/**
 * Sends one synthetic error and prints what Moonwatch answered. It is
 * always grouped into the same issue, so running it again only counts
 * another occurrence.
 */
class TestCommand extends Command
{
    protected $signature = 'moonwatch:test';

    protected $description = 'Send a test error to Moonwatch';

    public function handle(Transport $transport, EventFactory $factory): int
    {
        if (! $transport->isConfigured()) {
            $this->components->error('Set MOONWATCH_URL and MOONWATCH_TOKEN first.');

            return self::FAILURE;
        }

        if (! $this->laravel['config']->get('moonwatch.enabled', true)) {
            $this->components->warn('Reporting is disabled by MOONWATCH_ENABLED; the test is sent anyway.');
        }

        $event = $factory->fromException(new RuntimeException('This is a test error sent by php artisan moonwatch:test.'));
        $event['fingerprint'] = 'moonwatch:test';

        try {
            $response = $transport->postEvents([$event], 10);
        } catch (Throwable $exception) {
            $this->components->error('Could not reach '.$transport->endpoint('api/ingest/errors').': '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error(sprintf('Moonwatch answered %d: %s', $response->status(), $response->json('message') ?? $response->body()));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Sent. Moonwatch filed it under the application "%s" (path %s).',
            $response->json('application') ?? '?',
            $transport->path(),
        ));

        return self::SUCCESS;
    }
}
