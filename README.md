# Lumina Language Academy

A complete school management platform for a fictional language academy, built end to end
on Laravel and Filament, and published as a live, interactive showcase by
[OmniNomad](https://omninomad-llc.com).

**Live demo:** https://demo.omninomad-llc.com

Sign in with `demo@lumina.academy` (admin) or `teacher@lumina.academy` (teacher portal),
password `lumina-demo`. Every student, class and metric is generated sample data
(a deterministic Faker seeder), and the database resets daily. Nothing in this
repository or the demo refers to a real person.

## What it does

- **Admin console** (Filament): multi-region dashboards, student management with
  attendance risk meters (<75% flags red), skill profiles, reports and exports,
  student outreach tooling.
- **Teacher portal**: a separate panel with day-to-day teaching flows, such as rosters,
  a weekly calendar, and one-tap attendance taking.
- **Ops control panel**: scheduling-sync tooling (imports, backfills, audits), queue and
  worker health, sync logs with captured command output.
- **Demo machinery**: `DemoSeeder` generates the whole dataset from a fixed seed;
  `php artisan demo:reset` rebuilds it (and refuses to run outside demo mode);
  `DEMO_MODE=true` adds the demo banner, login hints and hides outbound actions.

## Stack

Laravel 12, Filament 3, Livewire, SQLite, FrankenPHP in Docker, Caddy in front.
The whole demo runs from one container with a throwaway database.

## Running locally

```bash
composer install
npm install && npm run build
cp .env.example .env   # set DEMO_MODE=true and an sqlite DB path
php artisan key:generate
php artisan demo:reset
php artisan serve
```

Test suite: `php artisan test` (47 tests).

## License

Copyright OmniNomad LLC. Published for review and demonstration purposes;
no license is granted for reuse of the code.
