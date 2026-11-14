{{-- One table for Team and Business: counts per board, no names. The whole row opens the board. --}}
<section class="d-group" aria-labelledby="g-health-{{ $view }}" style="--g:0">
    <div class="d-head"><h2 id="g-health-{{ $view }}">{{ $title }}</h2><span class="count num">{{ count($boards) }}</span></div>
    @include('livewire.dashboard.board-table', ['boards' => $boards, 'title' => $title])
</section>
