@php $plan = $this->data['plan']; @endphp
<div class="widget-figures">
    <div class="widget-figure"><span>Planned today</span><b>{{ $plan['total'] }}</b></div>
    <div class="widget-figure"><span>Done of those</span><b>{{ $plan['done'] }}</b></div>
    <div class="widget-figure"><span>My open tasks</span><b>{{ $this->data['mine'] }}</b></div>
</div>
<p style="margin-top:8px"><a class="link" href="{{ route('tasks.today') }}">Open My Day</a></p>
