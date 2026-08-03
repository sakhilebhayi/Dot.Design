<x-app-layout>
<x-slot name="header">{{ $designComponent->name }}</x-slot>

<div style="padding:2rem 2.5rem;max-width:1400px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin:0;">
            {{ $designComponent->name }}
        </h1>
        <span class="dot-badge" style="background:rgba(127,127,127,0.1);color:var(--text-dim);">v{{ $designComponent->version }}</span>
    </div>

    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1.5rem;">
        <span style="font-size:0.75rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;">{{ $designComponent->category }}</span>
        @if ($designComponent->is_brain_surface)
            <span class="dot-badge dot-badge-accent" style="display:inline-flex;align-items:center;gap:0.25rem;">
                <span class="material-symbols-rounded" style="font-size:12px;">psychology</span>
                Brain-surface component
            </span>
        @endif
    </div>

    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <p style="font-size:0.85rem;color:var(--text-muted);max-width:70ch;margin:0;line-height:1.6;">
            {{ $designComponent->description ?: 'No description recorded for this component.' }}
        </p>
    </div>

    @if ($designComponent->props_schema)
        <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text);margin:0 0 0.75rem;">Props schema</h2>
        <pre class="dot-card" style="padding:1rem;color:var(--text-muted);font-size:0.8rem;overflow-x:auto;margin:0;">{{ json_encode($designComponent->props_schema, JSON_PRETTY_PRINT) }}</pre>
    @else
        <div class="dot-card" style="border-style:dashed;padding:2rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:28px;color:var(--text-dim);display:block;margin-bottom:0.5rem;">data_object</span>
            <p style="color:var(--text-dim);font-size:0.8rem;margin:0;">No props schema recorded for this component.</p>
        </div>
    @endif

</div>

</x-app-layout>
