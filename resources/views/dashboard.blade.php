<x-app-layout>
    <div class="page-pad">

        <!-- Page header -->
        <div class="page-head">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Dashboard</h1>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">Manage your task lists and track progress</p>
            </div>
            @can('create', \App\Models\TaskList::class)
            <a href="{{ route('task-lists.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;border-radius:9999px;background:linear-gradient(135deg,#2962ff,#004ee8);padding:0.65rem 1.25rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#f7f5ff;text-decoration:none;box-shadow:0 6px 18px rgba(41,98,255,0.3);">
                <span class="material-symbols-rounded" style="font-size:18px;">add_circle</span>
                New List
            </a>
            @endcan
        </div>

        <!-- KPI row -->
        <div class="kpi-grid" style="margin-bottom:1.25rem;">
            <!-- Total Lists -->
            <div class="kpi-tile">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Total Lists</span>
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(41,98,255,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:#2962ff;">checklist</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;">{{ $lists->count() }}</div>
            </div>

            <!-- Total Tasks -->
            <div class="kpi-tile">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Total Tasks</span>
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(124,58,237,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:#7c3aed;">task_alt</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;">{{ $taskCounts['total'] }}</div>
            </div>

            <!-- In Progress -->
            <div class="kpi-tile">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">In Progress</span>
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(217,119,6,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:#d97706;">autorenew</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;">{{ $taskCounts['in_progress'] }}</div>
            </div>

            <!-- Completed -->
            <div class="kpi-tile">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Completed</span>
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(5,150,105,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:#059669;">check_circle</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;">{{ $taskCounts['done'] }}</div>
            </div>
        </div>

        <!-- Secondary KPI row: due-date awareness -->
        <div class="kpi-grid" style="margin-bottom:2.5rem;">
            <!-- Due Today -->
            <div class="kpi-tile">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Due Today</span>
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:#3b82f6;">today</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;">{{ $taskCounts['due_today'] }}</div>
            </div>

            <!-- Overdue -->
            <div class="kpi-tile">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Overdue</span>
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(239,68,68,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:#ef4444;">error</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:{{ $taskCounts['overdue'] > 0 ? '#ef4444' : '#f4f4f5' }};">{{ $taskCounts['overdue'] }}</div>
            </div>

            <!-- Completed This Week -->
            <div class="kpi-tile">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Completed This Week</span>
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:#22c55e;">celebration</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f4f4f5;">{{ $taskCounts['completed_week'] }}</div>
            </div>
        </div>

        <!-- Lists section heading -->
        <div style="margin-bottom:1.25rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#a1a1aa;margin:0;">Your Lists</h2>
        </div>

        @if($lists->isEmpty())
            <!-- Empty state -->
            <div style="background:rgba(20,20,22,0.9);border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:4rem 2rem;text-align:center;">
                <div style="width:56px;height:56px;border-radius:14px;background:rgba(41,98,255,0.12);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                    <span class="material-symbols-rounded" style="font-size:28px;color:#2962ff;">format_list_bulleted_add</span>
                </div>
                <p style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 0.5rem;">No task lists yet</p>
                @can('create', \App\Models\TaskList::class)
                <p style="font-size:0.8rem;color:#71717a;margin:0 0 1.5rem;">Create your first list to start organising tasks.</p>
                <a href="{{ route('task-lists.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;border-radius:9999px;background:linear-gradient(135deg,#2962ff,#004ee8);padding:0.65rem 1.4rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#f7f5ff;text-decoration:none;">
                    <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                    Create your first list
                </a>
                @else
                    <p style="font-size:0.8rem;color:#71717a;margin:0;">An editor or admin on this team creates the boards. They will appear here.</p>
                @endcan
            </div>
        @else
            <!-- Lists grid -->
            <div class="kpi-grid list-grid">
                @foreach($lists as $list)
                <a href="{{ route('task-lists.show', $list) }}" class="list-card">
                    <!-- Color bar at top -->
                    <div style="height:3px;border-radius:9999px;background:{{ $list->color ?? '#2962ff' }};margin-bottom:1.25rem;"></div>

                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;margin-bottom:0.5rem;">
                        <h3 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $list->name }}</h3>
                        <div style="width:10px;height:10px;border-radius:9999px;background:{{ $list->color ?? '#2962ff' }};flex-shrink:0;margin-top:4px;"></div>
                    </div>

                    @if($list->description)
                    <p style="font-size:0.78rem;color:#71717a;margin:0 0 1rem;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">{{ $list->description }}</p>
                    @else
                    <div style="margin-bottom:1rem;"></div>
                    @endif

                    <div style="display:flex;align-items:center;gap:0.4rem;">
                        <span class="material-symbols-rounded" style="font-size:14px;color:#71717a;">task_alt</span>
                        <span style="font-size:0.72rem;color:#71717a;font-weight:600;">{{ $list->tasks_count }} {{ Str::plural('task', $list->tasks_count) }}</span>
                    </div>
                </a>
                @endforeach
            </div>
        @endif

        <livewire:tasks.archived-boards />
    </div>
</x-app-layout>
