@push('head')
    @vite(['resources/css/pages/brand.css', 'resources/js/studio/pages/settings.js'])
@endpush
@php
    $boot = ['settings' => $settings, 'url' => route('design.settings.update')];
    $modes = ['simple' => 'Simple', 'pro' => 'Pro'];
    $motion = ['system' => 'Match my device', 'reduce' => 'Less movement', 'full' => 'Full'];
    $switches = [
        'snap' => ['Snap to guides', 'Elements click into line with each other and with the edges of the page while you drag.'],
        'grid' => ['Show a grid', 'A faint grid on the canvas to help you line things up. It never prints or exports.'],
        'rulers' => ['Show rulers', 'Rulers along the edges of the canvas, in the units of the design.'],
    ];
@endphp
<x-app-layout>
<div class="lt-page lt-page--narrow" id="settings-page">
    <x-ds.page-header title="Studio settings" sub="How the editor behaves for you. They follow you across every design and every workspace." />

    <div class="br-prefs" data-prefs>
        <div class="br-pref">
            <div class="br-pref__text" id="pref-mode-t"><span class="br-pref__name">Editor mode</span><span class="lt-small">Simple shows only what you need, in plain words. Pro shows every panel. Your designs are the same either way, so you can switch at any time.</span></div>
            <div class="lt-seg br-seg-wrap" role="group" aria-labelledby="pref-mode-t">
                @foreach ($modes as $value => $label)
                    <button type="button" data-pref="mode" data-value="{{ $value }}" aria-pressed="{{ $settings['mode'] === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        @foreach ($switches as $key => [$label, $help])
            <div class="br-pref">
                <div class="br-pref__text"><label for="pref-{{ $key }}" class="br-pref__name">{{ $label }}</label><span class="lt-small" id="pref-{{ $key }}-h">{{ $help }}</span></div>
                <input type="checkbox" role="switch" class="lt-switch" id="pref-{{ $key }}" data-pref="{{ $key }}" aria-describedby="pref-{{ $key }}-h" @checked($settings[$key])>
            </div>
        @endforeach
        <div class="br-pref">
            <div class="br-pref__text" id="pref-motion-t"><span class="br-pref__name">Movement in the editor</span><span class="lt-small">Animations are short and only there to show what changed. Choose less movement to turn them down, whatever your device says.</span></div>
            <div class="lt-seg br-seg-wrap" role="group" aria-labelledby="pref-motion-t">
                @foreach ($motion as $value => $label)
                    <button type="button" data-pref="reduced_motion_pref" data-value="{{ $value }}" aria-pressed="{{ $settings['reduced_motion_pref'] === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>
    <p class="br-prefs__status lt-small" role="status" aria-live="polite" data-pref-status>Changes are saved as you make them.</p>
    @if (\Illuminate\Support\Facades\Route::has('design.integrations.index'))
        <p class="lt-small" style="margin-top: var(--lt-space-6);">Looking for what Dot.Design is connected to? <a href="{{ route('design.integrations.index') }}">See connections to other Dot platforms</a>.</p>
    @endif
    @if (\Illuminate\Support\Facades\Route::has('design.settings.my-data'))
        <p class="lt-small" style="margin-top: var(--lt-space-3);">Your information: <a href="{{ route('design.settings.my-data') }}" download>download a copy of everything Dot.Design holds about you</a> (one file). To delete your account, use Profile and security.</p>
    @endif

    <script type="application/json" id="settings-boot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
</div>
</x-app-layout>
