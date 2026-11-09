{{-- Sign-in shell: the form on the sheet, a proof table beside it. The only picture is the real Dot.Design logo on a drawn proof sheet. --}}
<div class="lt-auth">
    <div class="lt-auth__table" aria-hidden="true">
        <span class="lt-frame lt-crop" style="--w: 4; --h: 3; --crop-len: 14px; --crop-gap: 10px;"><img src="{{ asset('images/logo.png') }}" alt="" width="1181" height="886" loading="lazy" decoding="async" style="object-fit: contain; padding: 0.5rem;"></span>
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
