@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])
{{-- Jetstream's flash banner (Livewire dispatches `banner-message`). Restyled onto the Light Table alert tones. --}}
<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
     style="display: none;"
     x-show="show && message"
     x-on:banner-message.window="style = event.detail.style; message = event.detail.message; show = true;"
     class="lt-banner" :class="{ 'lt-banner--ok': style == 'success', 'lt-banner--bad': style == 'danger', 'lt-banner--warn': style == 'warning' }" role="status">
    <p class="lt-banner__text" x-text="message"></p>
    <button type="button" class="lt-icon-btn" aria-label="Dismiss message" x-on:click="show = false"><x-ds.icon name="x" /></button>
</div>
