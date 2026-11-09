@props(['id', 'title', 'wide' => false])
{{-- Native <dialog>: focus is trapped and Escape closes it by the browser. Open with data-lt-dialog-open="{id}". --}}
<dialog id="{{ $id }}" class="lt-dialog" aria-labelledby="{{ $id }}-title">
    <div class="lt-dialog__wrap">
        <div {{ $attributes->class(['lt-dialog__box', 'lt-dialog__box--wide' => $wide]) }}>
            <div class="lt-dialog__head">
                <h2 id="{{ $id }}-title" class="lt-dialog__title">{{ $title }}</h2>
                <x-ds.icon-button icon="x" label="Close" data-lt-dialog-close />
            </div>
            <div class="lt-dialog__body">{{ $slot }}</div>
            @isset($footer)<div class="lt-dialog__foot">{{ $footer }}</div>@endisset
        </div>
    </div>
</dialog>
