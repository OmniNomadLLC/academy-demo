@extends('emails.layouts.base')

@php
    $participant = strtoupper((string)($student['name'] ?? 'the student'));
    $className = $session->class_name ?? 'ESOL English Class';
    $preheaderText = $participant.' missed today’s '.$className.' session.';
@endphp

@section('title', 'Participant absence notification')
@section('preheader', $preheaderText)
@section('heading', 'Participant absence notification')

@section('content')
    <p>Hello,</p>
    <p>This is to inform you that your participant <strong style="color:#e53935;">{{ $participant }}</strong> was marked absent for today’s session: <strong>{{ $className }}</strong>.</p>
    <p>Please confirm whether there has been a change of circumstances or if the participant will attend their next class.</p>
    <p style="font-weight:700;">Please note that after three missed classes the participant will be referred back to the Harbour.</p>
    <p>Many thanks,<br>Lumina Admin</p>
@endsection
