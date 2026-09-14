@extends('layouts.app')
@section('title', $filter === 'all' ? 'All Tasks' : 'My Tasks')
@section('crumb', $filter === 'all' ? 'All Tasks' : 'My Tasks')

@section('content')
<div x-data="{ viewMode: '{{ $view }}' }">
  <div class="page-head">
    <div>
      <h1>{{ $filter === 'all' ? 'All Tasks' : 'My Tasks' }}</h1>
      <div class="page-sub">{{ $filter === 'all' ? 'All tasks across all projects and assigned teams' : 'Everything assigned to you, across every project and team' }}</div>
    </div>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <span class="stat-label" style="margin:0;">View:</span>
      <button type="button" class="btn" :class="viewMode === 'kanban' ? 'btn-primary' : 'btn-ghost'" @click="viewMode = 'kanban'" style="padding:5px 12px; font-size:12.5px;">
        📊 Kanban
      </button>
      <button type="button" class="btn" :class="viewMode === 'list' ? 'btn-primary' : 'btn-ghost'" @click="viewMode = 'list'" style="padding:5px 12px; font-size:12.5px;">
        ☰ List
      </button>

      @can('create_tasks')
        <div x-data="{ showNewTask: {{ $errors->any() ? 'true' : 'false' }} }" style="position:relative;">
          <button class="btn btn-accent" @click="showNewTask = !showNewTask" x-text="showNewTask ? 'Cancel' : '+ New Task'" style="padding:5px 14px; font-size:12.5px; font-weight:700;"></button>

          <div class="card card-pad" x-show="showNewTask" x-cloak x-transition style="margin-top:12px; position:absolute; right:0; z-index:100; width:500px; max-width:90vw; box-shadow: 0 10px 30px rgba(0,0,0,0.18);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid var(--line); padding-bottom:10px;">
              <h3 style="margin:0; font-size:15px; font-weight:700;">Create New Task</h3>
              <button type="button" @click="showNewTask = false" style="background:none; border:none; color:var(--ink-faint); cursor:pointer; font-size:18px; line-height:1;" title="Close">&times;</button>
            </div>
            <form method="POST" action="{{ route('tasks.store') }}">
              @csrf
              <div class="form-grid">
                <div class="form-field" style="grid-column:1 / -1;">
                  <label for="task_name">Task Title <span style="color:var(--danger);">*</span></label>
                  <input type="text" id="task_name" name="task_name" value="{{ old('task_name') }}" required autofocus placeholder="e.g. Build Payment Gateway Integration">
                </div>

                <div class="form-field">
                  <label for="project_id">Project <span style="color:var(--danger);">*</span></label>
                  <select id="project_id" name="project_id" required onchange="updateNewTaskPhases(this.value)">
                    <option value="">— Select Project —</option>
                    @foreach ($projects as $prj)
                      <option value="{{ $prj->project_id }}" {{ old('project_id', $projectId) == $prj->project_id ? 'selected' : '' }}>
                        {{ $prj->project_name }}
                      </option>
                    @endforeach
                  </select>
                </div>

                <div class="form-field">
                  <label for="team_id">Assigned Team</label>
                  <select id="team_id" name="team_id">
                    <option value="">— Select Team —</option>
                    @foreach ($teams as $tm)
                      <option value="{{ $tm->team_id }}" {{ old('team_id', $teamId) == $tm->team_id ? 'selected' : '' }}>
                        {{ $tm->team_name }}
                      </option>
                    @endforeach
                  </select>
                </div>

                <div class="form-field">
                  <label for="task_phase_id">Phase</label>
                  <select id="task_phase_id" name="phase_id" data-old-phase="{{ old('phase_id') }}">
                    <option value="">— Select Phase —</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="assigned_to">Assignee</label>
                  <input
                    type="text"
                    id="assigned_to"
                    name="assigned_to"
                    list="tasks-modal-assignees-list"
                    value="{{ old('assigned_to') }}"
                    placeholder="Type or select member..."
                    autocomplete="off"
                  >
                  <datalist id="tasks-modal-assignees-list">
                    @foreach ($assignableUsers as $au)
                      <option value="{{ $au->full_name }}">{{ $au->full_name }} ({{ $au->department ?: 'Member' }})</option>
                    @endforeach
                  </datalist>
                </div>

                <div class="form-field">
                  <label for="priority">Priority</label>
                  <select id="priority" name="priority" required>
                    <option value="High" {{ old('priority') === 'High' ? 'selected' : '' }}>High</option>
                    <option value="Medium" {{ old('priority', 'Medium') === 'Medium' ? 'selected' : '' }}>Medium</option>
                    <option value="Low" {{ old('priority') === 'Low' ? 'selected' : '' }}>Low</option>
                    <option value="Urgent" {{ old('priority') === 'Urgent' ? 'selected' : '' }}>Urgent</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="status">Status</label>
                  <select id="status" name="status" required>
                    <option value="To Do" {{ old('status', 'To Do') === 'To Do' ? 'selected' : '' }}>To Do</option>
                    <option value="In Progress" {{ old('status') === 'In Progress' ? 'selected' : '' }}>In Progress</option>
                    <option value="In Review" {{ old('status') === 'In Review' ? 'selected' : '' }}>In Review</option>
                    <option value="Completed" {{ old('status') === 'Completed' ? 'selected' : '' }}>Completed</option>
                    <option value="Blocked" {{ old('status') === 'Blocked' ? 'selected' : '' }}>Blocked</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="task_budget">Task Budget (ETB)</label>
                  <input type="number" step="0.01" min="0" id="task_budget" name="budget" value="{{ old('budget') }}" placeholder="e.g. 25000">
                </div>

                <div class="form-field">
                  <label for="end_date">Due Date</label>
                  <input type="date" id="end_date" name="end_date" value="{{ old('end_date') }}">
                </div>
              </div>

              <div class="form-field" style="margin-top:8px;">
                <label for="task_description">Description (optional)</label>
                <textarea id="task_description" name="description" placeholder="Requirements, specifications, acceptance criteria..." style="min-height:60px;">{{ old('description') }}</textarea>
              </div>

              <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px; border-top:1px solid var(--line); padding-top:10px;">
                <button type="button" class="btn btn-ghost" @click="showNewTask = false">Cancel</button>
                <button type="submit" class="btn btn-accent" style="font-weight:700;">Add Task</button>
              </div>
            </form>
          </div>
        </div>
      @endcan
    </div>
  </div>

  <!-- Filter Controls -->
  <div class="filter-row" style="margin-bottom:18px; flex-wrap:wrap; gap:8px;">
     <a href="{{ route('tasks.index', array_filter(['filter' => 'mine', 'view' => $view, 'status' => $status, 'priority' => $priority, 'project' => $projectId, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}" class="pill {{ $filter !== 'all' ? 'active' : '' }}">My Tasks ({{ $myCount }})</a>
     @can('view_projects')
       <a href="{{ route('tasks.index', array_filter(['filter' => 'all', 'view' => $view, 'status' => $status, 'priority' => $priority, 'project' => $projectId, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}" class="pill {{ $filter === 'all' ? 'active' : '' }}">All Tasks ({{ $allCount }})</a>
     @endcan

     <div style="height:18px; width:1px; background:var(--line); margin:0 4px;"></div>

     <a href="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'priority' => $priority, 'project' => $projectId, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}" class="pill {{ empty($status) ? 'active' : '' }}">All Statuses</a>
     @foreach (['To Do', 'In Progress', 'In Review', 'Completed', 'Blocked'] as $st)
       <a href="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'status' => $st, 'priority' => $priority, 'project' => $projectId, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}" class="pill {{ $status === $st ? 'active' : '' }}">{{ $st }}</a>
     @endforeach

     <div style="height:18px; width:1px; background:var(--line); margin:0 4px;"></div>

     <!-- Project Filter -->
     <select
       onchange="if (this.value) window.location = this.value;"
       title="Filter by Project"
       style="border:1px solid var(--line); border-radius:999px; padding:5px 12px; font-size:12px; font-family:inherit; background:var(--surface); color:var(--ink); cursor:pointer;"
     >
       <option value="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'status' => $status, 'priority' => $priority, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}">All Projects</option>
       @foreach ($projects as $prj)
         <option value="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'status' => $status, 'priority' => $priority, 'project' => $prj->project_id, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}" {{ (string) $projectId === (string) $prj->project_id ? 'selected' : '' }}>{{ $prj->project_name }}</option>
       @endforeach
     </select>

     <!-- Team Filter -->
     <select
       onchange="if (this.value) window.location = this.value;"
       title="Filter by Team"
       style="border:1px solid var(--line); border-radius:999px; padding:5px 12px; font-size:12px; font-family:inherit; background:var(--surface); color:var(--ink); cursor:pointer;"
     >
       <option value="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'status' => $status, 'priority' => $priority, 'project' => $projectId, 'assignee' => $assigneeId, 'q' => $search])) }}">All Teams</option>
       @foreach ($teams as $tm)
         <option value="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'status' => $status, 'priority' => $priority, 'project' => $projectId, 'team' => $tm->team_id, 'assignee' => $assigneeId, 'q' => $search])) }}" {{ (string) $teamId === (string) $tm->team_id ? 'selected' : '' }}>{{ $tm->team_name }}</option>
       @endforeach
     </select>

     <!-- Priority Filter -->
     <select
       onchange="if (this.value) window.location = this.value;"
       title="Filter by Priority"
       style="border:1px solid var(--line); border-radius:999px; padding:5px 12px; font-size:12px; font-family:inherit; background:var(--surface); color:var(--ink); cursor:pointer;"
     >
       <option value="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'status' => $status, 'project' => $projectId, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}">All Priorities</option>
       @foreach (['High', 'Medium', 'Low', 'Urgent'] as $pr)
         <option value="{{ route('tasks.index', array_filter(['filter' => $filter, 'view' => $view, 'status' => $status, 'priority' => $pr, 'project' => $projectId, 'team' => $teamId, 'assignee' => $assigneeId, 'q' => $search])) }}" {{ $priority === $pr ? 'selected' : '' }}>{{ $pr }}</option>
       @endforeach
     </select>

     @if ($filter === 'all')
       <select
         onchange="if (this.value) window.location = this.value;"
         title="Filter by Assignee"
         style="border:1px solid var(--line); border-radius:999px; padding:5px 12px; font-size:12px; font-family:inherit; background:var(--surface); color:var(--ink); cursor:pointer;"
       >
         <option value="{{ route('tasks.index', array_filter(['filter' => 'all', 'view' => $view, 'status' => $status, 'priority' => $priority, 'project' => $projectId, 'team' => $teamId, 'q' => $search])) }}">All Assignees</option>
         @foreach ($assignableUsers as $au)
           <option value="{{ route('tasks.index', array_filter(['filter' => 'all', 'view' => $view, 'status' => $status, 'priority' => $priority, 'project' => $projectId, 'team' => $teamId, 'assignee' => $au->user_id, 'q' => $search])) }}" {{ (string) $assigneeId === (string) $au->user_id ? 'selected' : '' }}>{{ $au->full_name }}</option>
         @endforeach
       </select>
     @endif
  </div>

  <!-- Kanban View (5 Columns with HTML5 Drag & Drop) -->
  <div x-show="viewMode === 'kanban'" x-cloak>
    @if ($tasks->isNotEmpty())
      <div class="kanban" style="display:grid; grid-template-columns:repeat(5, minmax(220px, 1fr)); gap:14px; align-items:start; overflow-x:auto; padding-bottom:14px;">
        @php
          $columns = [
            'To Do' => ['label' => 'TO DO', 'match' => ['To Do', 'Pending', 'Not started'], 'badge' => 'b-planning'],
            'In Progress' => ['label' => 'IN PROGRESS', 'match' => ['In Progress'], 'badge' => 'b-planning'],
            'In Review' => ['label' => 'IN REVIEW', 'match' => ['In Review'], 'badge' => 'b-review'],
            'Completed' => ['label' => 'COMPLETED', 'match' => ['Completed', 'Done'], 'badge' => 'b-active'],
            'Blocked' => ['label' => 'BLOCKED', 'match' => ['Blocked'], 'badge' => 'b-blocked'],
          ];
        @endphp

        @foreach ($columns as $statusKey => $col)
          @php
            $colTasks = $tasks->filter(fn($t) => in_array($t->status, $col['match']));
            // Column counts come precomputed from SQL (see TaskController@index);
            // fall back to the collection only when the aggregate is unavailable.
            $colCount = $kanbanCounts[Str::slug($statusKey)] ?? $colTasks->count();
          @endphp
          <div
            class="kcol"
            id="kanban-col-{{ Str::slug($statusKey) }}"
            ondragover="handleDragOver(event)"
            ondrop="handleDrop(event, '{{ $statusKey }}')"
            style="background:var(--bg-subtle); border:1px solid var(--line); border-radius:10px; padding:12px; min-height:450px;"
          >
            <div class="kcol-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid var(--line);">
              <h4 style="margin:0; font-size:12.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--ink);">{{ $col['label'] }}</h4>
              <span class="kcol-count badge" id="count-{{ Str::slug($statusKey) }}" style="font-size:11px; font-weight:700;">{{ $colCount }}</span>
            </div>

            <div class="kcol-body" id="col-tasks-{{ Str::slug($statusKey) }}" style="display:flex; flex-direction:column; gap:10px; min-height:350px;">
              @foreach ($colTasks as $t)
                @php $late = !in_array($t->status, ['Done', 'Completed']) && $t->end_date && $t->end_date->isPast(); @endphp
                <div
                  class="tcard"
                  id="task-card-{{ $t->task_id }}"
                  draggable="true"
                  ondragstart="handleDragStart(event, {{ $t->task_id }})"
                  onclick="openTask({{ $t->task_id }})"
                  style="background:var(--bg-card); border:1px solid var(--line); border-radius:8px; padding:12px; cursor:grab; box-shadow:0 1px 3px rgba(0,0,0,0.04); transition:transform 0.15s ease, box-shadow 0.15s ease;"
                >
                  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <span class="id mono" style="font-size:10.5px; font-weight:700; color:var(--ink-muted);">TASK-{{ str_pad($t->task_id, 4, '0', STR_PAD_LEFT) }}</span>
                    @if ($t->team)
                      <span class="badge b-active" style="font-size:9.5px; padding:1px 6px;">{{ $t->team->team_name }}</span>
                    @endif
                  </div>

                  <div class="name" style="font-weight:600; font-size:13.5px; margin-bottom:8px; line-height:1.35; color:var(--ink);">{{ $t->task_name }}</div>

                  @if ($t->project || optional($t->phase)->project)
                    <div style="font-size:11.5px; color:var(--accent); margin-bottom:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                      📁 {{ optional($t->project ?? optional($t->phase)->project)->project_name }}
                    </div>
                  @endif

                  <div class="tcard-foot" style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding-top:8px; border-top:1px solid var(--line);">
                    <span class="priority p-{{ strtolower($t->priority ?: 'medium') }}" style="font-size:11px;">{{ $t->priority }}</span>
                    <div style="display:flex; align-items:center; gap:8px;">
                      @if ($t->comments->count() > 0)
                        <span style="font-size:11px; color:var(--ink-soft);" title="Comments">💬 {{ $t->comments->count() }}</span>
                      @endif
                      @if ($t->attachments->count() > 0)
                        <span style="font-size:11px; color:var(--ink-soft);" title="Attachments">📎 {{ $t->attachments->count() }}</span>
                      @endif
                      <span class="duedate {{ $late ? 'late' : '' }}" style="font-size:11px; font-weight:600;">
                        {{ $late ? '⚠ ' : '' }}{{ optional($t->end_date)->format('d M') ?: '—' }}
                      </span>
                      @if ($t->assignee)
                        <div class="avatar" style="width:22px; height:22px; font-size:9.5px;" title="{{ $t->assignee->full_name }}">{{ $t->assignee->initials() }}</div>
                      @endif
                    </div>
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        @endforeach
      </div>
    @else
      <div class="card">
        @include('partials.tasks-empty', ['filter' => $filter])
      </div>
    @endif
  </div>

  <!-- List View -->
  <div x-show="viewMode === 'list'">
    <div class="card">
      <table>
        <thead>
          <tr>
            <th style="width:24%">Task</th>
            <th>Project</th>
            <th>Team</th>
            <th>Assignee</th>
            <th>Priority</th>
            <th>Budget</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Due</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($tasks as $t)
            @php $late = !in_array($t->status, ['Done', 'Completed']) && $t->end_date && $t->end_date->isPast(); @endphp
            <tr onclick="openTask({{ $t->task_id }})" style="cursor:pointer;">
              <td class="cell-primary">
                {{ $t->task_name }}
                <div class="cell-sub mono">TASK-{{ str_pad($t->task_id, 4, '0', STR_PAD_LEFT) }}</div>
              </td>
              <td>
                @php $proj = $t->project ?? optional($t->phase)->project; @endphp
                @if ($proj)
                  <a href="{{ route('projects.show', $proj) }}" onclick="event.stopPropagation();" style="font-weight:600; color:var(--accent);">
                    {{ $proj->project_name }}
                  </a>
                @else
                  —
                @endif
              </td>
              <td>{{ optional($t->team)->team_name ?? '—' }}</td>
              <td>
                @if ($t->assignee)
                  <div style="display:flex; align-items:center; gap:6px;">
                    <div class="avatar" style="width:22px; height:22px; font-size:10px;">{{ $t->assignee->initials() }}</div>
                    <span>{{ $t->assignee->full_name }}</span>
                  </div>
                @else
                  <span style="color:var(--ink-faint);">Unassigned</span>
                @endif
              </td>
              <td><span class="priority p-{{ strtolower($t->priority) }}">{{ $t->priority }}</span></td>
              <td><span class="mono" style="font-weight:600; font-size:12px; color:var(--ink);">ETB {{ number_format($t->budget ?: 0) }}</span></td>
              <td>
                <span class="badge {{ $t->statusBadgeClass() }}"><span class="badge-dot"></span>{{ $t->status }}</span>
              </td>
              <td>
                <div style="display:flex; align-items:center; gap:6px;">
                  <div style="flex:1; height:6px; background:var(--bg-subtle); border-radius:999px; overflow:hidden; min-width:50px;">
                    <div style="height:100%; width:{{ $t->progress }}%; background:{{ $t->progress === 100 ? 'var(--active)' : 'var(--accent)' }};"></div>
                  </div>
                  <span style="font-size:11.5px; font-weight:600; color:var(--ink-soft);">{{ $t->progress }}%</span>
                </div>
              </td>
              <td><span class="{{ $late ? 'late' : '' }}">{{ optional($t->end_date)->format('d M Y') ?: '—' }}</span></td>
              <td style="text-align:right;">
                <button type="button" class="btn btn-ghost" style="padding:4px 10px; font-size:12px; font-weight:600;" onclick="event.stopPropagation(); openTask({{ $t->task_id }}, true);">✎ Edit</button>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="10">
                @include('partials.tasks-empty', ['filter' => $filter])
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

@php
  $taskProjectPhases = $projects->mapWithKeys(function ($project) {
    return [
      $project->project_id => $project->phases->map(function ($phase) {
        return [
          'id' => $phase->phase_id,
          'name' => $phase->phase_name,
          'status' => $phase->status,
        ];
      })->values(),
    ];
  });
@endphp
<script>
  let draggedTaskId = null;
  let sourceColumnBody = null;

  function handleDragStart(e, taskId) {
    draggedTaskId = taskId;
    sourceColumnBody = document.getElementById('task-card-' + taskId)?.parentElement || null;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(taskId));
    e.target.style.opacity = '0.5';
  }

  function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
  }

  function showToast(message, isError = false) {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.style.cssText = 'position:fixed; top:18px; right:18px; z-index:9999; display:flex; flex-direction:column; gap:8px;';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.style.cssText = 'background:' + (isError ? '#b91c1c' : '#14532d') + '; color:#fff; padding:10px 16px; border-radius:8px; font-size:13px; font-weight:600; box-shadow:0 6px 18px rgba(0,0,0,0.18); opacity:0; transition:opacity 0.2s ease;';
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(() => { toast.style.opacity = '1'; });
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 250);
    }, 3200);
  }

  function returnCardToSource(card) {
    if (card && sourceColumnBody) {
      sourceColumnBody.appendChild(card);
    }
  }

  async function handleDrop(e, targetStatus) {
    e.preventDefault();
    if (!draggedTaskId) return;

    const card = document.getElementById('task-card-' + draggedTaskId);
    const originBody = sourceColumnBody;

    // Optimistic move into the target column.
    if (card) {
      card.style.opacity = '1';
      const targetColBody = document.getElementById('col-tasks-' + slugify(targetStatus));
      if (targetColBody) {
        targetColBody.appendChild(card);
      }
    }

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
      const res = await fetch(`/tasks/${draggedTaskId}/status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ status: targetStatus })
      });

      if (res.ok) {
        updateKanbanCounts();
        showToast('Status updated');
      } else if (res.status === 403) {
        // Not authorized — put the card back where it came from.
        returnCardToSource(card);
        updateKanbanCounts();
        showToast('You are not allowed to move this task', true);
      } else {
        returnCardToSource(card);
        updateKanbanCounts();
        showToast('Could not update task status', true);
      }
    } catch (err) {
      console.error('Failed to update status:', err);
      if (originBody && card) originBody.appendChild(card);
      updateKanbanCounts();
      showToast('Network error — task not moved', true);
    } finally {
      draggedTaskId = null;
      sourceColumnBody = null;
    }
  }

  function slugify(text) {
    return text.toString().toLowerCase()
      .replace(/\s+/g, '-')
      .replace(/[^\w\-]+/g, '')
      .replace(/\-\-+/g, '-')
      .replace(/^-+/, '')
      .replace(/-+$/, '');
  }

  function updateKanbanCounts() {
    ['To Do', 'In Progress', 'In Review', 'Completed', 'Blocked'].forEach(st => {
      const col = document.getElementById('col-tasks-' + slugify(st));
      const countEl = document.getElementById('count-' + slugify(st));
      if (col && countEl) {
        const count = col.querySelectorAll('.tcard').length;
        countEl.innerText = count;
      }
    });
  }

  document.addEventListener('dragend', (e) => {
    if (e.target.classList.contains('tcard')) {
      e.target.style.opacity = '1';
    }
  });

  const taskProjectPhases = {{ \Illuminate\Support\Js::from($taskProjectPhases) }};

  function updateNewTaskPhases(projectId) {
    const phaseSelect = document.getElementById('task_phase_id');

    if (!phaseSelect) {
      return;
    }

    const phases = taskProjectPhases[projectId] || [];
    const oldPhaseId = phaseSelect.dataset.oldPhase || '';
    const preferredPhase = phases.find((phase) => phase.id == oldPhaseId)
      || phases.find((phase) => phase.status === 'In Progress')
      || phases[0];

    phaseSelect.innerHTML = '<option value="">— Select Phase —</option>';

    phases.forEach((phase) => {
      const option = document.createElement('option');
      option.value = phase.id;
      option.textContent = phase.name;
      option.selected = preferredPhase && phase.id == preferredPhase.id;
      phaseSelect.appendChild(option);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const projectSelect = document.getElementById('project_id');

    if (projectSelect) {
      updateNewTaskPhases(projectSelect.value);
    }
  });
</script>
@endsection

