@php $plan = $this->data['plan']; @endphp
<div class="d-dayline">
    <span>Planned today <b class="num">{{ $plan['total'] }}</b></span>
    <span>Done of those <b class="num">{{ $plan['done'] }}</b></span>
    <a class="link" href="{{ route('tasks.today') }}">Open My Day</a>
</div>
