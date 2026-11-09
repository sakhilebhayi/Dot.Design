<div {{ $attributes->merge(['class' => 'lt-formsection']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="lt-formsection__body">
        {{ $content }}
    </div>
</div>
