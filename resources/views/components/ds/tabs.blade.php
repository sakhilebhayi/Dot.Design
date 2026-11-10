@props(['tabs' => [], 'selected' => null, 'label' => 'Sections', 'prefix' => 'tab'])
{{-- Panel tabs: pair each entry with <x-ds.tabpanel id="..."> using the same ids. For link tabs pass `links` instead. --}}
@php($selected = $selected ?? array_key_first($tabs))
<div data-lt-tabs>
    <div class="lt-tabs" role="tablist" aria-label="{{ $label }}">
        @foreach ($tabs as $id => $text)
            <button type="button" role="tab" id="{{ $prefix }}-{{ $id }}" class="lt-tab" aria-controls="{{ $prefix }}-panel-{{ $id }}" aria-selected="{{ (string) $id === (string) $selected ? 'true' : 'false' }}" tabindex="{{ (string) $id === (string) $selected ? 0 : -1 }}">{{ $text }}</button>
        @endforeach
    </div>
    {{ $slot }}
</div>
