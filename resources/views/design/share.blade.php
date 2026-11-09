<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $document['project']['name'] }}</title>
</head>
<body>
    <main>
        <header>
            <h1>{{ $document['project']['name'] }}</h1>
            <p>{{ count($pages) }} {{ count($pages) === 1 ? 'page' : 'pages' }}. Shared with you to look at{{ $canComment ? ' and comment on' : '' }}.</p>
        </header>

        @foreach ($pages as $page)
            <figure id="page-{{ $page['number'] }}" data-page="{{ $page['number'] }}">
                {{-- Server-drawn preview: works without JavaScript. The sanitised read-only document is in the script tag below for the viewer. --}}
                <div class="design-share-page">{!! $page['svg'] !!}</div>
                <figcaption>Page {{ $page['number'] }}{{ $page['name'] ? ': '.$page['name'] : '' }}</figcaption>
            </figure>
        @endforeach

        @if ($canComment)
            <section id="guest-comment">
                <h2>Leave a comment</h2>
                <form method="post" action="{{ route('studio.share.comment', ['token' => $token]) }}" data-share-comment-form>
                    <p><label>Your name <input type="text" name="guest_name" maxlength="60" required autocomplete="name"></label></p>
                    <p><label>Comment <textarea name="body" maxlength="2000" rows="4" required></textarea></label></p>
                    <p aria-hidden="true" style="position:absolute;left:-9999px"><label>Leave empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
                    <p><button type="submit">Send comment</button></p>
                    <p role="status" data-share-comment-status></p>
                </form>
            </section>
        @endif
    </main>

    <script type="application/json" id="dot-share-document">{!! json_encode($document, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>

    @if ($canComment)
        <script>
            document.querySelectorAll('[data-share-comment-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    var status = form.querySelector('[data-share-comment-status]');
                    status.textContent = 'Sending...';
                    fetch(form.action, {
                        method: 'POST',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                        body: JSON.stringify(Object.fromEntries(new FormData(form))),
                        credentials: 'omit'
                    }).then(function (response) {
                        return response.json().then(function (body) { return {ok: response.ok, body: body}; });
                    }).then(function (result) {
                        status.textContent = result.ok ? 'Thank you. Your comment was sent.' : (result.body.message || 'Your comment could not be sent.');
                        if (result.ok) { form.reset(); }
                    }).catch(function () {
                        status.textContent = 'Your comment could not be sent. Check your connection and try again.';
                    });
                });
            });
        </script>
    @endif
</body>
</html>
