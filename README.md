# Moonwatch for Laravel

Reports a Laravel application's errors and log entries to Moonwatch, where they are grouped into issues and alert the channels of the server's group.

> This package is public only so it can be installed from Packagist without credentials. It is built for our own Moonwatch instance and our own applications, it is useless without a Moonwatch server, and it comes without support. Issues and pull requests from outside are not expected.

Laravel 11 or newer, PHP 8.2 or newer. No queue worker is needed: events are posted directly once the response has been sent.

## Installation

```
composer require ontob/moonwatch-laravel
php artisan moonwatch:install
```

`moonwatch:install` publishes `config/moonwatch.php`, adds the variables below to `.env` and `.env.example`, and adds the `moonwatch` channel to the `stack` channel in `config/logging.php`. Commit the changed files.

On each server, set in `.env`:

```
MOONWATCH_URL=https://moonwatch.example.com
MOONWATCH_TOKEN=     # the token of the server, as in the deploy hook
MOONWATCH_LEVEL=error
```

and check with `php artisan moonwatch:test`.

## What is sent

- **Reported exceptions**, always, at the `error` level: everything Laravel reports, so not the exceptions in its `dontReport` list (404, validation, authentication…).
- **Log entries** at or above `MOONWATCH_LEVEL` written to a channel that includes `moonwatch`. `LOG_CHANNEL` has to be `stack` for that.

Each event carries the URL without its query string, the method, the route, the id of the signed in user, the client IP, the release (the checked out commit), the environment and the host name. Request input, headers and cookies are never sent. An exception thrown inside a vendor package points to the first application file that called it, so it is grouped by your code.

Events are kept in memory and posted at the end of the request, after each queued job and when a command ends, in batches of 50. A failed post is dropped and never retried or logged. At most 100 events are kept per request or job.

## Configuration

| Variable | Default | |
|---|---|---|
| `MOONWATCH_ENABLED` | `true` | `false` turns everything off |
| `MOONWATCH_URL` | | Moonwatch URL; nothing is sent without it |
| `MOONWATCH_TOKEN` | | Server token; nothing is sent without it |
| `MOONWATCH_LEVEL` | `error` | Minimum log level sent: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` |
| `MOONWATCH_IGNORE` | | Comma separated exception classes never sent, subclasses included |
| `MOONWATCH_CAPTURE_USER` | `true` | Send the id of the signed in user |
| `MOONWATCH_CAPTURE_IP` | `true` | Send the client IP |
| `MOONWATCH_RELEASE` | commit in `.git` | Release the events belong to |
| `MOONWATCH_PATH` | `base_path()` | Path Moonwatch knows the application by |
| `MOONWATCH_NAME` | `APP_NAME` | Name for an application Moonwatch has not seen yet |
| `MOONWATCH_TIMEOUT` | `2` | Seconds per post |
| `MOONWATCH_DEPLOY_ENABLED` | `false` | Allow `moonwatch:deploy` |

Nothing is ever sent while the application runs its tests (`APP_ENV=testing`).

## Deploy hook

`php artisan moonwatch:deploy` uploads `composer.json`, `composer.lock`, `package-lock.json` and `php artisan about` for the dependency audit, like the curl snippet on the application's Setup tab. It is off unless `MOONWATCH_DEPLOY_ENABLED=true`. Append `|| true` in the deploy script so a Moonwatch problem does not fail the deploy:

```
php artisan moonwatch:deploy || true
```

## Development

```
composer install
composer test
composer format
```

A release is a git tag pushed to GitHub (`git tag v0.2.0 && git push origin v0.2.0`). Packagist picks it up through the GitHub hook, and `composer.lock` in the applications pins it.
