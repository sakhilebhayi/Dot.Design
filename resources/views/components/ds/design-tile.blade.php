@props(['project', 'brandUpdated' => false])
{{-- One design on a contact strip: the real saved thumbnail at the design's true proportions (neutral frame when none is saved yet),
     name, size, last edit, and a status chip only when it says something. Home, Start and the designs grid use this one tile. --}}
@php($chip = \App\Support\Ui\ProjectCard::status($project))
<a href="{{ route('design.projects.show', $project) }}" {{ $attributes->class(['lt-contact']) }}>
    <span class="lt-contact__stage"><x-ds.frame :width="$project->width" :height="$project->height" :src="\App\Support\Ui\ProjectCard::thumbnailUrl($project)" class="lt-crop lt-crop--link" /></span>
    <span class="lt-contact__meta">
        <span class="lt-contact__name">{{ $project->name }}</span>
        <span class="lt-contact__sub">{{ \App\Support\Ui\DesignListing::sizeLabel($project) }}</span>
        <span class="lt-contact__sub">Edited {{ $project->updated_at?->diffForHumans() ?? 'never' }}</span>
        @if (($chip && $chip['label'] !== 'Draft') || $brandUpdated)
            <span class="lt-contact__chips">
                @if ($chip && $chip['label'] !== 'Draft')<x-ds.tag :kind="$chip['kind']">@if ($chip['label'] === 'Approved')<span class="lt-seal" aria-hidden="true"><x-ds.icon name="check" /></span>@endif{{ $chip['label'] }}</x-ds.tag>@endif
                @if ($brandUpdated)<x-ds.tag kind="warn">Brand updated</x-ds.tag>@endif
            </span>
        @endif
    </span>
</a>
