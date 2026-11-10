@props(['submit'])

<div {{ $attributes->merge(['class' => 'lt-formsection']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <form wire:submit="{{ $submit }}" class="lt-formsection__body">
        <div class="grid grid-cols-6 gap-6">
            {{ $form }}
        </div>

        @if (isset($actions))
            <div class="lt-formsection__foot">
                {{ $actions }}
            </div>
        @endif
    </form>
</div>
