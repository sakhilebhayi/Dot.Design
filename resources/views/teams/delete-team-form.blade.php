<x-action-section>
    <x-slot name="title">
        {{ __('Delete workspace') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Permanently delete this workspace.') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600 ">
            {{ __('Once a workspace is deleted, all of its resources and data will be permanently deleted. Before deleting this workspace, please download any data or information regarding this workspace that you wish to retain.') }}
        </div>

        <div class="mt-5">
            <x-danger-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                {{ __('Delete workspace') }}
            </x-danger-button>
        </div>

        <!-- Delete Team Confirmation Modal -->
        <x-confirmation-modal wire:model.live="confirmingTeamDeletion">
            <x-slot name="title">
                {{ __('Delete workspace') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Are you sure you want to delete this workspace? Once a workspace is deleted, all of its resources and data will be permanently deleted.') }}
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3" wire:click="deleteTeam" wire:loading.attr="disabled">
                    {{ __('Delete workspace') }}
                </x-danger-button>
            </x-slot>
        </x-confirmation-modal>
    </x-slot>
</x-action-section>
