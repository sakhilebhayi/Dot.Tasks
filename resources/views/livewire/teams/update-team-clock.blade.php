<form wire:submit="save" class="panel" style="margin-bottom:24px">
    <h2 class="section-title" style="margin-top:0">Time zone</h2>
    <p style="color:var(--muted);margin-bottom:10px">Decides when today ends for due dates, My Day and repeating tasks on this team.</p>
    <div class="step-add">
        <label class="sr" for="team-timezone">Time zone</label>
        <select class="select" id="team-timezone" wire:model="timezone" @cannot('update', $team) disabled @endcannot>
            @foreach($zones as $zone)
                <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
            @endforeach
        </select>
        @can('update', $team)
            <button type="submit" class="btn">Save</button>
        @endcan
    </div>
    @error('timezone')<p class="error">{{ $message }}</p>@enderror
    @if($saved)<p role="status" style="margin-top:6px;color:var(--muted)">Saved.</p>@endif
</form>
