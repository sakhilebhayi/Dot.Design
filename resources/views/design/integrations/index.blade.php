<x-app-layout>

@php
    $connectable = collect($integrations)->reject(fn ($i) => $i->status === 'no_contract');
    $unavailable = collect($integrations)->filter(fn ($i) => $i->status === 'no_contract');
@endphp

<div class="page">

    <header class="page-head">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin:0 0 0.25rem;">Connections to other Dot platforms</h1>
            <p style="font-size:0.85rem;color:var(--text-muted);margin:0;max-width:65ch;">
                What Dot.Design is really connected to. A platform is only listed as connected after a real call to it has worked.
                Turning a connection on is a server setting, so there are no switches on this page.
            </p>
        </div>
    </header>

    <section aria-labelledby="int-real">
        <h2 id="int-real" style="font-size:1rem;font-weight:700;color:var(--text);margin:1.5rem 0 0.75rem;">Connections that exist</h2>
        <div class="table-scroll">
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                <caption class="sr-only" style="position:absolute;left:-9999px;">Platforms Dot.Design can connect to, with their current status</caption>
                <thead>
                    <tr style="text-align:left;color:var(--text-dim);">
                        <th scope="col" style="padding:0.5rem 0.75rem;font-weight:600;">Platform</th>
                        <th scope="col" style="padding:0.5rem 0.75rem;font-weight:600;">Status</th>
                        <th scope="col" style="padding:0.5rem 0.75rem;font-weight:600;">What it does</th>
                        <th scope="col" style="padding:0.5rem 0.75rem;font-weight:600;">Right now</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($connectable as $item)
                    <tr style="border-top:1px solid var(--border);vertical-align:top;" data-integration="{{ $item->key }}" data-status="{{ $item->status }}">
                        <th scope="row" style="padding:0.75rem;font-weight:600;color:var(--text);text-align:left;">{{ $item->name }}</th>
                        <td style="padding:0.75rem;color:var(--text);white-space:nowrap;font-weight:600;">{{ $item->label() }}</td>
                        <td style="padding:0.75rem;color:var(--text-muted);max-width:42ch;">{{ $item->does }}</td>
                        <td style="padding:0.75rem;color:var(--text-muted);max-width:48ch;">
                            {{ $item->reason }}
                            @if ($operator && $item->setup)
                                <br><span style="color:var(--text-dim);">Setup: {{ $item->setup }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section aria-labelledby="int-none">
        <h2 id="int-none" style="font-size:1rem;font-weight:700;color:var(--text);margin:2rem 0 0.25rem;">Not connected: nothing to connect to</h2>
        <p style="font-size:0.85rem;color:var(--text-muted);margin:0 0 0.75rem;max-width:65ch;">
            These platforms do not offer another app a safe way in, so Dot.Design does not pretend to work with them.
        </p>
        <dl style="margin:0;font-size:0.85rem;">
            @foreach ($unavailable as $item)
                <div style="display:grid;grid-template-columns:minmax(8rem,12rem) 1fr;gap:0.25rem 1rem;padding:0.6rem 0;border-top:1px solid var(--border);" data-integration="{{ $item->key }}" data-status="{{ $item->status }}">
                    <dt style="font-weight:600;color:var(--text);">{{ $item->name }}</dt>
                    <dd style="margin:0;color:var(--text-muted);max-width:65ch;">Not connected. {{ $item->reason }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

</div>

</x-app-layout>
