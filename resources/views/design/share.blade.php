@php
    $name = $document['project']['name'];
    $count = count($pages);
    $fonts = \App\Support\Design\ShareFonts::manifestFor($document);
    $sentence = $count.' '.($count === 1 ? 'page' : 'pages').'. Shared with you to look at'.($canComment ? ' and comment on' : '').'.';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light dark">
    <title>{{ $name }} | Dot.Design</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="preload" href="{{ asset('fonts/ui/atkinson-hyperlegible-next-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    @vite(['resources/css/pages/share.css', 'resources/js/studio/pages/share-viewer.js'])
</head>
<body class="sv"
      data-token-ok="1"
      data-comment-url="{{ route('studio.share.comment', ['token' => $token]) }}"
      data-can-comment="{{ $canComment ? '1' : '0' }}"
      data-fonts="{{ json_encode($fonts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}">
    <a class="sv-skip" href="#sv-main">Skip to the design</a>

    <header class="sv-bar">
        <span class="sv-brand"><span class="sv-mark" role="img" aria-label="Dot.Design mark"></span><span class="sv-brand__name">Dot.Design</span></span>
        <div class="sv-title">
            <h1>{{ $name }}</h1>
            <p>{{ $sentence }}</p>
        </div>
        <button type="button" class="sv-theme" data-sv-theme aria-pressed="false" aria-label="Dark mode" hidden>
            <span data-sv-theme-label>Dark</span>
        </button>
    </header>

    <div class="sv-body">
        <main id="sv-main" class="sv-view" tabindex="-1">
            <div class="sv-interactive" hidden data-sv-interactive>
                <div class="sv-stage" data-sv-stage tabindex="0" role="group" aria-roledescription="design viewer" aria-label="Design preview. Left and right arrow keys change page. Plus and minus zoom."></div>
                <div class="sv-toolbar" role="toolbar" aria-label="Viewer controls">
                    <div class="sv-toolbar__group">
                        <button type="button" class="sv-tool" data-sv-prev aria-label="Previous page">Previous</button>
                        <span class="sv-pagelabel" data-sv-pagelabel role="status" aria-live="polite"></span>
                        <button type="button" class="sv-tool" data-sv-next aria-label="Next page">Next</button>
                    </div>
                    <div class="sv-toolbar__group">
                        <button type="button" class="sv-tool" data-sv-zoomout aria-label="Zoom out">&minus;</button>
                        <span class="sv-zoomlabel" data-sv-zoomlabel aria-live="off"></span>
                        <button type="button" class="sv-tool" data-sv-zoomin aria-label="Zoom in">+</button>
                        <button type="button" class="sv-tool" data-sv-fit>Fit to screen</button>
                    </div>
                    @if ($canDownload)
                        {{-- Only on a link made with "Allow downloads". The buttons stay hidden until the script that makes the file has loaded. --}}
                        <div class="sv-toolbar__group" data-sv-download-group hidden>
                            <button type="button" class="sv-tool" data-sv-download="png">Download picture</button>
                            <button type="button" class="sv-tool" data-sv-download="pdf">Download PDF</button>
                            <span class="sv-hint" data-sv-download-status role="status" aria-live="polite"></span>
                        </div>
                    @endif
                </div>
                <nav class="sv-strip" aria-label="Pages" data-sv-strip @if ($count < 2) hidden @endif></nav>
            </div>

            {{-- Server-drawn pages: this is the whole viewer without JavaScript, and the source of the page strip thumbnails. --}}
            <div class="sv-fallback" data-sv-fallback>
                @foreach ($pages as $page)
                    <figure id="page-{{ $page['number'] }}" data-page="{{ $page['number'] }}" class="sv-fallback__page">
                        <div class="design-share-page">{!! $page['svg'] !!}</div>
                        <figcaption>Page {{ $page['number'] }}{{ $page['name'] ? ': '.$page['name'] : '' }}</figcaption>
                    </figure>
                @endforeach
            </div>
        </main>

        @if ($canComment)
            <aside class="sv-comment" id="guest-comment" aria-labelledby="sv-comment-h" hidden data-sv-comment>
                <h2 id="sv-comment-h">Leave a comment</h2>
                <p class="sv-hint">Say who you are so the owner knows who is asking. Your name and comment are visible to the people who made this design.</p>
                <form method="post" action="{{ route('studio.share.comment', ['token' => $token]) }}" data-share-comment-form novalidate>
                    <div class="lt-field">
                        <label class="lt-label" for="sv-name">Your name</label>
                        <input class="lt-input" id="sv-name" type="text" name="guest_name" maxlength="60" required autocomplete="name">
                        <p class="lt-error" id="sv-name-err" hidden></p>
                    </div>
                    <div class="lt-field">
                        <label class="lt-label" for="sv-body">Comment</label>
                        <textarea class="lt-textarea" id="sv-body" name="body" maxlength="2000" rows="4" required aria-describedby="sv-body-count"></textarea>
                        <p class="sv-count" id="sv-body-count" data-sv-count></p>
                        <p class="lt-error" id="sv-body-err" hidden></p>
                    </div>
                    <div class="sv-pinrow">
                        <button type="button" class="lt-btn lt-btn--secondary lt-btn--sm" data-sv-pin aria-pressed="false">Point to a spot on the design</button>
                        <span class="sv-hint" data-sv-pinstate role="status"></span>
                    </div>
                    {{-- A field real visitors never see: a bot that fills it is accepted silently by the server and nothing is stored. --}}
                    <div class="sv-honey" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <div class="sv-actions">
                        <button type="submit" class="lt-btn">Send comment</button>
                    </div>
                    <p class="sv-status" role="status" aria-live="polite" data-share-comment-status></p>
                </form>
                <div class="sv-mine" data-sv-mine hidden>
                    <h3>Your comments on this page</h3>
                    <ul data-sv-minelist></ul>
                </div>
            </aside>
            <noscript><p class="sv-noscript">Turn on JavaScript in your browser to leave a comment.</p></noscript>
        @endif
    </div>

    <script type="application/json" id="dot-share-document">{!! json_encode($document, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
</body>
</html>
