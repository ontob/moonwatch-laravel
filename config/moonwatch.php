<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    |
    | Nothing is sent unless reporting is enabled and both the Moonwatch URL
    | and the server token are set. The token is the one of the server the
    | site runs on, the same one its deploy hook uses. Nothing is ever sent
    | while the application runs its tests.
    |
    */

    'enabled' => (bool) env('MOONWATCH_ENABLED', true),

    'url' => env('MOONWATCH_URL'),

    'token' => env('MOONWATCH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Application
    |--------------------------------------------------------------------------
    |
    | Moonwatch identifies the application by the server token and the path
    | it lives in. The base path works for zero-downtime deploys too: a
    | release directory and the site root resolve to the same application.
    | The name is only used when Moonwatch sees the application first.
    |
    */

    'path' => env('MOONWATCH_PATH'),

    'name' => env('MOONWATCH_NAME'),

    /*
    |--------------------------------------------------------------------------
    | What is sent
    |--------------------------------------------------------------------------
    |
    | The minimum level a log entry needs to be sent through the "moonwatch"
    | log channel: debug, info, notice, warning, error, critical, alert or
    | emergency. Reported exceptions are always sent at the error level,
    | unless they are an instance of a class in the ignore list (a comma
    | separated list of class names in MOONWATCH_IGNORE).
    |
    */

    'level' => env('MOONWATCH_LEVEL', 'error'),

    'ignore' => array_values(array_filter(array_map('trim', explode(',', (string) env('MOONWATCH_IGNORE', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | Each event carries the URL without its query string, the method, the
    | route, the id of the signed in user and the client IP. Request input,
    | headers and cookies are never sent.
    |
    */

    'capture' => [
        'user' => (bool) env('MOONWATCH_CAPTURE_USER', true),
        'ip' => (bool) env('MOONWATCH_CAPTURE_IP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | The release the events belong to. When empty, the commit checked out
    | in the application's .git directory is used.
    |
    */

    'release' => env('MOONWATCH_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Events are collected in memory and posted once, after the response is
    | sent, after each queued job or when a command ends. A failed post is
    | dropped silently. At most max_events are kept per request or job.
    |
    */

    'timeout' => (float) env('MOONWATCH_TIMEOUT', 2),

    'max_events' => 100,

    /*
    |--------------------------------------------------------------------------
    | Deploy hook
    |--------------------------------------------------------------------------
    |
    | The moonwatch:deploy command uploads composer.json, the lock files and
    | `php artisan about` like the curl snippet from the Setup tab does. It
    | is off unless enabled here.
    |
    */

    'deploy' => [
        'enabled' => (bool) env('MOONWATCH_DEPLOY_ENABLED', false),
    ],

];
