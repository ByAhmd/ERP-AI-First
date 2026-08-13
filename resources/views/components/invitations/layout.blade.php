{{--
    Standalone layout for invitation acceptance.

    The recipient has no session and cannot reach the panel, so this page cannot
    reuse Filament's shell. Styles are inlined rather than built through Vite:
    this is two static pages, and making them depend on an asset pipeline would
    mean a Node build step for the whole deployment.

    Direction comes from the same translation key Filament uses, so the page
    flips with the rest of the application rather than drifting from it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ __('filament-panels::layout.direction') ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('identity.invitations.accept.title') }}</title>
    <style>
        :root {
            --bg: #f4f4f5;
            --card: #ffffff;
            --text: #18181b;
            --muted: #71717a;
            --border: #e4e4e7;
            --accent: #059669;
            --danger: #b91c1c;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #18181b;
                --card: #27272a;
                --text: #fafafa;
                --muted: #a1a1aa;
                --border: #3f3f46;
            }
        }

        * { box-sizing: border-box; }

        body.invitation {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            line-height: 1.6;
        }

        .invitation__card {
            width: 100%;
            max-width: 26rem;
            padding: 2rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
        }

        .invitation__heading {
            margin: 0 0 0.25rem;
            font-size: 1.375rem;
            font-weight: 600;
        }

        .invitation__identity,
        .invitation__body {
            margin: 0 0 1.5rem;
            color: var(--muted);
            font-size: 0.9375rem;
        }

        .invitation__errors {
            margin: 0 0 1.25rem;
            padding: 0.75rem 1.25rem;
            list-style-position: inside;
            border: 1px solid var(--danger);
            border-radius: 0.5rem;
            color: var(--danger);
            font-size: 0.875rem;
        }

        .invitation__label {
            display: block;
            margin-bottom: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .invitation__input {
            width: 100%;
            margin-bottom: 1.25rem;
            padding: 0.625rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--card);
            color: var(--text);
            font: inherit;
            font-size: 0.9375rem;
        }

        .invitation__input:focus {
            outline: 2px solid var(--accent);
            outline-offset: 1px;
        }

        .invitation__submit {
            width: 100%;
            padding: 0.6875rem 1rem;
            border: 0;
            border-radius: 0.5rem;
            background: var(--accent);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .invitation__submit:hover { filter: brightness(0.94); }
    </style>
</head>
<body class="invitation">
    <main class="invitation__card">
        {{ $slot }}
    </main>
</body>
</html>
