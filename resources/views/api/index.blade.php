<x-app-layout>
    <x-slot name="header">{{ __('API tokens') }}</x-slot>

    <div class="lt-stack">
        <div>
            @livewire('api.api-token-manager')
        </div>
    </div>
</x-app-layout>
