<x-app-layout>

<div style="padding:2rem 2.5rem;max-width:1400px;">
    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">
            {{ $tokenSet->name }} <span style="color:#71717a;font-weight:400;">v{{ $tokenSet->version }}</span>
        </h1>
        <p style="font-size:0.8rem;color:#71717a;margin:0;">{{ $tokenSet->description }}</p>
    </div>

    <h2 style="font-family:'Syne',sans-serif;font-size:1.1rem;color:#f4f4f5;">Tokens</h2>
    <table style="width:100%;border-collapse:collapse;font-size:0.85rem;color:#e4e4e7;margin-bottom:2rem;">
        <thead>
            <tr style="text-align:left;border-bottom:1px solid #3f3f46;">
                <th style="padding:0.5rem;">Name</th>
                <th style="padding:0.5rem;">Category</th>
                <th style="padding:0.5rem;">Value</th>
                <th style="padding:0.5rem;">Version</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tokenSet->tokens as $token)
                <tr style="border-bottom:1px solid #27272a;">
                    <td style="padding:0.5rem;">{{ $token->name }}</td>
                    <td style="padding:0.5rem;color:#a1a1aa;">{{ $token->category }}</td>
                    <td style="padding:0.5rem;">{{ $token->value }}</td>
                    <td style="padding:0.5rem;">v{{ $token->version }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="padding:1rem;color:#71717a;">No tokens yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 style="font-family:'Syne',sans-serif;font-size:1.1rem;color:#f4f4f5;">Consuming Platforms</h2>
    <table style="width:100%;border-collapse:collapse;font-size:0.85rem;color:#e4e4e7;">
        <thead>
            <tr style="text-align:left;border-bottom:1px solid #3f3f46;">
                <th style="padding:0.5rem;">Platform</th>
                <th style="padding:0.5rem;">Pinned version</th>
                <th style="padding:0.5rem;">Last synced</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tokenSet->consumptionRecords as $record)
                <tr style="border-bottom:1px solid #27272a;">
                    <td style="padding:0.5rem;">{{ $record->platform_id }}</td>
                    <td style="padding:0.5rem;">v{{ $record->pinned_version }}</td>
                    <td style="padding:0.5rem;color:#71717a;">{{ $record->last_synced_at?->diffForHumans() ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="padding:1rem;color:#71717a;">No consumers recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

</x-app-layout>
