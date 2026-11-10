@props(['label', 'name', 'type' => 'text', 'hint' => null, 'error' => null, 'value' => null, 'as' => 'input', 'id' => null, 'icon' => null])
@php
    $id = $id ?: 'f-'.str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $message = $error ?? ($errors->has(str_replace(['[', ']'], ['.', ''], $name)) ? $errors->first(str_replace(['[', ']'], ['.', ''], $name)) : null);
    $describedBy = collect([$hint ? $id.'-hint' : null, $message ? $id.'-error' : null])->filter()->implode(' ');
    $current = old(str_replace(['[', ']'], ['.', ''], $name), $value);
@endphp
<div class="lt-field @if($message) has-error @endif">
    <label for="{{ $id }}" class="lt-label">{{ $label }}</label>
    @if ($as === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif {{ $attributes->class(['lt-textarea']) }}>{{ $current }}</textarea>
    @elseif ($icon)
        <div class="lt-input-wrap"><x-ds.icon :name="$icon" /><input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ $current }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif {{ $attributes->class(['lt-input']) }}></div>
    @else
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" @if($type !== 'password') value="{{ $current }}" @endif @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif {{ $attributes->class(['lt-input']) }}>
    @endif
    @if ($hint)<p id="{{ $id }}-hint" class="lt-hint">{{ $hint }}</p>@endif
    @if ($message)<p id="{{ $id }}-error" class="lt-error">{{ $message }}</p>@endif
</div>
