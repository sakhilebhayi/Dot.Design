@php
    // Interim create form (wave 2 replaces it with the guided flow and keeps this contract: POST name, type, width, height, unit).
    // ?format=<key> prefills the fields from the shared format list; an unknown key is ignored.
    $preset = \App\Support\Ui\StartFormats::find(request()->query('format'));
    $typeValue = old('type', $preset['type'] ?? 'graphic');
    $unitValue = old('unit', $preset['unit'] ?? 'px');
@endphp
<x-app-layout>
<div class="lt-page lt-page--narrow">
    <x-ds.page-header title="New design" :sub="$preset ? $preset['name'].', '.$preset['size'].'. Change anything below.' : 'Set up a canvas to start designing.'" />

    @if ($errors->any())
        <div class="lt-alert lt-alert--bad" role="alert" style="margin-bottom: var(--lt-space-5);">
            <x-ds.icon name="circle-alert" />
            <div><strong>This design could not be created.</strong>
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('design.projects.store') }}" class="lt-form">
        @csrf
        <x-ds.field label="Name" name="name" id="design-projects-create-name" required maxlength="255" placeholder="Summer sale Instagram post" :value="$preset ? $preset['name'] : null" autofocus />

        <x-ds.select label="Type" name="type" id="design-projects-create-type" required :selected="$typeValue" :options="collect($types)->mapWithKeys(fn ($t) => [$t => ucfirst($t)])->all()" />

        <div class="form-grid-3 lt-form__row" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
            <x-ds.field label="Width" name="width" type="number" id="design-projects-create-width" min="1" max="10000" required :value="$preset['width'] ?? 1200" />
            <x-ds.field label="Height" name="height" type="number" id="design-projects-create-height" min="1" max="10000" required :value="$preset['height'] ?? 628" />
            <x-ds.select label="Unit" name="unit" id="design-projects-create-unit" required :selected="$unitValue" :options="array_combine(['px', 'in', 'cm', 'mm'], ['px', 'in', 'cm', 'mm'])" />
        </div>

        <div class="lt-start" role="list" aria-label="Sizes" style="margin-inline: calc(-1 * var(--lt-space-3)); flex-wrap: wrap; overflow: visible;">
            @foreach (\App\Support\Ui\StartFormats::all() as $format)
                <x-ds.format-chip role="listitem" :href="route('design.projects.create').'?format='.$format['key']" :name="$format['name']" :width="$format['width']" :height="$format['height']" :size="$format['width'].' × '.$format['height'].' '.$format['unit']" />
            @endforeach
        </div>

        <div class="lt-form__actions">
            <x-ds.button type="submit">Create design</x-ds.button>
            <x-ds.button :href="route('design.projects.index')" variant="ghost">Cancel</x-ds.button>
        </div>
    </form>
</div>
</x-app-layout>
