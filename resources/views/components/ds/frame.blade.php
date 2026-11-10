@props(['width', 'height', 'blank' => true, 'src' => null, 'alt' => ''])
{{-- A design page drawn at its true aspect ratio. With a real thumbnail (uploaded by the editor after a save) it shows that picture, lazy loaded;
     without one it is a neutral blank frame, never a fake picture. An image is decorative when the tile already names the design (alt=""). --}}
<span {{ $attributes->class(['lt-frame', 'lt-frame--blank' => $blank && ! $src]) }} style="--w: {{ max(1, (float) $width) }}; --h: {{ max(1, (float) $height) }}">@if ($src)<img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async" width="{{ (int) $width }}" height="{{ (int) $height }}">@else{{ $slot }}@endif</span>
