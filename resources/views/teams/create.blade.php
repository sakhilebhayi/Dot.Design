<x-app-layout>
    <x-slot name="header">{{ __('Create workspace') }}</x-slot>

    <div class="lt-stack">
        <div>
            @livewire('teams.create-team-form')
        </div>
    </div>
</x-app-layout>
