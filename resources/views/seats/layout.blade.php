<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title')</title>
    <style>
        /* Kein Build-Schritt, keine Asset-Pipeline: diese Seite wird aus einer
           Mail heraus geoeffnet und muss mit dem ersten Byte stehen.

           Die Farben sind die des Kundenportals von statamic-payments, Wert fuer
           Wert. Wer aus der Kaufbestaetigung ins Portal und von dort hierher
           kommt, soll nicht sehen, dass zwei Pakete die Seiten zeichnen.
           Abgeschrieben und nicht von dort geerbt: die Vorlage dort ist ein
           Innenteil von payments, kein Vertrag, und sie aendert sich ohne
           Ankuendigung. */
        body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181b; }
        .card { max-width: 560px; margin: 8vh auto; background: #fff; border-radius: 12px; padding: 40px 32px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        @@media (max-width: 600px) { .card { margin: 0; border-radius: 0; padding: 32px 16px; min-height: 100vh; box-sizing: border-box; } }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .lede { color: #52525b; line-height: 1.6; margin: 0 0 4px; font-size: 15px; }
        .muted { font-size: 14px; color: #71717a; margin: 0 0 4px; }
        .block { margin-top: 32px; }
        .block h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #71717a; margin: 0 0 4px; }
        .block .hint { font-size: 13px; color: #a1a1aa; line-height: 1.5; margin: 0 0 10px; }
        .list { list-style: none; margin: 0; padding: 0; border-top: 1px solid #e4e4e7; }
        .entry { border-bottom: 1px solid #e4e4e7; padding: 12px 2px; display: flex; gap: 12px; align-items: center; justify-content: space-between; }
        .entry .what { flex: 1; min-width: 0; }
        .name { font-size: 15px; color: #18181b; word-break: break-word; }
        .desc { display: block; font-size: 13px; line-height: 1.5; color: #71717a; margin: 4px 0 0; }
        .count { font-size: 28px; font-variant-numeric: tabular-nums; margin: 16px 0 0; }
        .notice { background: #f4f4f5; border-radius: 8px; padding: 10px 12px; font-size: 14px; color: #3f3f46; margin: 0 0 16px; line-height: 1.5; }
        .errors { margin: 0 0 16px; padding: 10px 12px; background: #fef2f2; border-radius: 8px; color: #b91c1c; font-size: 14px; line-height: 1.5; }
        .btn { margin-top: 22px; width: 100%; padding: 11px 16px; border: 0; border-radius: 8px; background: #18181b; color: #fff; font-size: 15px; cursor: pointer; font-family: inherit; text-align: center; text-decoration: none; display: block; box-sizing: border-box; }
        .btn:disabled { background: #a1a1aa; cursor: not-allowed; }
        .btn-quiet { margin: 0; width: auto; padding: 6px 12px; font-size: 13px; background: transparent; color: #b91c1c; border: 1px solid #e4e4e7; }
        input[type=email], input[type=text] { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #d4d4d8; border-radius: 8px; font-size: 15px; font-family: inherit; background: #fff; color: #18181b; }
        .field { margin-top: 16px; }
        .field label { display: block; font-size: 14px; color: #71717a; margin: 0 0 6px; }
        .field .error { font-size: 13px; color: #b91c1c; margin: 6px 0 0; }
        .badge { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 999px; background: #f4f4f5; color: #52525b; white-space: nowrap; }
        .badge.ok { background: #ecfdf5; color: #047857; }
        .foot { margin-top: 28px; font-size: 12px; color: #a1a1aa; line-height: 1.6; }
        a { color: #18181b; }
    </style>
</head>
<body>
    <main class="card">
        @if (session('statamic-offers.seats.status'))
            <p class="notice" role="status">{{ session('statamic-offers.seats.status') }}</p>
        @endif

        @yield('content')
    </main>
</body>
</html>
