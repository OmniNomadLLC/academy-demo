<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Confirm outcome — Lumina </title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f6f4f1; color: #1f2937; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 24px 16px; }
        .brand { color: #8f1d21; font-weight: 800; font-size: 1.1rem; }
        .card { background: #fff; border-radius: 14px; padding: 22px; margin-top: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 1.15rem; margin: 0 0 4px; }
        .meta { color: #6b7280; font-size: .9rem; margin-bottom: 16px; }
        label { display: block; font-weight: 600; font-size: .9rem; margin: 14px 0 6px; }
        .options label { display: flex; gap: 10px; align-items: center; font-weight: 500; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; margin-bottom: 8px; cursor: pointer; }
        input[type=text], textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px; font: inherit; }
        button { margin-top: 18px; width: 100%; background: #8f1d21; color: #fff; border: 0; border-radius: 10px; padding: 13px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .done { text-align: center; padding: 30px 10px; }
        .done .big { font-size: 2.4rem; }
        .err { color: #b91c1c; font-size: .85rem; }
        footer { text-align: center; color: #9ca3af; font-size: .75rem; padding: 22px 0; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">Lumina Language Academy</div>
    <div class="card">
        @if ($done)
            <div class="done">
                <div class="big">✅</div>
                <h1>Thank you</h1>
                <p class="meta">Your confirmation ({{ str_replace('_', ' ', $verification->result) }}) has been recorded on {{ $verification->confirmed_at->format('d M Y, H:i') }}.<br>You can close this page.</p>
            </div>
        @else
            <h1>Confirm interview / hire outcome</h1>
            <p class="meta">
                Role: <strong>{{ $application->job->title }}</strong><br>
                Candidate: <strong>{{ $application->student->first_name }}</strong>
                @if ($application->interview_at) · interview {{ $application->interview_at->format('d M Y') }} @endif
            </p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <label>What happened?</label>
                <div class="options">
                    <label><input type="radio" name="result" value="attended" required> Attended the interview</label>
                    <label><input type="radio" name="result" value="no_show"> Did not attend (no-show)</label>
                    <label><input type="radio" name="result" value="hired"> Hired</label>
                    <label><input type="radio" name="result" value="not_hired"> Not hired</label>
                </div>
                @error('result')<p class="err">{{ $message }}</p>@enderror

                <label for="contact_name">Your name</label>
                <input type="text" id="contact_name" name="contact_name" value="{{ old('contact_name') }}" required>
                @error('contact_name')<p class="err">{{ $message }}</p>@enderror

                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="3">{{ old('notes') }}</textarea>

                <button type="submit">Confirm</button>
            </form>
            <p class="meta" style="margin-top:14px;">This link is for {{ $verification->employer_name }} only and can be used once. Your confirmation is stored as evidence with a timestamp.</p>
        @endif
    </div>
    <footer>Lumina Language Academy · This page collects only the outcome you confirm.</footer>
</div>
</body>
</html>
