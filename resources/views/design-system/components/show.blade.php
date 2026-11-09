<x-app-layout>
<div class="lt-page lt-page--narrow">
    <x-ds.page-header :title="$designComponent->name">
        <x-ds.tag>v{{ $designComponent->version }}</x-ds.tag>
        <x-ds.button :href="route('design-system.components.index')" variant="ghost" icon="arrow-left" size="sm">All components</x-ds.button>
    </x-ds.page-header>

    <div style="display: flex; align-items: center; gap: var(--lt-space-2); margin-bottom: var(--lt-space-5); flex-wrap: wrap;">
        <span class="lt-small">{{ $designComponent->category }}</span>
        @if ($designComponent->is_brain_surface)<x-ds.tag kind="accent"><x-ds.icon name="sparkle" size="sm" /> Brain-surface component</x-ds.tag>@endif
    </div>

    <p class="lt-measure" style="margin-bottom: var(--lt-space-6);">{{ $designComponent->description ?: 'No description recorded for this component.' }}</p>

    @if ($designComponent->props_schema)
        <h2 class="lt-section__title" style="margin-bottom: var(--lt-space-3);">Props schema</h2>
        <pre class="lt-code" tabindex="0">{{ json_encode($designComponent->props_schema, JSON_PRETTY_PRINT) }}</pre>
    @else
        <x-ds.empty title="No props schema recorded">When an operator adds one, the props this component accepts are listed here.</x-ds.empty>
    @endif
</div>
</x-app-layout>
