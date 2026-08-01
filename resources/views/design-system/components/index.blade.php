<x-app-layout>
<x-slot name="header">Component Library</x-slot>

<div style="padding:2rem 2.5rem;max-width:1400px;">

    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin:0 0 0.25rem;">
            Component Library
        </h1>
        <p style="font-size:0.8rem;color:var(--text-dim);margin:0;">
            Shared component definitions, including Brain-surface components (Why block, confidence badge, intent label).
        </p>
    </div>

    @if ($components->isEmpty())
        <div class="dot-card" style="border-style:dashed;padding:3rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:40px;color:var(--text-dim);display:block;margin-bottom:0.75rem;">widgets</span>
            <p style="color:var(--text-dim);font-size:0.85rem;margin:0;">No components yet.</p>
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;">
            @foreach ($components as $component)
                <a href="{{ route('design-system.components.show', $component) }}" class="dot-card" style="display:block;padding:1.25rem;text-decoration:none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text);">
                            {{ $component->name }}
                        </div>
                        <span class="dot-badge" style="background:rgba(127,127,127,0.1);color:var(--text-dim);">v{{ $component->version }}</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                        <span style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;">{{ $component->category }}</span>
                        @if ($component->is_brain_surface)
                            <span class="dot-badge dot-badge-accent" style="display:inline-flex;align-items:center;gap:0.25rem;">
                                <span class="material-symbols-rounded" style="font-size:12px;">psychology</span>
                                Brain surface
                            </span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif

</div>

</x-app-layout>
