@props(['id', 'maxWidth'])

@php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => 'lt-dialog__box--sm',
    'md' => '',
    'lg' => 'lt-dialog__box--lg',
    'xl' => 'lt-dialog__box--wide',
    '2xl' => 'lt-dialog__box--wide',
][$maxWidth ?? '2xl'];
@endphp

{{-- Jetstream modal (Livewire wire:model + Alpine). Same look as <x-ds.dialog>; kept on Alpine because Livewire drives its open state. --}}
<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    id="{{ $id }}"
    class="jetstream-modal lt-modal"
    style="display: none;"
    role="dialog" aria-modal="true"
>
    <div x-show="show" class="lt-modal__scrim" x-on:click="show = false"
         x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>

    <div x-show="show" class="lt-dialog__box {{ $maxWidth }} lt-modal__box" x-trap.inert.noscroll="show"
         x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        {{ $slot }}
    </div>
</div>
