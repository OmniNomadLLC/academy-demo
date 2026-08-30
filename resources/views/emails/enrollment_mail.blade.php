@extends('emails.layouts.base')

@section('title', 'Your ESOL enrolment details')
@section('preheader', $preheader ?? 'Your class starts soon')
@section('heading', 'Your ESOL enrolment details')

@section('content')
    {!! $bodyHtml !!}
@endsection

@section('meta')
    @isset($context['meeting_url'])
        <p><strong>Zoom link:</strong> <a href="{{ $context['meeting_url'] }}" style="color:#2563eb;">{{ $context['meeting_url'] }}</a></p>
    @endisset
    @isset($context['meeting_room'])
        <p><strong>Meeting ID:</strong> {{ $context['meeting_room'] }}</p>
    @endisset
    <p style="margin-top:18px;">If you have any questions, reply to this email or call the Lumina office.</p>
@endsection
