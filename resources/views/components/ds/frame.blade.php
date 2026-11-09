@props(['width', 'height', 'blank' => true])
{{-- A design page drawn at its true aspect ratio. Until real thumbnails exist this is a neutral blank frame, never a fake picture. --}}
<span {{ $attributes->class(['lt-frame', 'lt-frame--blank' => $blank]) }} style="--w: {{ max(1, (float) $width) }}; --h: {{ max(1, (float) $height) }}">{{ $slot }}</span>
