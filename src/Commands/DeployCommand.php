<?php

namespace Ontob\Moonwatch\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Ontob\Moonwatch\Release;
use Ontob\Moonwatch\Transport;
use Throwable;

/**
 * The deploy hook as a command: uploads composer.json, the lock files and
 * `php artisan about` so Moonwatch audits the dependencies, like the curl
 * snippet on the application's Setup tab. Off unless enabled in config.
 */
class DeployCommand extends Command
{
    protected $signature = 'moonwatch:deploy {--path= : The site path, when it differs from the configured one}';

    protected $description = 'Upload the lock files to Moonwatch for the dependency audit';

    public function handle(Transport $transport, Release $release): int
    {
        if (! $this->laravel['config']->get('moonwatch.deploy.enabled', false)) {
            $this->components->warn('The deploy upload is off. Set MOONWATCH_DEPLOY_ENABLED=true to use it.');

            return self::FAILURE;
        }

        if (! $transport->isConfigured()) {
            $this->components->error('Set MOONWATCH_URL and MOONWATCH_TOKEN first.');

            return self::FAILURE;
        }

        $files = array_filter([
            'composer_json' => 'composer.json',
            'composer_lock' => 'composer.lock',
            'package_lock' => 'package-lock.json',
        ], fn (string $file): bool => is_file($this->laravel->basePath($file)));

        if (! isset($files['composer_lock']) && ! isset($files['package_lock'])) {
            $this->components->error('There is neither a composer.lock nor a package-lock.json to upload.');

            return self::FAILURE;
        }

        $request = $transport->request(30);

        foreach ($files as $field => $file) {
            $request = $request->attach($field, (string) file_get_contents($this->laravel->basePath($file)), $file);
        }

        try {
            $response = $request->post($transport->endpoint('api/ingest/apps'), array_filter([
                'path' => $this->option('path') ?: $transport->path(),
                'user' => $this->systemUser(),
                'git_ref' => $release->gitRef(),
                'git_branch' => $release->gitBranch(),
                'about' => $this->about(),
            ], fn (?string $value): bool => $value !== null && $value !== ''));
        } catch (Throwable $exception) {
            $this->components->error('Could not reach Moonwatch: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error(sprintf('Moonwatch answered %d: %s', $response->status(), $response->json('message') ?? $response->body()));

            return self::FAILURE;
        }

        $this->components->info('Uploaded '.implode(', ', $files).' to Moonwatch.');

        return self::SUCCESS;
    }

    protected function about(): ?string
    {
        try {
            Artisan::call('about', ['--json' => true]);

            return trim(Artisan::output()) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function systemUser(): ?string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && isset($user['name'])) {
                return $user['name'];
            }
        }

        return get_current_user() ?: null;
    }
}
