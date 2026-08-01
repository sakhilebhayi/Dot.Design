<x-app-layout>

<div style="padding:2rem 2.5rem;max-width:1400px;">
    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">
            Token Sets
        </h1>
        <p style="font-size:0.8rem;color:#71717a;margin:0;">
            Design-token / component-library domain (MVP scaffold) — shared, not team-scoped.
        </p>
    </div>

    <table style="width:100%;border-collapse:collapse;font-size:0.85rem;color:#e4e4e7;">
        <thead>
            <tr style="text-align:left;border-bottom:1px solid #3f3f46;">
                <th style="padding:0.5rem;">Name</th>
                <th style="padding:0.5rem;">Slug</th>
                <th style="padding:0.5rem;">Version</th>
                <th style="padding:0.5rem;">Tokens</th>
                <th style="padding:0.5rem;">Consumers</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tokenSets as $tokenSet)
                <tr style="border-bottom:1px solid #27272a;">
                    <td style="padding:0.5rem;">
                        <a href="{{ route('design-system.token-sets.show', $tokenSet) }}" style="color:#e879f9;text-decoration:none;">
                            {{ $tokenSet->name }}
                        </a>
                    </td>
                    <td style="padding:0.5rem;color:#a1a1aa;">{{ $tokenSet->slug }}</td>
                    <td style="padding:0.5rem;">v{{ $tokenSet->version }}</td>
                    <td style="padding:0.5rem;">{{ $tokenSet->tokens_count }}</td>
                    <td style="padding:0.5rem;">{{ $tokenSet->consumption_records_count }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="padding:1rem;color:#71717a;">No token sets yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

</x-app-layout>
