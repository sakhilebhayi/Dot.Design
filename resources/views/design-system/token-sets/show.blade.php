<x-app-layout>
<x-slot name="header">{{ $tokenSet->name }}</x-slot>

<div style="padding:2rem 2.5rem;max-width:1400px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin:0;">
            {{ $tokenSet->name }}
        </h1>
        <span class="dot-badge dot-badge-accent">v{{ $tokenSet->version }}</span>
    </div>
    <p style="font-size:0.8rem;color:var(--text-dim);margin:0 0 2rem;">
        {{ $tokenSet->description ?: 'No description.' }}
    </p>

    {{-- Tokens --}}
    <div style="margin-bottom:2.5rem;">
        <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text);margin:0 0 1rem;">Tokens</h2>

        @if ($tokenSet->tokens->isEmpty())
            <div class="dot-card" style="border-style:dashed;padding:2rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:32px;color:var(--text-dim);display:block;margin-bottom:0.5rem;">sell</span>
                <p style="color:var(--text-dim);font-size:0.82rem;margin:0;">No tokens in this set yet.</p>
            </div>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:0.85rem;">
                @foreach ($tokenSet->tokens as $token)
                    <div class="dot-card" style="padding:1rem;">
                        @if ($token->category === 'color')
                            <div style="height:52px;border-radius:8px;background:{{ $token->value }};border:1px solid var(--border-strong);margin-bottom:0.75rem;"></div>
                        @elseif ($token->category === 'spacing')
                            <div style="height:52px;border-radius:8px;background:rgba(127,127,127,0.08);border:1px solid var(--border);margin-bottom:0.75rem;display:flex;align-items:center;padding:0 0.75rem;">
                                <div style="height:8px;border-radius:4px;background:#ec4899;width:{{ min((float) $token->value * 16, 100) }}%;max-width:100%;"></div>
                            </div>
                        @elseif ($token->category === 'type')
                            <div style="height:52px;border-radius:8px;background:rgba(127,127,127,0.08);border:1px solid var(--border);margin-bottom:0.75rem;display:flex;align-items:center;justify-content:center;">
                                <span style="font-family:'Syne',sans-serif;color:var(--text);font-size:{{ min((float) $token->value * 16, 32) }}px;line-height:1;">Aa</span>
                            </div>
                        @else
                            <div style="height:52px;border-radius:8px;background:rgba(127,127,127,0.08);border:1px solid var(--border);margin-bottom:0.75rem;display:flex;align-items:center;justify-content:center;">
                                <span class="material-symbols-rounded" style="font-size:22px;color:var(--text-dim);">bolt</span>
                            </div>
                        @endif

                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.2rem;">
                            <div style="font-size:0.85rem;font-weight:600;color:var(--text);">{{ $token->name }}</div>
                            <span class="dot-badge" style="background:rgba(127,127,127,0.1);color:var(--text-dim);">v{{ $token->version }}</span>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-size:0.7rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;">{{ $token->category }}</span>
                            <span class="metric-val" style="font-size:0.72rem;color:var(--text-muted);">{{ $token->value }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Consuming platforms --}}
    <div>
        <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text);margin:0 0 1rem;">Consuming Platforms</h2>

        @if ($tokenSet->consumptionRecords->isEmpty())
            <div class="dot-card" style="border-style:dashed;padding:2rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:32px;color:var(--text-dim);display:block;margin-bottom:0.5rem;">hub</span>
                <p style="color:var(--text-dim);font-size:0.82rem;margin:0;">No consumers recorded yet.</p>
            </div>
        @else
            <div class="dot-card" style="padding:0.5rem;">
                <table style="width:100%;border-collapse:collapse;font-size:0.83rem;color:var(--text-muted);">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid var(--border);">
                            <th style="padding:0.6rem 0.75rem;color:var(--text-dim);font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;">Platform</th>
                            <th style="padding:0.6rem 0.75rem;color:var(--text-dim);font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;">Pinned version</th>
                            <th style="padding:0.6rem 0.75rem;color:var(--text-dim);font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;">Last synced</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokenSet->consumptionRecords as $record)
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:0.6rem 0.75rem;color:var(--text);">{{ $record->platform_id }}</td>
                                <td style="padding:0.6rem 0.75rem;">v{{ $record->pinned_version }}</td>
                                <td style="padding:0.6rem 0.75rem;color:var(--text-dim);">{{ $record->last_synced_at?->diffForHumans() ?? 'never synced' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>

</x-app-layout>
