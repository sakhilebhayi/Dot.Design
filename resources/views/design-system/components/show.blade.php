<x-app-layout>

<div style="padding:2rem 2.5rem;max-width:1400px;">
    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">
            {{ $component->name }} <span style="color:#71717a;font-weight:400;">v{{ $component->version }}</span>
        </h1>
        <p style="font-size:0.8rem;color:#71717a;margin:0;">
            {{ $component->category }}
            @if($component->is_brain_surface)
                &middot; Brain-surface component
            @endif
        </p>
    </div>

    <p style="font-size:0.85rem;color:#e4e4e7;max-width:60ch;">{{ $component->description }}</p>

    @if($component->props_schema)
        <h2 style="font-family:'Syne',sans-serif;font-size:1rem;color:#f4f4f5;margin-top:1.5rem;">Props schema</h2>
        <pre style="background:#18181b;border:1px solid #27272a;border-radius:0.5rem;padding:1rem;color:#a1a1aa;font-size:0.8rem;overflow-x:auto;">{{ json_encode($component->props_schema, JSON_PRETTY_PRINT) }}</pre>
    @endif
</div>

</x-app-layout>
