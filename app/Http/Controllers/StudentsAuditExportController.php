<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentsAuditExportController extends Controller
{
    public function latestZip(): BinaryFileResponse
    {
        // Behind ['web','auth'] only. This streams a full student-audit archive, so
        // restrict it to admin/super_admin rather than any authenticated user.
        $user = auth()->user();
        abort_unless($user && $user->hasRole('super_admin', 'admin'), 403);

        $base = storage_path('app');
        $dirs = array_filter(glob($base.'/students_audit-*'), 'is_dir');
        if (empty($dirs)) {
            abort(404, 'No students audit exports found');
        }
        // Sort by mtime desc
        usort($dirs, fn($a,$b) => filemtime($b) <=> filemtime($a));
        $latest = $dirs[0];
        $zipPath = storage_path('app/'.basename($latest).'.zip');

        // Build zip
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip');
        }
        $files = glob($latest.'/*');
        foreach ($files as $f) {
            if (is_file($f)) {
                $zip->addFile($f, basename($f));
            }
        }
        $zip->close();

        $downloadName = basename($latest).'.zip';
        return response()->download($zipPath, $downloadName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}

