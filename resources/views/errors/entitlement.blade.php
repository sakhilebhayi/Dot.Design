{{-- R-50: the company does not hold Dot.Design. One action (InfoDot, where it is managed); switching workspace is a quiet second line.
     No design, project or workspace data is rendered here. --}}
@extends('errors._ecosystem-layout', ['code' => 403, 'hideNext' => true])

@section('title', $refusal->title)
@section('message', $refusal->message)

@section('actions')
    <a href="{{ $manageUrl }}" class="lt-btn" rel="noopener">Open Platforms in InfoDot</a>
@endsection

@section('after')
    @if ($workspaces->isNotEmpty())
        <nav aria-label="Switch workspace" class="lt-errorpage__next">
            <p class="lt-small">Or switch to</p>
            <ul>
                @foreach ($workspaces as $workspace)
                    <li>
                        <form method="POST" action="{{ route('current-team.update') }}">
                            @method('PUT') @csrf
                            <input type="hidden" name="team_id" value="{{ $workspace->id }}">
                            <button type="submit">{{ $workspace->name }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
@endsection
