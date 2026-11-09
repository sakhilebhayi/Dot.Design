@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="lt-dialog__head"><div class="lt-dialog__title">{{ $title }}</div></div>
    <div class="lt-dialog__body">{{ $content }}</div>
    <div class="lt-dialog__foot">{{ $footer }}</div>
</x-modal>
