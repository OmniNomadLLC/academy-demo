@extends('emails.layouts.base')

@php
    $rate = number_format($student->attendance_rate ?? 0, 2);
    $preheaderText = $student->full_name.' attendance at '.$rate.'%';
    $manager = $student->manager;
    $attendanceStats = $student->attendanceRecords()
        ->selectRaw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present")
        ->selectRaw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late")
        ->selectRaw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent")
        ->selectRaw('COUNT(*) as total')
        ->first();
    $presentCount = (int) ($attendanceStats->present ?? 0);
    $lateCount = (int) ($attendanceStats->late ?? 0);
    $absentCount = (int) ($attendanceStats->absent ?? 0);
    $totalSessions = (int) ($attendanceStats->total ?? 0);
    $lastAbsence = $student->attendanceRecords()
        ->where('status', 'absent')
        ->latest('marked_at')
        ->with('classSession')
        ->first();
    $session = $lastAbsence?->classSession;
@endphp

@section('title', 'Low attendance alert')
@section('preheader', $preheaderText)
@section('heading', 'Low attendance alert')

@section('content')
    <p>Hey Darling,</p>
    <p>Student <strong>{{ $student->full_name }}</strong> currently has an attendance rate of <strong>{{ $rate }}%</strong>.</p>
    @if($session)
        <p>The most recent absence was <strong>{{ $session->calendar_name ?? 'ESOL class' }}</strong> on <strong>{{ optional($session->session_date)->format('l, d M Y') }}</strong> starting at <strong>{{ optional($session->start_time)->format('H:i') }}</strong>.</p>
    @endif
    <p>Please review their recent attendance history and follow up.</p>
    <p>
        <strong>Attendance breakdown</strong><br>
        Present: {{ $presentCount }} · Late: {{ $lateCount }} · Absent: {{ $absentCount }} · Sessions tracked: {{ $totalSessions }}
    </p>
    <p>
        <strong>Student contact</strong><br>
        Email: {{ $student->email ?? '—' }}<br>
        Phone: {{ $student->phone ?? '—' }}
    </p>
    @if($manager)
        <p>
            <strong>Assigned manager</strong><br>
            {{ $manager->name ?? 'Manager' }} — {{ $manager->email ?? '—' }}
        </p>
    @endif
    <p>Lots of Love 🌹,<br>Your Nerdy Dev 😘</p>
@endsection
