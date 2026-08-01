<x-app-layout>
<x-slot name="header">Token Sets</x-slot>

<div style="padding:2rem 2.5rem;max-width:1400px;">

    <div style="margin-bottom:2rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin:0 0 0.25rem;">
            Token Sets
        </h1>
        <p style="font-size:0.8rem;color:var(--text-dim);margin:0;">
            Design-token / component-library domain (MVP scaffold) &mdash; shared catalog, not team-scoped.
        </p>
    </div>

    @if ($tokenSets->isEmpty())
        <div class="dot-card" style="border-style:dashed;padding:3rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:40px;color:var(--text-dim);display:block;margin-bottom:0.75rem;">palette</span>
            <p style="color:var(--text-dim);font-size:0.85rem;margin:0;">No token sets yet.</p>
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
            @foreach ($tokenSets as $tokenSet)
                @php
                    $swatchTokens = $tokenSet->tokens->where('category', 'color')->take(6);
                @endphp
                <a href="{{ route('design-system.token-sets.show', $tokenSet) }}" class="dot-card" style="display:block;padding:1.25rem;text-decoration:none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text);">
                            {{ $tokenSet->name }}
                        </div>
                        <span class="dot-badge dot-badge-accent">v{{ $tokenSet->version }}</span>
                    </div>
                    <p style="font-size:0.78rem;color:var(--text-dim);margin:0 0 0.9rem;min-height:1.1rem;">
                        {{ $tokenSet->description ?: 'No description.' }}
                    </p>

                    @if ($swatchTokens->isNotEmpty())
                        <div style="display:flex;gap:0.35rem;margin-bottom:0.9rem;">
                            @foreach ($swatchTokens as $token)
                                <span title="{{ $token->name }}: {{ $token->value }}"
                                      style="width:22px;height:22px;border-radius:6px;background:{{ $token->value }};border:1px solid var(--border-strong);display:inline-block;"></span>
                            @endforeach
                        </div>
                    @endif

                    <div style="display:flex;align-items:center;gap:1rem;font-size:0.72rem;color:var(--text-dim);">
                        <span style="display:flex;align-items:center;gap:0.25rem;">
                            <span class="material-symbols-rounded" style="font-size:14px;">sell</span>
                            {{ $tokenSet->tokens_count }} {{ Str::plural('token', $tokenSet->tokens_count) }}
                        </span>
                        <span style="display:flex;align-items:center;gap:0.25rem;">
                            <span class="material-symbols-rounded" style="font-size:14px;">hub</span>
                            {{ $tokenSet->consumption_records_count }} {{ Str::plural('consumer', $tokenSet->consumption_records_count) }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

</div>

</x-app-layout>
