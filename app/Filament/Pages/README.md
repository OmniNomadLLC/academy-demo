# Control Panel Notes

This directory holds the Filament page responsible for the Lumina Control Panel.

- **Queue status logic** lives in `App\Support\QueueStatusResolver`. The page resolves it through `ControlPanel::queueStatus()` which caches a single request cycle. The helper pings Redis, checks the configured driver, inspects Horizon status, and exposes supervisor metadata (configured min/max processes, active worker count) for the banner and Queue Health card.
- **Staging guide content** is rendered directly in `resources/views/filament/pages/control-panel.blade.php` inside the collapsible “How to load Acuity data (staging)” panel. Screenshot placeholders and the Loom script both come from helper methods (`commandsForGuide()` / `loomScriptLines()`). The guide includes a “Horizon quick fix” checklist—update it whenever the recovery process changes.
- **Grouping principle:** every actionable command appears once. Sync Tools encapsulate delta / appointments / clients, Backfills includes only data backfill buttons, Audit & Reconcile owns all audit actions, Queue Health centralises queue maintenance (restart, retry, flush, clear), Webhook Health owns webhook replays, and Recent Sync Logs is the single surface for log output.

When adding new actions, pick the appropriate group, reuse the `dispatchArtisan()` helper for consistent logging/toasts, and update the staging guide if the operator flow changes.

## Horizon quick fix summary

- Ensure each environment defines `minProcesses` / `maxProcesses` for `supervisor-acuity` in `config/horizon.php` (production 2→16, staging 1→8, local 1→2).
- After changing configuration or queue drivers, run:
  - `php artisan config:clear`
  - `php artisan cache:clear`
  - `php artisan config:cache`
  - `php artisan horizon:terminate`
- Verify a daemon (systemd/Supervisor/Forge) is running `php artisan horizon`.

## After-deploy checklist

- Refresh configuration caches (see list above).
- Terminate Horizon so the daemon restarts with the latest supervisor settings.
- Open the Control Panel → Queue Health to confirm active processes > 0 and queues are draining.
