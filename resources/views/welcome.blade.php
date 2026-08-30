<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Lumina Language Academy · An OmniNomad showcase</title>
        <link rel="icon" href="{{ asset('logo-demo.svg') }}" type="image/svg+xml">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=Hanken+Grotesk:wght@400;500;600;700&family=Spline+Sans+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <style>
            :root{
                --bg:#0A0F1C; --bg2:#0E1526; --panel:#101a30;
                --ink:#E9EDF4; --muted:#8B97AB; --dim:#586278;
                --line:rgba(91,200,220,.16);
                --amber:#F4B740; --amber2:#E0962B; --cyan:#5BC8DC;
            }
            *, *::before, *::after { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                font-family: 'Hanken Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                color: var(--ink);
                background:
                    radial-gradient(900px 500px at 80% -10%, rgba(91,200,220,.10), transparent 60%),
                    radial-gradient(700px 480px at 8% 108%, rgba(244,183,64,.09), transparent 55%),
                    var(--bg);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem;
            }
            .shell {
                width: min(980px, 100%);
                position: relative;
            }
            .brandline {
                display: flex;
                align-items: center;
                gap: 11px;
                margin-bottom: 2.2rem;
            }
            .glyph {
                width: 30px; height: 30px;
                border: 1.3px solid var(--amber);
                display: flex; align-items: center; justify-content: center;
                font-size: 12px; font-weight: 800; color: var(--amber);
                border-radius: 6px;
                box-shadow: 0 0 16px rgba(244,183,64,.28), inset 0 0 10px rgba(244,183,64,.08);
            }
            .brandname {
                font-family: 'Bricolage Grotesque', sans-serif;
                font-weight: 800; font-size: 17px; letter-spacing: -.01em;
            }
            .kicker {
                margin-left: auto;
                font-family: 'Spline Sans Mono', monospace;
                font-size: 11px; font-weight: 600;
                letter-spacing: .16em; text-transform: uppercase;
                color: var(--cyan);
            }
            h1 {
                font-family: 'Bricolage Grotesque', sans-serif;
                font-weight: 800;
                font-size: clamp(2.1rem, 4.6vw, 3.3rem);
                line-height: 1.05;
                letter-spacing: -.015em;
                margin: 0 0 .9rem;
            }
            h1 .accent { color: var(--amber); }
            p.lede {
                margin: 0 0 2rem;
                font-size: clamp(1rem, 2.2vw, 1.1rem);
                color: var(--muted);
                max-width: 56ch;
                line-height: 1.6;
            }
            .demo-note {
                background: var(--panel);
                border: 1px solid var(--line);
                border-radius: 14px;
                padding: 1rem 1.3rem;
                margin: 0 0 2rem;
                font-size: .95rem;
                color: var(--muted);
                line-height: 1.65;
            }
            .demo-note strong { color: var(--amber); font-weight: 700; }
            .demo-note code {
                font-family: 'Spline Sans Mono', monospace;
                font-size: .82em;
                color: var(--cyan);
                background: rgba(91,200,220,.08);
                border: 1px solid var(--line);
                border-radius: 6px;
                padding: .12rem .45rem;
                white-space: nowrap;
            }
            .tiles {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 1.4rem;
            }
            .tile {
                background: var(--bg2);
                border: 1px solid var(--line);
                border-radius: 14px;
                padding: 1.8rem 1.7rem;
                display: flex;
                flex-direction: column;
                gap: .85rem;
                transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
            }
            .tile:hover {
                transform: translateY(-6px);
                border-color: rgba(91,200,220,.4);
                box-shadow: 0 18px 44px rgba(0,0,0,.45), 0 0 24px rgba(91,200,220,.12);
            }
            .tile .lbl {
                font-family: 'Spline Sans Mono', monospace;
                font-size: 10.5px; font-weight: 600;
                letter-spacing: .14em; text-transform: uppercase;
                color: var(--cyan);
            }
            .tile h2 {
                font-family: 'Bricolage Grotesque', sans-serif;
                margin: 0;
                font-size: 1.35rem;
                font-weight: 700;
                letter-spacing: -.01em;
            }
            .tile p {
                margin: 0;
                color: var(--muted);
                line-height: 1.6;
                font-size: .95rem;
            }
            .tile a {
                margin-top: auto;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 11px 20px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 13.5px;
                text-decoration: none;
                background: var(--amber);
                color: #12161F;
                transition: transform .2s, box-shadow .2s, background .2s;
            }
            .tile a:hover {
                background: var(--amber2);
                transform: translateY(-2px);
                box-shadow: 0 10px 26px rgba(244,183,64,.28);
            }
            .credit {
                margin-top: 2.6rem;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: .5rem;
                font-family: 'Spline Sans Mono', monospace;
                font-size: .72rem;
                letter-spacing: .1em;
                text-transform: uppercase;
                color: var(--dim);
            }
            .credit a {
                color: var(--muted);
                font-weight: 600;
                text-decoration: none;
                border-bottom: 1px solid transparent;
                transition: color .2s, border-color .2s;
            }
            .credit a:hover { color: var(--cyan); border-color: var(--line); }
            @media (max-width: 640px) {
                body { padding: 1.25rem; }
                .kicker { display: none; }
            }
        </style>
    </head>
    <body>
        <main class="shell" role="main">
            <div class="brandline">
                <span class="glyph">ON</span>
                <span class="brandname">OmniNomad</span>
                <span class="kicker">Showcase // Lumina Language Academy</span>
            </div>

            <h1>A language school,<br>run from <span class="accent">one platform</span>.</h1>
            <p class="lede">Lumina Language Academy is a fictional school on a real system: enrolment,
                attendance with risk meters, skill tracking, reporting and an ops control panel,
                built end to end on Laravel and Filament.</p>

            @if (config('app.demo_mode'))
                <div class="demo-note" role="note">
                    <strong>Public demo.</strong> Every student, class and metric is generated sample data, and the
                    database resets daily. Sign in with <code>demo@lumina.academy</code> (admin) or
                    <code>teacher@lumina.academy</code> (teacher portal), password <code>lumina-demo</code>.
                </div>
            @endif

            <section class="tiles" aria-label="Choose a portal">
                <article class="tile">
                    <span class="lbl">Ops &amp; management</span>
                    <h2>Admin Console</h2>
                    <p>Dashboards, reports, student outreach, and ops tooling across
                        all regions, including the Control Panel.</p>
                    <a href="{{ url('/admin/login') }}">
                        Go to admin login →
                    </a>
                </article>
                <article class="tile">
                    <span class="lbl">Day-to-day teaching</span>
                    <h2>Teacher Portal</h2>
                    <p>Take attendance, review rosters, and follow up with your students inside the
                        dedicated teacher experience.</p>
                    <a href="{{ url('/portal/login') }}">
                        Go to teacher login →
                    </a>
                </article>
            </section>

            <p class="credit">
                Built end to end by
                <a href="https://omninomad-llc.com" target="_blank" rel="noopener">OmniNomad</a>
            </p>
        </main>
    </body>
</html>
