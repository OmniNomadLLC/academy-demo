<?php

namespace App\Providers;

use App\Domain\Calendar\AcuityTimezoneResolver;
use App\Domain\Calendar\TeacherCalendarEventFactory;
use DateTimeZone;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AcuityTimezoneResolver::class, function () {
            $tz = config('services.acuity.timezone') ?? config('app.timezone') ?? 'UTC';

            try {
                $timezone = new DateTimeZone($tz);
            } catch (\Throwable $e) {
                $timezone = new DateTimeZone('UTC');
            }

            return new AcuityTimezoneResolver($timezone);
        });

        $this->app->singleton(TeacherCalendarEventFactory::class, function ($app) {
            return new TeacherCalendarEventFactory($app->make(AcuityTimezoneResolver::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerSqliteJsonCompat();

        // The container's FrankenPHP does not trust the outer reverse proxy,
        // so X-Forwarded-Proto arrives as http and asset URLs break under TLS.
        // APP_URL is the source of truth for the public scheme.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;

            if (! $user || ! method_exists($user, 'forceFill')) {
                return;
            }

            $user->forceFill([
                'last_login_at' => now(),
            ])->saveQuietly();
        });
    }

    /**
     * Many raw queries in this codebase use MySQL's JSON_UNQUOTE(). SQLite has
     * native JSON_EXTRACT() but no JSON_UNQUOTE(), so register a PHP-backed
     * equivalent on the connection. SQLite's json_extract() already returns
     * unquoted scalars, so this only needs to strip quotes from values that
     * arrive as JSON-encoded strings.
     */
    protected function registerSqliteJsonCompat(): void
    {
        // Register on every (re)connect: commands like migrate:fresh tear the
        // PDO down and reconnect, which would silently drop a one-time
        // registration.
        Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class, function ($event): void {
            $connection = $event->connection;

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $pdo = $connection->getPdo();

            if (! method_exists($pdo, 'sqliteCreateFunction')) {
                return;
            }

            $pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($value) {
                if (is_string($value) && strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                    $decoded = json_decode($value);

                    return is_string($decoded) ? $decoded : $value;
                }

                return $value;
            }, 1);
        });

        // Cover the connection that may already exist before this listener ran.
        if (config('database.default') === 'sqlite') {
            try {
                $pdo = DB::connection()->getPdo();
                if (method_exists($pdo, 'sqliteCreateFunction')) {
                    $pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($value) {
                        if (is_string($value) && strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                            $decoded = json_decode($value);

                            return is_string($decoded) ? $decoded : $value;
                        }

                        return $value;
                    }, 1);
                }
            } catch (\Throwable $e) {
                // Connection not available yet; the listener will handle it.
            }
        }
    }
}
