@php
    use App\Support\Ui\ProjectCard;
    use App\Support\Ui\DesignListing;

    $chip = ProjectCard::status($project);
    $brand = $home['brand'];
    $brandUpdated = in_array($project->id, $home['brandUpdated'], true);
    $thumb = ProjectCard::thumbnailUrl($project);
@endphp
{{-- The selected design: its real saved proof at true proportions on the stage, then the facts people check before they open it. --}}
<h2 class="lt-panel__title" id="sel-title" tabindex="-1">{{ $project->name }}</h2>
<div class="lt-panel__stage">
    <x-ds.frame :width="$project->width" :height="$project->height" :src="$thumb" loading="eager" :alt="$thumb ? $project->name.', '.DesignListing::sizeLabel($project).', saved proof' : ''" class="lt-crop lt-panel__sheet" />
</div>
<dl class="lt-facts">
    <div><dt>Size</dt><dd class="lt-num">{{ DesignListing::sizeLabel($project) }}</dd></div>
    <div><dt>Pages</dt><dd class="lt-num">{{ $project->canvases_count }}</dd></div>
    <div><dt>Status</dt><dd>@if ($chip)<x-ds.tag :kind="$chip['kind']">{{ $chip['label'] }}</x-ds.tag>@endif @if ($brandUpdated)<x-ds.tag kind="warn">Brand updated</x-ds.tag>@endif</dd></div>
    <div><dt>Visible to</dt><dd>{{ $project->visibility === 'team' ? 'Workspace' : 'Private' }}</dd></div>
    @if ($project->user)<div><dt>Made by</dt><dd>{{ $project->user->name }}</dd></div>@endif
    <div><dt>Last edit</dt><dd class="lt-num">{{ $project->updated_at?->diffForHumans() ?? 'never' }}</dd></div>
    @if ($brand)<div><dt>Workspace brand</dt><dd>{{ $brand['name'] }}@if ($brand['version']), version {{ $brand['version'] }}@endif</dd></div>@endif
</dl>
<div class="lt-panel__acts">
    <x-ds.button :href="route('design.projects.show', $project)" variant="secondary">Open in the editor</x-ds.button>
    <a class="lt-arrive__link" href="{{ route('design.projects.versions.index', $project) }}">Versions</a>
    <a class="lt-arrive__link" href="{{ route('design.projects.reviews.index', $project) }}">Reviews</a>
    @if ($home['canShare'])<a class="lt-arrive__link" href="{{ route('design.projects.shares.index', $project) }}">Share</a>@endif
</div>
