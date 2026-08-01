<x-app-layout>

<div style="padding:2rem 2.5rem;max-width:1400px;">
    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">
            Component Library
        </h1>
        <p style="font-size:0.8rem;color:#71717a;margin:0;">
            Shared component definitions, including Brain-surface components (Why block, confidence badge, intent label).
        </p>
    </div>

    <table style="width:100%;border-collapse:collapse;font-size:0.85rem;color:#e4e4e7;">
        <thead>
            <tr style="text-align:left;border-bottom:1px solid #3f3f46;">
                <th style="padding:0.5rem;">Name</th>
                <th style="padding:0.5rem;">Category</th>
                <th style="padding:0.5rem;">Brain-surface</th>
                <th style="padding:0.5rem;">Version</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($components as $component)
                <tr style="border-bottom:1px solid #27272a;">
                    <td style="padding:0.5rem;">
                        <a href="{{ route('design-system.components.show', $component) }}" style="color:#e879f9;text-decoration:none;">
                            {{ $component->name }}
                        </a>
                    </td>
                    <td style="padding:0.5rem;color:#a1a1aa;">{{ $component->category }}</td>
                    <td style="padding:0.5rem;">{{ $component->is_brain_surface ? 'Yes' : 'No' }}</td>
                    <td style="padding:0.5rem;">v{{ $component->version }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="padding:1rem;color:#71717a;">No components yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

</x-app-layout>
