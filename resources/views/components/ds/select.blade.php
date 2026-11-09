@props(['label', 'name', 'options' => [], 'selected' => null, 'hint' => null, 'error' => null, 'id' => null, 'placeholder' => null])
@php
    $id = $id ?: 'f-'.str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $dot = str_replace(['[', ']'], ['.', ''], $name);
    $message = $error ?? ($errors->has($dot) ? $errors->first($dot) : null);
    $current = (string) old($dot, $selected);
@endphp
<div class="lt-field @if($message) has-error @endif">
    <label for="{{ $id }}" class="lt-label">{{ $label }}</label>
    <select id="{{ $id }}" name="{{ $name }}" @if($message) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif {{ $attributes->class(['lt-select']) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) $value === $current)>{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($hint)<p class="lt-hint">{{ $hint }}</p>@endif
    @if ($message)<p id="{{ $id }}-error" class="lt-error">{{ $message }}</p>@endif
</div>
