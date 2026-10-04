<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ontob\Moonwatch\Commands\InstallCommand;

describe('moonwatch:install', function () {
    it('adds the channel to the stack Laravel ships', function () {
        $config = <<<'PHP'
            'stack' => [
                'driver' => 'stack',
                'channels' => explode(',', (string) env('LOG_STACK', 'single')),
                'ignore_exceptions' => false,
            ],
        PHP;

        $updated = InstallCommand::addToStack($config);

        expect($updated)->toContain("'channels' => array_unique([...explode(',', (string) env('LOG_STACK', 'single')), 'moonwatch']),")
            ->and(InstallCommand::addToStack($updated))->toBe($updated);
    });

    it('adds the channel to a plain list', function () {
        $config = <<<'PHP'
            'stack' => [
                'driver' => 'stack',
                'channels' => ['daily', 'slack'],
            ],
        PHP;

        expect(InstallCommand::addToStack($config))->toContain("'channels' => ['daily', 'slack', 'moonwatch'],");
    });

    it('leaves a config it does not recognise alone', function () {
        expect(InstallCommand::addToStack("'stack' => [\n    'channels' => \$channels,\n],"))->toBeNull()
            ->and(InstallCommand::addToStack("'single' => []"))->toBeNull();
    });

    it('adds only the missing variables to an env file', function () {
        $updated = InstallCommand::addEnvPlaceholders("APP_NAME=Shop\nMOONWATCH_LEVEL=warning\n");

        expect($updated)->toBe("APP_NAME=Shop\nMOONWATCH_LEVEL=warning\n\nMOONWATCH_URL=\nMOONWATCH_TOKEN=\n")
            ->and(InstallCommand::addEnvPlaceholders($updated))->toBe($updated);
    });
});

describe('moonwatch:test', function () {
    it('sends a test error grouped into one issue', function () {
        $this->artisan('moonwatch:test')
            ->expectsOutputToContain('filed it under the application "Shop"')
            ->assertSuccessful();

        expect($this->sentEvents())->toHaveCount(1)
            ->and($this->sentEvents()[0])->toMatchArray(['fingerprint' => 'moonwatch:test', 'level' => 'error']);
    });

    it('fails without a token', function () {
        config(['moonwatch.token' => null]);

        $this->artisan('moonwatch:test')->assertFailed();

        Http::assertNothingSent();
    });

    it('shows what Moonwatch answered to a rejected request', function () {
        $this->moonwatchResponse = Http::response(['message' => 'Unknown token.'], 401);

        $this->artisan('moonwatch:test')
            ->expectsOutputToContain('Moonwatch answered 401: Unknown token.')
            ->assertFailed();
    });
});

describe('moonwatch:deploy', function () {
    beforeEach(function () {
        file_put_contents(base_path('composer.lock'), '{"packages": []}');
    });

    afterEach(function () {
        @unlink(base_path('composer.lock'));
    });

    it('is off by default', function () {
        $this->artisan('moonwatch:deploy')->assertFailed();

        Http::assertNothingSent();
    });

    it('uploads the lock files when enabled', function () {
        config(['moonwatch.deploy.enabled' => true]);

        $this->artisan('moonwatch:deploy')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://moonwatch.test/api/ingest/apps'
            && $request->isMultipart()
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'composer_lock' && $part['contents'] === '{"packages": []}')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'path' && $part['contents'] === '/home/forge/shop.test'));
    });
});
