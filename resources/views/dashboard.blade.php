@extends('layouts.app')
@section('title', 'Dashboard')
@section('crumb', 'Dashboard')

@section('content')
<div class="page-head">
  <div>
    <h1>Good morning, {{ explode(' ', auth()->user()->full_name)[0] }} 👋</h1>
    <div class="page-sub">{{ $scoped ? "Your teams' status" : "Organization-wide status" }} across {{ $stats['active_projects'] }} active projects · {{ now()->format('l, j M Y') }}</div>
  </div>
  <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
    <a href="{{ route('tasks.index', ['view' => 'kanban']) }}" class="btn btn-ghost" style="font-size:12.5px;">📊 Kanban Board</a>
    @if (auth()->user()->canCreateProjects())
      <a href="{{ route('projects.create') }}" class="btn btn-accent" style="font-weight:700;">+ New Project</a>
    @endif
  </div>
</div>

{{--
  User Access & Approvals is an organization-wide administration widget.

  This must be a strict role check, NOT @can('manage_users'): the Head of
  Office and Head of Department roles are seeded with wildcard permissions
  (Permissions::DEFAULT_ROLES => 'permissions' => '*'), so a permission check
  evaluates true for them. The role names below are the only ones treated as
  system administrators (see User::canAccessGlobalScope()).
--}}
@if ($userApprovalStats && (auth()->user()->hasRole('Administrator') || auth()->user()->hasRole('Super Admin')))
  <!-- User Governance & Approval Section (Requirement 9) -->
  <div class="card card-pad" style="margin-bottom:20px; background:var(--surface); border:1px solid var(--line);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:16px;">👥</span>
        <h3 style="margin:0; font-size:14px; font-weight:700; color:var(--ink);">User Access &amp; Approvals</h3>
      </div>
      <a href="{{ route('admin.users.index') }}" class="link-small" style="font-size:12px;">Manage All Users →</a>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:12px;">
      <a href="{{ route('admin.users.index') }}" style="text-decoration:none; color:inherit; background:var(--bg-subtle); border:1px solid var(--line); border-radius:8px; padding:12px 14px; display:block;">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted);">Total Users</div>
        <div style="font-size:22px; font-weight:800; color:var(--ink); margin-top:4px;">{{ $userApprovalStats['total'] }}</div>
      </a>
      <a href="{{ route('admin.users.index', ['status' => 'Active']) }}" style="text-decoration:none; color:inherit; background:var(--bg-subtle); border:1px solid var(--line); border-radius:8px; padding:12px 14px; display:block;">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--success);">Active Users</div>
        <div style="font-size:22px; font-weight:800; color:var(--success); margin-top:4px;">{{ $userApprovalStats['active'] }}</div>
      </a>
      <a href="{{ route('admin.users.index', ['status' => 'Pending']) }}" style="text-decoration:none; color:inherit; background:{{ $userApprovalStats['pending'] > 0 ? '#eff6ff' : 'var(--bg-subtle)' }}; border:1px solid {{ $userApprovalStats['pending'] > 0 ? '#93c5fd' : 'var(--line)' }}; border-radius:8px; padding:12px 14px; display:block;">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:{{ $userApprovalStats['pending'] > 0 ? '#1d4ed8' : 'var(--ink-muted)' }};">Pending Approval</div>
        <div style="font-size:22px; font-weight:800; color:{{ $userApprovalStats['pending'] > 0 ? '#1d4ed8' : 'var(--ink)' }}; margin-top:4px;">
          {{ $userApprovalStats['pending'] }}
          @if ($userApprovalStats['pending'] > 0)
            <span style="font-size:11px; font-weight:600; background:#dbeafe; padding:2px 6px; border-radius:4px; vertical-align:middle;">Action needed</span>
          @endif
        </div>
      </a>
      <a href="{{ route('admin.users.index', ['status' => 'Rejected']) }}" style="text-decoration:none; color:inherit; background:var(--bg-subtle); border:1px solid var(--line); border-radius:8px; padding:12px 14px; display:block;">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--danger);">Rejected Users</div>
        <div style="font-size:22px; font-weight:800; color:var(--danger); margin-top:4px;">{{ $userApprovalStats['rejected'] }}</div>
      </a>
        </div>
     </div>
@endif
@if ($officeStats)
@php
  $ob = $officeStats['budget'];
  $obUtil = $ob['allocated'] > 0 ? min(100, round($ob['spent'] / $ob['allocated'] * 100)) : 0;
  $snapTiles = [
    ['icon' => '👥', 'label' => 'Teams', 'value' => $officeStats['teams']],
    ['icon' => '👤', 'label' => 'Users', 'value' => $officeStats['users']],
    ['icon' => '📁', 'label' => 'Projects', 'value' => $officeStats['projects']],
    ['icon' => '🚀', 'label' => 'Active', 'value' => $officeStats['active_projects']],
    ['icon' => '✅', 'label' => 'Completed', 'value' => $officeStats['completed_projects']],
  ];
@endphp
<!-- Office Snapshot -->
<div class="card card-pad" style="margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; gap:10px; flex-wrap:wrap;">
    <div style="display:flex; align-items:center; gap:10px;">
      <div style="width:34px; height:34px; border-radius:9px; background:color-mix(in srgb, var(--accent) 12%, transparent); display:flex; align-items:center; justify-content:center; font-size:16px;">🏢</div>
      <div>
        <h3 style="margin:0; font-size:14.5px; color:var(--ink);">{{ $officeStats['name'] }}</h3>
        <div style="font-size:11.5px; color:var(--ink-soft); margin-top:1px;">Office snapshot</div>
      </div>
    </div>
    @can('view_offices')
      <a class="link-small" href="{{ route('admin.offices.index') }}">Office management →</a>
    @endcan
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(96px, 1fr)); gap:10px; margin-bottom:14px;">
    @foreach ($snapTiles as $tile)
      <div style="background:var(--bg-card, var(--surface)); border:1px solid var(--line); border-radius:10px; padding:12px 14px;">
        <div style="font-size:14px; margin-bottom:6px;">{{ $tile['icon'] }}</div>
        <div style="font-size:20px; font-weight:700; color:var(--ink); font-family:'Space Grotesk', sans-serif; line-height:1;">{{ $tile['value'] }}</div>
        <div style="font-size:11px; color:var(--ink-soft); margin-top:5px; text-transform:uppercase; letter-spacing:.05em;">{{ $tile['label'] }}</div>
      </div>
    @endforeach
  </div>

  @can('view_budgets')
    <div style="background:var(--bg-card, var(--surface)); border:1px solid var(--line); border-radius:10px; padding:14px 16px;">
      <div style="display:flex; justify-content:space-between; align-items:baseline; gap:10px; flex-wrap:wrap; margin-bottom:9px;">
        <span style="font-size:11.5px; font-weight:600; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.05em;">💰 Budget</span>
        <span class="mono" style="font-size:12px; color:var(--ink-soft);">
          <b style="color:var(--ink); font-size:13px;">ETB {{ number_format($ob['allocated']) }}</b> allocated
        </span>
      </div>
      <div class="progressbar {{ $obUtil >= 90 ? 'danger' : ($obUtil >= 70 ? 'warn' : '') }}" style="height:7px; border-radius:5px;"><div style="width:{{ $obUtil }}%"></div></div>
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:9px; font-size:12px;">
        <span style="color:var(--ink-soft);">
          Spent <b class="mono" style="color:var(--ink);">ETB {{ number_format($ob['spent']) }}</b>
          <span style="color:var(--ink-faint);">({{ $obUtil }}%)</span>
        </span>
        <span style="color:var(--ink-soft);">
          Remaining <b class="mono" style="color:var(--success);">ETB {{ number_format($ob['remaining']) }}</b>
        </span>
      </div>
    </div>
  @endcan
</div>
@endif

<!-- Key Stat Cards -->
<div class="grid grid-4 stat-card-grid">
  <x-stat-card title="Active Projects" :value="$stats['active_projects']" icon="📁" :delta="$scoped ? 'Across your team(s)' : 'Across all departments'" />

  <x-stat-card title="Open Tasks" :value="$stats['open_tasks']" icon="🗒️" :delta="$stats['overdue_tasks'].' overdue'" :delta-class="$stats['overdue_tasks'] > 0 ? 'down' : ''" />

  @php $util = $stats['budget_allocated'] > 0 ? round($stats['budget_spent'] / $stats['budget_allocated'] * 100) : 0; @endphp
  <x-stat-card title="Budget Utilised" :value="$util.'%'" icon="💰" :delta="'ETB '.number_format($stats['budget_spent']).' of '.number_format($stats['budget_allocated'])" />

  <x-stat-card
    title="Action Items"
    :value="$stats['overdue_tasks'] + $stats['pending_change_requests']"
    icon="⚠️"
    :color="($stats['overdue_tasks'] + $stats['pending_change_requests']) > 0 ? 'var(--danger)' : 'var(--success)'"
    :delta="$stats['pending_change_requests'].' change requests pending'"
    delta-class="down"
  />
</div>

<!-- Attention Required Alert (If overdue or blocked tasks exist) -->
@if ($overdueTasksList->isNotEmpty() || $blockedTasksList->isNotEmpty())
  <div class="card card-pad" style="margin-bottom:20px; background:#fff7ed; border:1.5px solid #fed7aa; border-radius:10px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:16px;">⚠️</span>
        <strong style="color:#9a3412; font-size:14px;">Items Requiring Attention</strong>
      </div>
      <span class="badge b-risk" style="font-size:11px;">{{ $overdueTasksList->count() + $blockedTasksList->count() }} item(s)</span>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:10px;">
      @foreach ($overdueTasksList->take(3) as $ot)
        <div onclick="openTask({{ $ot->task_id }})" style="background:#fff; border:1px solid #fdba74; border-radius:6px; padding:8px 12px; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-weight:600; font-size:12.5px; color:#7c2d12;">{{ $ot->task_name }}</div>
            <div style="font-size:11px; color:#9a3412;">📁 {{ optional($ot->project)->project_name }}</div>
          </div>
          <span class="badge b-blocked" style="font-size:10px;">Overdue</span>
        </div>
      @endforeach

      @foreach ($blockedTasksList->take(2) as $bt)
        <div onclick="openTask({{ $bt->task_id }})" style="background:#fff; border:1px solid #fdba74; border-radius:6px; padding:8px 12px; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-weight:600; font-size:12.5px; color:#7c2d12;">{{ $bt->task_name }}</div>
            <div style="font-size:11px; color:#9a3412;">📁 {{ optional($bt->project)->project_name }}</div>
          </div>
          <span class="badge b-blocked" style="font-size:10px;">Blocked</span>
        </div>
      @endforeach
    </div>
  </div>
@endif

<div class="two-col">
  <div style="display:flex; flex-direction:column; gap:18px;">
    <!-- Active Projects Portfolio -->
    <div class="card card-pad">
      <div class="card-title-row">
        <h3>{{ $scoped ? "Your Team's Active Projects" : "Projects in Progress" }}</h3>
        <a class="link-small" href="{{ route('projects.index') }}">View all projects →</a>
      </div>
      @if ($projects->isEmpty())
        <div class="empty"><h4>No projects yet</h4>{{ $scoped ? "Nothing assigned to your team so far." : "" }}</div>
      @else
        <table><tbody>
          @foreach ($projects as $p)
            @php
              $pProg = $p->progressPercentage();
              $b = $p->budget;
            @endphp
            <tr onclick="window.location='{{ route('projects.show', $p) }}'" style="cursor:pointer;">
              <td>
                <div class="cell-primary" style="display:flex; align-items:center; gap:6px;">
                  <span>{{ $p->project_name }}</span>
                  <span class="priority p-{{ strtolower($p->priority ?: 'medium') }}" style="font-size:9.5px;">{{ $p->priority ?: 'Medium' }}</span>
                </div>
                <div class="cell-sub mono">
                  PRJ-{{ str_pad($p->project_id, 3, '0', STR_PAD_LEFT) }}
                  @if ($p->client) · <span style="color:var(--accent);">🏢 {{ $p->client }}</span>@endif
                </div>
              </td>
              <td style="width:130px;">
                <div style="font-size:11.5px; font-weight:600; color:var(--ink-soft); margin-bottom:3px;">{{ $pProg }}% Done</div>
                <div class="progressbar"><div style="width:{{ $pProg }}%"></div></div>
              </td>
              <td class="cell-align-right">
                <x-status-badge :status="$p->status" />
              </td>
            </tr>
          @endforeach
        </tbody></table>
      @endif
    </div>

    <!-- My Priority Assigned Tasks Widget -->
    <div class="card card-pad">
      <div class="card-title-row">
        <h3>My Assigned Tasks ({{ $myAssignedTasks->count() }})</h3>
        <a class="link-small" href="{{ route('tasks.index', ['filter' => 'mine']) }}">Open task board →</a>
      </div>

      @forelse ($myAssignedTasks as $mt)
        @php
          $late = $mt->end_date && $mt->end_date->isPast();
          $proj = $mt->project ?? optional($mt->phase)->project;
        @endphp
        <div class="list-row" onclick="openTask({{ $mt->task_id }})" style="cursor:pointer; padding:10px 0; border-bottom:1px solid var(--line);">
          <div style="display:flex; align-items:center; gap:10px; flex:1;">
            <div style="width:8px; height:8px; border-radius:50%; background:{{ $mt->priority === 'High' ? 'var(--danger)' : 'var(--accent)' }};"></div>
            <div>
              <div style="font-weight:600; font-size:13px; color:var(--ink);">{{ $mt->task_name }}</div>
              <div class="cell-sub" style="font-size:11.5px;">
                {{ optional($proj)->project_name ?? 'Task' }}
                @if ($mt->end_date)
                  · <span class="{{ $late ? 'late' : '' }}">Due {{ $mt->end_date->format('M d') }}</span>
                @endif
              </div>
            </div>
          </div>

          <div class="cell-align-right">
            <x-status-badge :status="$mt->status" />
          </div>
        </div>
      @empty
        <div style="text-align:center; padding:20px; color:var(--ink-faint); font-size:12.5px;">
          🎉 All caught up! No pending tasks assigned to you right now.
        </div>
      @endforelse
    </div>
  </div>

  <div style="display:flex; flex-direction:column; gap:16px;">
    <!-- Team Load -->
    <div class="card card-pad">
      <div class="card-title-row">
        <h3>{{ $scoped ? "Your Team's Workload" : "Team Workload & Capacity" }}</h3>
        <a class="link-small" href="{{ route('reports.index') }}">Full reports →</a>
      </div>
      @foreach ($teamLoad as $u)
        @php $pct = min(100, $u->open_task_count * 20); @endphp
        <div style="margin-bottom:13px;">
          <div style="display:flex; justify-content:space-between; font-size:12.6px; margin-bottom:5px;">
            <span style="font-weight:600;">{{ $u->full_name }}</span>
            <span class="mono" style="color:var(--ink-soft); font-size:12px;">{{ $u->open_task_count }} open</span>
          </div>
          <div class="progressbar {{ $pct>=90?'danger':($pct>=70?'warn':'') }}"><div style="width:{{ $pct }}%"></div></div>
        </div>
      @endforeach
    </div>

    <!-- Recent Activity Stream -->
    <div class="card card-pad">
      <div class="card-title-row">
        <h3>Recent Activity</h3>
        @can('view_audit_logs')
          <a class="link-small" href="{{ route('admin.audit') }}">Audit log →</a>
        @endcan
      </div>
      @foreach ($activity as $a)
        <div class="activity-row">
          <div class="activity-icon">📝</div>
          <div>
            <div class="activity-txt"><b>{{ optional($a->user)->full_name ?? 'System' }}</b> {{ $a->action }} — {{ $a->entity_type }}</div>
            <div class="activity-time">{{ $a->timestamp->diffForHumans() }}</div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</div>
@endsection
