<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Notification')</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f7f7f9;
            color: #1f2933;
            font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
        }
        .preheader {
            display: none !important;
            visibility: hidden;
            opacity: 0;
            color: transparent;
            height: 0;
            width: 0;
            overflow: hidden;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f7f7f9;
            padding: 24px 0;
        }
        .container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }
        .content {
            padding: 32px;
            line-height: 1.6;
            font-size: 16px;
        }
        .content h1 {
            margin-top: 0;
            font-size: 24px;
            color: #0f172a;
        }
        .content p {
            margin: 0 0 16px 0;
        }
        .meta {
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #e1e7ef;
            font-size: 13px;
            color: #6b7280;
        }
        .meta p {
            margin-bottom: 6px;
        }
        @media screen and (max-width: 600px) {
            .content {
                padding: 24px;
            }
        }
    </style>
</head>
<body>
    @php
        $preheaderText = trim($__env->yieldContent('preheader')) ?: 'Notification from Lumina';
    @endphp
    <span class="preheader">{{ $preheaderText }}</span>

    <table role="presentation" class="wrapper" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" class="container" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="content">
                            <h1>@yield('heading', 'Notification')</h1>

                            @yield('content')

                            @hasSection('meta')
                                <div class="meta">
                                    @yield('meta')
                                </div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
