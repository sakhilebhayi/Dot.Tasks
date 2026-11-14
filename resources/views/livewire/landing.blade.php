<section class="land" aria-labelledby="land-title">
    <div class="land-top">
        {{-- The original logo files, as drawn. Only the size and the space around them are set here. --}}
        <img class="land-logo land-logo-day" src="{{ asset('images/logo.png') }}" alt="Dot.Tasks" width="1181" height="886">
        <img class="land-logo land-logo-night" src="{{ asset('images/logo-light.png') }}" alt="Dot.Tasks" width="1181" height="886">
        <nav class="land-back" aria-label="Dot ecosystem">
            <a href="{{ $infodotHome }}">InfoDot</a>
            <a href="{{ $infodotPlatforms }}">Platforms</a>
        </nav>
    </div>

    <div class="land-body">
        <div class="land-say">
            <h1 id="land-title" class="d-in" style="--i:1" @if($isEmpty) data-land-empty @endif>{{ $sentence }}</h1>
            @if($isEmpty)
                <p class="land-lead d-in" style="--i:2">Welcome, {{ $firstName }}. Dot.Tasks is where {{ $company }} writes work down, gives it to someone and sees it finished.</p>
            @else
                <p class="land-lead d-in" style="--i:2">Welcome, {{ $firstName }}. {{ $open }} {{ $open === 1 ? 'is' : 'are' }} open on your name at {{ $company }}.</p>
            @endif
            <div class="land-do d-in" style="--i:3">
                {{-- With scripts the sentence box opens here; without them the link still goes to the Inbox, where a task can be written. --}}
                <a class="btn primary land-go" href="{{ route('tasks.inbox') }}" data-primary-action data-open-quick-add><x-icon name="plus" /> New task</a>
                <p class="land-note">
                    @if($sharesBoards)
                        It starts in your Inbox, which only you see. Choose a board as you write it to share it with the team.
                    @else
                        It starts in your Inbox, which only you see. You can read every board at {{ $company }}; an editor or admin puts work on them.
                    @endif
                </p>
            </div>
        </div>
        <x-rack-art class="land-art" :cards="$rack" />
    </div>
</section>
