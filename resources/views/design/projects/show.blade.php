@extends('layouts.studio')

@section('title', $project->name)

@section('content')
    {{-- The editor mounts here. Everything it needs is in the JSON below (a non-executable data block, so no inline script). --}}
    <div id="studio-app" class="st-app" data-project="{{ $project->id }}">
        <div class="st-boot" role="status" aria-live="polite">
            <svg class="lt-icon lt-icon--lg lt-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.55"/></svg>
            <span>Opening {{ $project->name }}</span>
        </div>
        <noscript><p class="st-noscript">The design editor needs JavaScript. Turn it on in your browser and reload this page.</p></noscript>
    </div>
    <script type="application/json" id="studio-boot">@json($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)</script>
@endsection
