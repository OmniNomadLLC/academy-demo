<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ExportUpcomingController;
use App\Http\Controllers\Portal\CalendarEventsController;
use App\Http\Controllers\Portal\StudentAssessmentDownloadController;
use App\Http\Controllers\StudentAssessmentAttachmentDownloadController;
use App\Http\Controllers\StudentsAuditExportController;

// Root
Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/login', '/admin/login')->name('login');

// -----------------------------------------------------------------------------
// Removed relative to the original application (demo hardening):
//
// - POST /webhooks/acuity and /webhooks/twilio/* — the demo has no external
//   integrations, and the Acuity endpoint accepted unsigned payloads
//   (2026-07 security audit item 8).
// - The CSRF-exempt POST /admin/login and /portal/login fallbacks — they
//   bypassed CSRF and had no throttling (2026-07 security audit item 9).
//   The demo uses Filament's native Livewire login.
// - GET /regions/{region}/students — removed 2026-07-14: unauthenticated
//   full student listing. Do not reintroduce.
// -----------------------------------------------------------------------------

Route::middleware(['web', 'auth'])
    ->prefix('portal')
    ->group(function () {
        Route::get('/api/calendar/events', CalendarEventsController::class)
            ->name('portal.api.calendar.events');

        Route::get('/students/{student}/assessments/{assessment}/download', StudentAssessmentDownloadController::class)
            ->name('portal.assessments.download');
    });

// Admin exports: upcoming classes CSV
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/admin/exports/upcoming', [ExportUpcomingController::class, 'upcoming'])
        ->name('exports.upcoming');

    Route::get('/admin/exports/students-audit/latest', [StudentsAuditExportController::class, 'latestZip'])
        ->name('exports.students_audit.latest');

    Route::get('/admin/uk-reports', [\App\Http\Controllers\Admin\UkReportsController::class, 'index'])
        ->name('filament.admin.pages.uk-reports');

    Route::get('/admin/uk-reports/export', [\App\Http\Controllers\Admin\UkReportsController::class, 'exportCsv'])
        ->name('admin.uk-reports.export');

    Route::get('/admin/uk-reports/export/pdf', [\App\Http\Controllers\Admin\UkReportsController::class, 'exportPdf'])
        ->name('admin.uk-reports.export-pdf');

    Route::get('/assessments/{assessment}/attachment', StudentAssessmentAttachmentDownloadController::class)
        ->name('assessments.attachment.download');
});

// Lumina Works: signed student/employer pages (404 while the feature flag is
// off; routes stay registered because admin pages reference them by name).
Route::get('/luminaworks/coach/{student}', [\App\Http\Controllers\LuminaWorksCoachController::class, 'show'])
    ->middleware(['web', 'signed'])
    ->name('luminaworks.coach');

Route::get('/luminaworks/verify/{verification}', [\App\Http\Controllers\LuminaWorksEmployerController::class, 'show'])
    ->middleware(['web', 'signed'])
    ->name('luminaworks.employer-verify');
Route::post('/luminaworks/verify/{verification}', [\App\Http\Controllers\LuminaWorksEmployerController::class, 'store'])
    ->middleware(['web', 'signed'])
    ->name('luminaworks.employer-verify.store');
