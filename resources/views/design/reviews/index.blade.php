@php
    use App\Support\Design\ReviewStatus;
    $tabLinks = collect($tabs)->map(fn ($label, $key) => [
        'href' => route('design.reviews.index', $key === 'assigned' ? [] : ['tab' => $key]),
        'label' => $label.(isset($counts[$key]) && $counts[$key] > 0 ? ' ('.$counts[$key].')' : ''),
        'current' => $tab === $key,
    ])->values()->all();

    $statusFor = function ($review) use ($me) {
        $mineToDecide = $review->reviewer_id === $me->id;
        return match ($review->status) {
            ReviewStatus::Pending => ['accent', $mineToDecide ? 'Waiting for you' : 'Waiting for '.($review->reviewer?->name ?? 'the reviewer')],
            ReviewStatus::Approved => ['ok', 'Approved'],
            ReviewStatus::ChangesRequested => ['warn', 'Changes requested'],
            ReviewStatus::Stale => ['warn', 'Changed after approval'],
            ReviewStatus::Cancelled => ['neutral', 'Cancelled'],
        };
    };
    $empty = [
        'assigned' => ['Nothing is waiting for you', 'When a colleague sends you a design to approve, it appears here. Open it, look at the version they sent, then approve it or ask for changes.'],
        'requested' => ['You have not asked for a review', 'Open a design and choose Review, then Request review. Pick a colleague who can approve, add a note, and track the answer here.'],
        'decisions' => ['No decisions yet', 'Approvals and change requests on designs you can see are listed here, newest first.'],
    ][$tab];
@endphp
<x-app-layout>
    @push('head')
        @vite(['resources/css/pages/reviews.css'])
    @endpush
<div class="lt-page">
    <x-ds.page-header title="Review" sub="Designs waiting for a decision, and what was decided." />

    <x-ds.link-tabs :items="$tabLinks" label="Review lists" />

    @if ($rows->isEmpty())
        <x-ds.empty :title="$empty[0]" class="rv-empty">
            {{ $empty[1] }}
            <x-slot name="actions">
                <x-ds.button :href="route('design.projects.index')" variant="secondary">Go to designs</x-ds.button>
            </x-slot>
        </x-ds.empty>
    @else
        <ul class="rv-list" aria-label="{{ $tabs[$tab] }}">
            @foreach ($rows as $review)
                @php
                    $project = $review->project;
                    [$kind, $label] = $statusFor($review);
                    $thumb = $project ? $thumbnails->url($project) : null;
                    $mineToDecide = $review->reviewer_id === $me->id && $review->status === ReviewStatus::Pending;
                    $when = $review->decided_at ?? $review->created_at;
                @endphp
                <li class="rv-row">
                    <span class="rv-row__stage" aria-hidden="true">
                        @if ($project)
                            <x-ds.frame :width="$project->width ?: 4" :height="$project->height ?: 3" :blank="! $thumb">@if ($thumb)<img src="{{ $thumb }}" alt="" loading="lazy" width="{{ $project->width }}" height="{{ $project->height }}">@endif</x-ds.frame>
                        @endif
                    </span>
                    <div class="rv-row__main">
                        <a class="rv-row__name" href="{{ $project ? route('design.projects.show', $project) : '#' }}">{{ $project?->name ?? 'A design you can no longer open' }}</a>
                        <p class="rv-row__who">
                            @if ($tab === 'requested')
                                Asked {{ $review->reviewer?->name ?? 'a colleague' }}
                            @elseif ($tab === 'assigned')
                                From {{ $review->requester?->name ?? 'a colleague' }}
                            @else
                                {{ $review->requester?->name ?? 'A colleague' }} asked {{ $review->reviewer?->name ?? 'a colleague' }}
                            @endif
                            <span class="rv-row__age"><time datetime="{{ $when?->toIso8601String() }}" title="{{ $when?->format('j M Y, H:i') }}">{{ $when?->diffForHumans() }}</time></span>
                        </p>
                        @if ($review->decision_note || $review->note)
                            <p class="rv-row__note">{{ \Illuminate\Support\Str::limit($review->decision_note ?: $review->note, 140) }}</p>
                        @endif
                    </div>
                    <div class="rv-row__end">
                        <x-ds.tag :kind="$kind">{{ $label }}</x-ds.tag>
                        @if ($project)
                            <x-ds.button :href="route('design.projects.show', $project)" :variant="$mineToDecide ? 'primary' : 'secondary'" size="sm">{{ $mineToDecide ? 'Open and review' : 'Open' }}</x-ds.button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
</x-app-layout>
