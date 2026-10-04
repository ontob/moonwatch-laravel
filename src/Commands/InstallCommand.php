<?php

namespace Ontob\Moonwatch\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Sets the package up in an application: publishes the config, adds the
 * environment variables to .env and .env.example, and adds the moonwatch
 * channel to the stack log channel in config/logging.php.
 */
class InstallCommand extends Command
{
    protected $signature = 'moonwatch:install {--force : Overwrite the published config}';

    protected $description = 'Publish the Moonwatch config and add the moonwatch channel to the log stack';

    /**
     * @var array<string, string>
     */
    public const ENV_PLACEHOLDERS = [
        'MOONWATCH_URL' => '',
        'MOONWATCH_TOKEN' => '',
        'MOONWATCH_LEVEL' => 'error',
    ];

    public function handle(Filesystem $files): int
    {
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'moonwatch-config',
            '--force' => $this->option('force'),
        ]));
        $this->components->info('Published config/moonwatch.php.');

        foreach (['.env', '.env.example'] as $name) {
            $path = $this->laravel->basePath($name);

            if (! $files->exists($path)) {
                continue;
            }

            $contents = $files->get($path);
            $updated = static::addEnvPlaceholders($contents);

            if ($updated !== $contents) {
                $files->put($path, $updated);
                $this->components->info("Added the Moonwatch variables to {$name}.");
            }
        }

        $this->addToLogStack($files);

        if ($this->laravel['config']->get('logging.default') !== 'stack') {
            $this->components->warn('LOG_CHANNEL is not "stack", so the moonwatch channel only receives reported exceptions. Set LOG_CHANNEL=stack to send log entries too.');
        }

        $this->newLine();
        $this->line('  Set MOONWATCH_URL and MOONWATCH_TOKEN (the token of the server this site runs on), then run <comment>php artisan moonwatch:test</comment>.');

        return self::SUCCESS;
    }

    protected function addToLogStack(Filesystem $files): void
    {
        $path = $this->laravel->configPath('logging.php');

        if (! $files->exists($path)) {
            $this->components->warn('There is no config/logging.php. Publish it with `php artisan config:publish logging` and add "moonwatch" to the channels of the stack channel.');

            return;
        }

        $contents = $files->get($path);
        $updated = static::addToStack($contents);

        if ($updated === null) {
            $this->components->warn('Could not find the channels of the stack channel in config/logging.php. Add "moonwatch" to them by hand.');

            return;
        }

        if ($updated !== $contents) {
            $files->put($path, $updated);
            $this->components->info('Added the moonwatch channel to the stack channel in config/logging.php.');
        }
    }

    /**
     * Add the placeholders missing from the contents of an env file.
     */
    public static function addEnvPlaceholders(string $contents): string
    {
        $missing = [];

        foreach (self::ENV_PLACEHOLDERS as $key => $value) {
            if (! preg_match('/^\s*'.preg_quote($key, '/').'\s*=/m', $contents)) {
                $missing[] = "{$key}={$value}";
            }
        }

        if ($missing === []) {
            return $contents;
        }

        return rtrim($contents)."\n\n".implode("\n", $missing)."\n";
    }

    /**
     * Add "moonwatch" to the channels of the stack channel, or return null
     * when the config does not have the shape this knows. Both the shape
     * Laravel 11 and newer ship, reading LOG_STACK, and a plain list are
     * handled; contents that already have it come back unchanged.
     */
    public static function addToStack(string $contents): ?string
    {
        if (! preg_match("/(['\"])stack\\1\s*=>\s*\[(?<body>.*?)\n\s*\],/s", $contents, $stack, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $body = $stack['body'][0];
        $offset = $stack['body'][1];

        if (preg_match("/(['\"])moonwatch\\1/", $body)) {
            return $contents;
        }

        $patterns = [
            // 'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            "/((['\"])channels\\2\s*=>\s*)(explode\(.*?\)\)),/" => fn (array $m): string => $m[1].'array_unique([...'.$m[3].", 'moonwatch']),",
            // 'channels' => ['single', 'slack'],
            "/((['\"])channels\\2\s*=>\s*\[)(.*?)(\])/s" => fn (array $m): string => $m[1].(trim($m[3]) === '' ? "'moonwatch'" : rtrim($m[3], ", \n\t").", 'moonwatch'").$m[4],
        ];

        foreach ($patterns as $pattern => $replace) {
            $updated = preg_replace_callback($pattern, $replace, $body, 1, $count);

            if ($count === 1 && $updated !== null) {
                return substr_replace($contents, $updated, $offset, strlen($body));
            }
        }

        return null;
    }
}
