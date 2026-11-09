@push('head')
    @vite(['resources/css/pages/brand.css'])
@endpush
@php
    $connectable = collect($integrations)->reject(fn ($i) => $i->status === 'no_contract');
    $unavailable = collect($integrations)->filter(fn ($i) => $i->status === 'no_contract');
    $kinds = ['connected' => 'ok', 'ready' => 'accent', 'unavailable' => 'warn', 'not_configured' => 'neutral'];
@endphp
<x-app-layout>
<div class="lt-page lt-page--wide br-int">
    <x-ds.page-header title="Connections to other Dot platforms" sub="What Dot.Design is really connected to. A platform is only listed as connected after a real call to it has worked. Turning a connection on is a server setting, so there are no switches on this page." />

    <section aria-labelledby="int-real">
        <h2 id="int-real" class="lt-h2" style="margin-bottom: var(--lt-space-4);">Connections that exist</h2>
        <div class="lt-table-scroll" tabindex="0" role="region" aria-labelledby="int-real">
            <table class="lt-table br-int__table">
                <caption class="lt-sr">Platforms Dot.Design can connect to, with their current status</caption>
                <thead>
                    <tr>
                        <th scope="col">Platform</th>
                        <th scope="col">Status</th>
                        <th scope="col">What it does</th>
                        <th scope="col">Right now</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($connectable as $item)
                    <tr data-integration="{{ $item->key }}" data-status="{{ $item->status }}" style="vertical-align: top;">
                        <th scope="row">{{ $item->name }}</th>
                        <td style="white-space: nowrap;"><x-ds.tag :kind="$kinds[$item->status] ?? 'neutral'">{{ $item->label() }}</x-ds.tag></td>
                        <td class="lt-muted" style="max-width: 42ch;">{{ $item->does }}</td>
                        <td class="lt-muted" style="max-width: 48ch;">
                            {{ $item->reason }}
                            @if ($operator && $item->setup)
                                <br><span class="lt-faint">Setup: {{ $item->setup }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section aria-labelledby="int-none">
        <h2 id="int-none" class="lt-h2">Not connected: nothing to connect to</h2>
        <p class="lt-small" style="margin: var(--lt-space-1) 0 var(--lt-space-4); max-width: 65ch;">These platforms do not offer another app a safe way in, so Dot.Design does not pretend to work with them.</p>
        <dl class="br-int__list">
            @foreach ($unavailable as $item)
                <div class="br-int__item" data-integration="{{ $item->key }}" data-status="{{ $item->status }}">
                    <dt>{{ $item->name }}</dt>
                    <dd>Not connected. {{ $item->reason }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>
</x-app-layout>
