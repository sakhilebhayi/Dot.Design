@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="lt-dialog__head">
        <div style="display: flex; gap: var(--lt-space-3); align-items: flex-start;">
            <span style="color: var(--lt-bad); margin-top: 0.2rem;"><x-ds.icon name="triangle-alert" size="lg" /></span>
            <h3 class="lt-dialog__title">{{ $title }}</h3>
        </div>
    </div>
    <div class="lt-dialog__body">{{ $content }}</div>
    <div class="lt-dialog__foot">{{ $footer }}</div>
</x-modal>
