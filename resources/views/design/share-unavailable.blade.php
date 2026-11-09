<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="color-scheme" content="light dark">
    <title>Link not available | Dot.Design</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    @vite(['resources/css/pages/share.css'])
</head>
<body class="sv sv--gone">
    {{-- One page for every link that cannot be opened (unknown, malformed, expired, turned off) and for the rate limit: nothing here can tell them apart. --}}
    <main class="sv-gone">
        <span class="sv-brand"><span class="sv-mark" role="img" aria-label="Dot.Design mark"></span><span class="sv-brand__name">Dot.Design</span></span>
        <h1>This link is not available</h1>
        <p>It may have expired, or the person who shared it turned it off. Ask them to send you a new one. If you have opened it many times in a short while, wait a minute and try again.</p>
    </main>
</body>
</html>
