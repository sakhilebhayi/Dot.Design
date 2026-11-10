@props(['items' => [], 'label' => 'Sections'])
<nav class="lt-tabs" aria-label="{{ $label }}">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}" class="lt-tab" @if(! empty($item['current'])) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
