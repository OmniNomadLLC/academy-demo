@extends('emails.layouts.base')

@php
    $allowedTags = '<p><br><strong><em><u><ol><ul><li><blockquote><a><span><b><i><strike><div>';
    $bodyHtml = strip_tags($bodyText, $allowedTags);
    $bodyHtml = str_ireplace(['<div>', '</div>'], ['<p>', '</p>'], $bodyHtml);
    $preheaderText = \Illuminate\Support\Str::limit(strip_tags($bodyHtml), 120, '…');
@endphp

@section('title', $subjectText)
@section('preheader', $preheaderText)
@section('heading', $subjectText)

@section('content')
    {!! $bodyHtml !!}
    <p>Many thanks,<br>Lumina Language Academy</p>
@endsection
