@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'lt-error']) }} style="margin-top: var(--lt-space-1);">{{ $message }}</p>
@enderror
