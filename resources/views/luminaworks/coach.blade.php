<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>My Job Coach — Lumina</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f6f4f1; color: #1f2937; }
        .wrap { max-width: 680px; margin: 0 auto; padding: 16px; }
        header { background: #8f1d21; color: #fff; padding: 20px 16px; }
        header h1 { margin: 0; font-size: 1.3rem; }
        header p { margin: 6px 0 0; font-size: .9rem; opacity: .9; }
        .card { background: #fff; border-radius: 14px; padding: 16px; margin-top: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .job-title { font-weight: 700; font-size: 1.05rem; margin: 0; }
        .job-meta { color: #6b7280; font-size: .85rem; margin: 4px 0 10px; }
        .score { float: right; background: #e8f5ec; color: #1a7a3a; border-radius: 999px; padding: 2px 10px; font-size: .8rem; font-weight: 700; }
        a.apply { display: inline-block; background: #8f1d21; color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 10px; font-weight: 600; font-size: .95rem; }
        details { margin-top: 12px; border-top: 1px solid #eee; padding-top: 10px; }
        summary { cursor: pointer; font-weight: 600; color: #8f1d21; }
        h3 { font-size: .95rem; margin: 14px 0 6px; }
        ul { margin: 0; padding-left: 18px; }
        li { margin-bottom: 6px; font-size: .95rem; line-height: 1.45; }
        .term { font-weight: 700; }
        .phrase { background: #f3f7ff; border-radius: 8px; padding: 8px 10px; margin-bottom: 6px; font-size: .95rem; }
        .phrase .sit { display: block; color: #6b7280; font-size: .8rem; }
        .rights { background: #fff8e6; border-radius: 8px; padding: 10px; font-size: .9rem; margin-top: 10px; }
        footer { text-align: center; color: #9ca3af; font-size: .75rem; padding: 24px 0; }
    </style>
</head>
<body>
<header>
    <div class="wrap" style="padding:0;">
        <h1>My Job Coach</h1>
        <p>Hello {{ $student->first_name }}! Here are jobs near you. You apply yourself — tap "See job & apply".</p>
    </div>
</header>
<div class="wrap">
    @forelse ($matches as $match)
        <div class="card">
            <span class="score">{{ $match->score }}% match</span>
            <p class="job-title">{{ $match->job->title }}</p>
            <p class="job-meta">
                {{ $match->job->employer_name ?? 'Employer unknown' }} · {{ $match->job->location_name }}
                @if ($match->distance_km !== null) · {{ $match->distance_km }} km from you @endif
            </p>
            <a class="apply" href="{{ $match->job->apply_url }}" target="_blank" rel="noopener noreferrer">See job &amp; apply →</a>

            @if ($pack = $packs->get($match->lumina_works_job_id))
                <details>
                    <summary>📖 Words &amp; phrases for this job</summary>
                    <h3>Useful words</h3>
                    <ul>
                        @foreach ($pack->content['vocabulary'] ?? [] as $word)
                            <li><span class="term">{{ $word['term'] }}</span> — {{ $word['meaning'] }}</li>
                        @endforeach
                    </ul>
                    <h3>What you can say</h3>
                    @foreach ($pack->content['phrases'] ?? [] as $phrase)
                        <div class="phrase"><span class="sit">{{ $phrase['situation'] }}</span>"{{ $phrase['phrase'] }}"</div>
                    @endforeach
                    <h3>First day tips</h3>
                    <ul>
                        @foreach ($pack->content['first_day_tips'] ?? [] as $tip)
                            <li>{{ $tip }}</li>
                        @endforeach
                    </ul>
                    @if (!empty($pack->content['rights_note']))
                        <div class="rights">ℹ️ {{ $pack->content['rights_note'] }}</div>
                    @endif
                </details>
            @endif
        </div>
    @empty
        <div class="card"><p>No job matches yet. Your teacher will add them soon.</p></div>
    @endforelse
    <footer>Jobs by <a href="https://www.adzuna.co.uk" rel="noopener noreferrer">Adzuna</a> · Lumina Language Academy</footer>
</div>
</body>
</html>
