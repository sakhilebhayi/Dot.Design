@props(['milestone' => null])
{{-- A first-time moment (first design, first file, first approval) as one calm line with a short flourish: the crop marks of the proof
     sheet close in around a yellow seal. Shown once per person (the server remembers), never blocks anything, and is still when
     the visitor prefers less movement. Without script the "Got it" button is a plain form post. --}}
@if ($milestone)
    <section class="lt-milestone" data-milestone data-key="{{ $milestone['key'] }}" data-url="{{ route('design.milestones.seen', $milestone['key']) }}" role="status" aria-label="{{ $milestone['title'] }}">
        <span class="lt-milestone__seal" aria-hidden="true"><x-ds.icon name="check" /></span>
        <div class="lt-milestone__text">
            <p class="lt-milestone__title">{{ $milestone['title'] }}</p>
            <p class="lt-small">{{ $milestone['text'] }}</p>
        </div>
        <form method="post" action="{{ route('design.milestones.seen', $milestone['key']) }}">
            @csrf
            <x-ds.button type="submit" variant="secondary" size="sm" data-milestone-dismiss>Got it</x-ds.button>
        </form>
    </section>
@endif
