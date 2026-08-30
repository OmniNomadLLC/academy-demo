@php($timestamp = now()->toDateTimeString())
<p>Heads up — the scheduled database dump smoke test failed at {{ $timestamp }}.</p>

<p><strong>Message:</strong></p>
<pre style="font-family: monospace; white-space: pre-wrap;">{{ $exception->getMessage() }}</pre>

@if($trace = $exception->getTraceAsString())
<p><strong>Trace:</strong></p>
<pre style="font-family: monospace; white-space: pre-wrap;">{{ $trace }}</pre>
@endif

<p>Please verify the mysqldump credentials/host and rerun <code>php artisan db:verify</code>.</p>
