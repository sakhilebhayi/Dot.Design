@php
    $query = array_filter($filters, fn ($v) => $v !== null && $v !== '');
@endphp
<x-app-layout>
    @push('head')
        @vite(['resources/css/pages/activity.css'])
    @endpush
<div class="lt-page">
    <x-ds.page-header title="Activity" :sub="$team ? 'What happened in '.$team->name.'. Visible to owners and admins.' : 'What happened in this workspace.'" />

    <form method="GET" action="{{ route('design.activity.page') }}" class="ac-filters" aria-label="Filter activity">
        <input type="hidden" name="team_id" value="{{ $teamId }}">
        <div class="lt-field">
            <label class="lt-label" for="ac-action">What happened</label>
            <select id="ac-action" name="action" class="lt-select">
                <option value="">Anything</option>
                @foreach ($actions as $key => $label)
                    <option value="{{ $key }}" @selected($filters['action'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="lt-field">
            <label class="lt-label" for="ac-project">Design</label>
            <select id="ac-project" name="project_id" class="lt-select">
                <option value="">All designs</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected((int) $filters['project_id'] === $project->id)>{{ \Illuminate\Support\Str::limit($project->name, 48) }}</option>
                @endforeach
            </select>
        </div>
        <div class="lt-field">
            <label class="lt-label" for="ac-from">From</label>
            <input id="ac-from" class="lt-input" type="date" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="lt-field">
            <label class="lt-label" for="ac-to">To</label>
            <input id="ac-to" class="lt-input" type="date" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="ac-filters__end">
            <x-ds.button type="submit" variant="secondary">Apply</x-ds.button>
            @if (count($query) > 0)<x-ds.button :href="route('design.activity.page', ['team_id' => $teamId])" variant="ghost">Clear</x-ds.button>@endif
        </div>
    </form>

    @if ($items->isEmpty())
        <x-ds.empty :title="count($query) > 0 ? 'Nothing matches those filters' : 'No activity yet'">
            @if (count($query) > 0)
                Try a wider date range or a different kind of event.
            @else
                Sharing, reviews, restores, exports and deletions are recorded here as they happen. Comment text, link addresses and email addresses are never kept in this list.
            @endif
        </x-ds.empty>
    @else
        <ol class="ac-list" aria-label="Activity, newest first">
            @foreach ($items as $item)
                <li class="ac-row">
                    <time class="ac-row__when" datetime="{{ $item['when']?->toIso8601String() }}" title="{{ $item['when']?->format('j M Y, H:i') }}">{{ $item['when']?->diffForHumans() }}</time>
                    <p class="ac-row__text">{{ $item['sentence'] }}</p>
                    @if ($item['project_exists'])
                        <a class="ac-row__link" href="{{ route('design.projects.show', $item['project_id']) }}">Open design</a>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($next)
            <p class="ac-more"><x-ds.button :href="route('design.activity.page', $query + ['team_id' => $teamId, 'cursor' => $next])" variant="secondary">Older activity</x-ds.button></p>
        @endif
    @endif
</div>
</x-app-layout>
