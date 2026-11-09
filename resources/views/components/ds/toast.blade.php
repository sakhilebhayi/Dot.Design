{{-- The toast region (include once per layout). Session flash messages are rendered as plain elements that toast.js turns into toasts; without script they stay readable on the page. --}}
@php($flash = array_filter([
    ['kind' => 'ok', 'text' => session('status')],
    ['kind' => 'ok', 'text' => session('success')],
    ['kind' => 'bad', 'text' => session('error')],
    ['kind' => 'warn', 'text' => session('warning')],
], fn ($f) => is_string($f['text']) && $f['text'] !== ''))
<div id="lt-toasts" class="lt-toasts" role="status" aria-live="polite">
    @foreach (['circle-check' => 'circle-check', 'circle-alert' => 'circle-alert', 'triangle-alert' => 'triangle-alert', 'info' => 'info', 'x' => 'x'] as $key => $icon)
        <template data-icon="{{ $key }}"><x-ds.icon :name="$icon" /></template>
    @endforeach
    @foreach ($flash as $f)
        <div class="lt-toast lt-toast--{{ $f['kind'] }}" data-lt-flash data-kind="{{ $f['kind'] }}" data-message="{{ $f['text'] }}"><x-ds.icon :name="$f['kind'] === 'ok' ? 'circle-check' : ($f['kind'] === 'bad' ? 'circle-alert' : 'triangle-alert')" /><div class="lt-toast__body">{{ $f['text'] }}</div></div>
    @endforeach
</div>
