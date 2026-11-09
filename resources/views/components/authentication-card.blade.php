{{-- Sign-in shell: the form on the sheet, a proof table beside it. The picture is three real library templates (or, if the artwork was never built, the real logo on a proof sheet). --}}
<div class="lt-auth">
    <div class="lt-auth__table" aria-hidden="true">
        @php($art = \App\Support\Ui\LandingArt::manifest())
        @if (isset($art['close-instagram-post'], $art['close-poster'], $art['close-presentation']))
            {{-- real library templates drawn by the editor's renderer (scripts/landing/build-art.mjs), not a picture of nothing --}}
            <div class="lt-auth__trio">
                @foreach (['instagram-post', 'poster', 'presentation'] as $key)
                    <span class="lt-frame lt-crop lp-art" style="--w: {{ $art['close-'.$key]['width'] }}; --h: {{ $art['close-'.$key]['height'] }};">{!! \App\Support\Ui\LandingArt::svg('close-'.$key) !!}</span>
                @endforeach
            </div>
        @else
            <span class="lt-frame lt-crop lt-auth__sheet" style="--w: 4; --h: 3; --crop-len: 14px; --crop-gap: 10px;"><x-ds.logo /></span>
        @endif
    </div>
    <main id="main" class="lt-auth__main" tabindex="-1">
        <div class="lt-auth__box">
            <a href="{{ url('/') }}" class="lt-brand" aria-label="Dot.Design, home" style="padding-left: 0;">
                <span class="lt-mark" role="img" aria-label="Dot.Design mark"></span>
                <span class="lt-brand__name">Dot.Design</span>
            </a>
            {{ $slot }}
        </div>
        <p class="lt-auth__foot">
            <a href="{{ route('terms.show') }}">Terms</a> &nbsp; <a href="{{ route('policy.show') }}">Privacy</a> &nbsp; <a href="{{ route('cookies') }}">Cookies</a>
        </p>
    </main>
</div>
