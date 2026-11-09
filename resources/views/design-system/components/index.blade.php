<x-app-layout>
<div class="lt-page">
    <x-ds.page-header title="Components" sub="Shared component definitions, including the Brain surfaces: the why block, the confidence badge and the intent label." />

    @if ($components->isEmpty())
        <x-ds.empty title="No components yet">Component definitions published by operators appear here.</x-ds.empty>
    @else
        <ul class="lt-cardlist" style="--min: 16rem;">
            @foreach ($components as $def)
                <li class="lt-listcard">
                    <div class="lt-listcard__head">
                        <h2 class="lt-h3"><a href="{{ route('design-system.components.show', $def) }}" class="lt-listcard__link">{{ $def->name }}</a></h2>
                        <x-ds.tag>v{{ $def->version }}</x-ds.tag>
                    </div>
                    <div style="display: flex; gap: var(--lt-space-2); align-items: center; flex-wrap: wrap;">
                        <span class="lt-meta">{{ $def->category }}</span>
                        @if ($def->is_brain_surface)<x-ds.tag kind="accent"><x-ds.icon name="sparkle" size="sm" /> Brain surface</x-ds.tag>@endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
</x-app-layout>
