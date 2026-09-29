@extends('layouts.app')
@section('title', 'New Project')
@section('crumb')
  <a class="link-small" style="cursor:pointer;" href="{{ route('projects.index') }}">Projects</a> / New Project
@endsection

@section('content')
<div class="page-head">
  <div>
    <h1>Create New Project</h1>
    <div class="page-sub">Set up project information, assign teams, configure initial tasks, and review before launch.</div>
  </div>
</div>

@if ($errors->any())
  <div class="form-alert"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<!-- Step Indicator -->
<div class="card" style="margin-bottom:24px; padding:18px 24px;">
  <div class="wizard-stepper" style="display:flex; justify-content:space-between; position:relative; align-items:center;">
    <div class="wizard-step active" id="step-nav-1" onclick="goToStep(1)">
      <div class="wizard-step-circle">1</div>
      <div class="wizard-step-info">
        <span class="step-num">Step 1</span>
        <span class="step-title">Project Info</span>
      </div>
    </div>
    <div class="wizard-line" id="line-1"></div>
    <div class="wizard-step" id="step-nav-2" onclick="goToStep(2)">
      <div class="wizard-step-circle">2</div>
      <div class="wizard-step-info">
        <span class="step-num">Step 2</span>
        <span class="step-title">Assign Teams</span>
      </div>
    </div>
    <div class="wizard-line" id="line-2"></div>
    <div class="wizard-step" id="step-nav-3" onclick="goToStep(3)">
      <div class="wizard-step-circle">3</div>
      <div class="wizard-step-info">
        <span class="step-num">Step 3</span>
        <span class="step-title">Create Tasks</span>
      </div>
    </div>
    <div class="wizard-line" id="line-3"></div>
    <div class="wizard-step" id="step-nav-4" onclick="goToStep(4)">
      <div class="wizard-step-circle">4</div>
      <div class="wizard-step-info">
        <span class="step-num">Step 4</span>
        <span class="step-title">Review & Confirm</span>
      </div>
    </div>
  </div>
</div>

<form id="project-wizard-form" method="POST" action="{{ route('projects.store') }}">
  @csrf

  <!-- STEP 1: Project Information -->
  <div class="card card-pad wizard-pane active" id="pane-1">
    <div style="border-bottom:1px solid var(--line); padding-bottom:14px; margin-bottom:20px;">
      <h2 style="font-size:17px; font-weight:700; margin:0;">Step 1 — Project Information</h2>
    </div>

    <div class="form-grid">
      <div class="form-field" style="grid-column:1 / -1;">
        <label for="project_name">Project Name <span style="color:var(--danger);">*</span></label>
        <input type="text" id="project_name" name="project_name" value="{{ old('project_name') }}" data-required placeholder="e.g. E-Commerce Website">
      </div>

      <div class="form-field" style="grid-column:1 / -1;">
        <label for="primary_office_id">Primary Office <span style="color:var(--accent, #2563eb);">*</span></label>
        <select id="primary_office_id" name="primary_office_id" data-required>
          @if ($isAdmin)
            <option value="">— Select Primary Owning Office —</option>
          @endif
          @foreach ($offices as $office)
            <option value="{{ $office->office_id }}" {{ (string) old('primary_office_id') === (string) $office->office_id ? 'selected' : '' }}>
              {{ $office->office_name }}</option>
          @endforeach
        </select>
      </div>

      <div class="form-field" style="grid-column:1 / -1;">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3" placeholder="Briefly describe the project...">{{ old('description') }}</textarea>
      </div>

      <div class="form-field">
        <label for="client">Client / Organization</label>
        <input type="text" id="client" name="client" value="{{ old('client') }}" placeholder="e.g. Acme Corp">
      </div>

      <div class="form-field">
        <label for="project_type">Project Type <span style="color:var(--danger);">*</span></label>
        <select id="project_type" name="project_type_id" data-required>
          <option value="">Select Project Type</option>
          @foreach ($projectTypes as $type)
            <option value="{{ $type->project_type_id }}" {{ (string) old('project_type_id') === (string) $type->project_type_id ? 'selected' : '' }} data-office="{{ $type->office_id ?? '' }}">
              {{ $type->name }}@if ($type->office_id) · {{ optional($type->office)->office_name }}@endif</option>
          @endforeach
        </select>
      </div>

      <div class="form-field">
        <label for="project_manager_id">
          Project Manager <span style="color:var(--danger);">*</span>
        </label>
        <select id="project_manager_id" name="project_manager_id">
          <option value="">— Select Project Manager —</option>
          @foreach ($projectManagers as $pm)
            <option value="{{ $pm->user_id }}" data-office="{{ $pm->office_id ?? '' }}" {{ (string) old('project_manager_id') === (string) $pm->user_id ? 'selected' : '' }}>
              {{ $pm->full_name }} ({{ $pm->department ?: 'PM' }})
            </option>
          @endforeach
        </select>
      </div>

      <div class="form-field">
        <label for="priority">Priority <span style="color:var(--danger);">*</span></label>
        <select id="priority" name="priority">
          <option value="">Select Priority</option>
          @foreach ($priorities as $p)
            <option value="{{ $p }}" {{ old('priority') === $p ? 'selected' : '' }}>{{ $p }}</option>
          @endforeach
        </select>
      </div>

      <div class="form-field">
        <label for="start_date">Start Date</label>
        <input type="date" id="start_date" name="start_date" value="{{ old('start_date') }}">
      </div>

      <div class="form-field">
        <label for="end_date">Deadline (Target End Date)</label>
        <input type="date" id="end_date" name="end_date" value="{{ old('end_date') }}">
      </div>

      <div class="form-field">
        <label for="allocated_amount">Allocated Budget (ETB)</label>
        <input type="number" step="0.01" min="0" id="allocated_amount" name="allocated_amount" value="{{ old('allocated_amount') }}" placeholder="e.g. 750,000 ETB">
      </div>

      <div class="form-field">
        <label>Participating Offices
          <span style="font-weight:400; color:var(--ink-faint);">(cross-office collaboration)</span>
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-2">
          @foreach ($offices as $office)
            <label class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-blue-50/50 border border-slate-200/80 hover:border-blue-300 transition-all cursor-pointer group">
              <input type="checkbox"
                     name="participating_offices[]"
                     value="{{ $office->office_id }}"
                     {{ in_array((string) $office->office_id, old('participating_offices', [])) ? 'checked' : '' }}
                     class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 shrink-0 transition-colors cursor-pointer">
              <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 select-none">
                {{ $office->office_name }}
              </span>
            </label>
          @endforeach
        </div>
      </div>
    </div>

    <div class="wizard-actions">
      <div></div>
      <button type="button" id="btn-step-1-next" class="btn btn-accent">Continue to Teams →</button>
    </div>
  </div>



  <!-- STEP 2: Assign Teams -->
  <div class="card card-pad wizard-pane" id="pane-2">
    <div style="border-bottom:1px solid var(--line); padding-bottom:14px; margin-bottom:20px;">
      <h2 style="font-size:17px; font-weight:700; margin:0;">Step 2 — Assign Teams</h2>
    </div>

    <div class="teams-selection-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px; margin-bottom:20px;">
      @foreach ($teams as $team)
        <label class="team-select-card" id="team-card-{{ $team->team_id }}" data-office="{{ $team->office_id }}" style="display:flex; align-items:flex-start; gap:14px; padding:16px; border:1.5px solid var(--line); border-radius:10px; cursor:pointer; transition:all 0.15s ease; background:var(--bg-card);">
          <input
            type="checkbox"
            name="teams[]"
            value="{{ $team->team_id }}"
            id="team-checkbox-{{ $team->team_id }}"
            class="team-checkbox"
            style="margin-top:4px; width:18px; height:18px; accent-color:var(--accent); cursor:pointer;"
            onchange="toggleTeamSelection({{ $team->team_id }})"
            {{ in_array((string) $team->team_id, array_map('strval', old('teams', [])), true) ? 'checked' : '' }}
          >
          <div style="flex:1;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
              <span style="font-weight:700; font-size:14.5px; color:var(--ink);">{{ $team->team_name }}</span>
              <span class="badge b-active" style="font-size:10px;">{{ $team->members->count() }} members</span>
            </div>
            <div style="font-size:12px; color:var(--ink-soft); margin:4px 0 6px;">
              <b>Lead:</b> {{ optional($team->leader)->full_name ?? 'Unassigned' }}
            </div>
            <div style="font-size:12px; color:var(--ink-muted); line-height:1.4;">
              {{ Str::limit($team->description, 75) }}
            </div>
          </div>
        </label>
      @endforeach
    </div>

    <p id="teams-empty-message" style="display:none; color:var(--ink-muted); font-size:13px; margin-bottom:16px;">
      No teams available yet. Choose a primary office and/or participating offices in Step 1 to see the teams you can assign.
    </p>

    <div class="wizard-actions">
      <button type="button" class="btn btn-ghost" onclick="goToStep(1)">← Back to Info</button>
      <button type="button" class="btn btn-accent" onclick="validateStep2AndNext()">Continue to Tasks →</button>
    </div>
  </div>

  <!-- STEP 3: Create Tasks -->
  <div class="card card-pad wizard-pane" id="pane-3">
    <div style="border-bottom:1px solid var(--line); padding-bottom:14px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
      <div>
        <h2 style="font-size:17px; font-weight:700; margin:0;">Step 3 — Create Tasks by Team</h2>
      </div>
    </div>

    <!-- Dynamic container for task creation sections per selected team -->
    <div id="team-task-builders-container">
      <!-- Populated dynamically by JavaScript based on Step 2 selection -->
    </div>

    <div class="wizard-actions">
      <button type="button" class="btn btn-ghost" onclick="goToStep(2)">← Back to Teams</button>
      <button type="button" class="btn btn-accent" onclick="buildReviewAndNext()">Review Project →</button>
    </div>
  </div>

  <!-- STEP 4: Review & Confirm -->
  <div class="card card-pad wizard-pane" id="pane-4">
    <div style="border-bottom:1px solid var(--line); padding-bottom:14px; margin-bottom:20px;">
      <h2 style="font-size:17px; font-weight:700; margin:0;">Step 4 — Review & Confirm Project</h2>
    </div>

    <!-- Review Metrics Grid -->
    <div class="review-stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:14px; margin-bottom:24px;">
      <div class="stat-box" style="background:var(--bg-subtle); padding:14px; border-radius:8px; border:1px solid var(--line);">
        <div style="display:flex; justify-content:space-between; align-items:center;"><div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted);">Project Name</div><button type="button" class="btn btn-ghost" onclick="editWizardStep(1)">Edit</button></div>
        <div id="review-proj-name" style="font-size:15px; font-weight:700; color:var(--ink);">-</div>
      </div>
      <div class="stat-box" style="background:var(--bg-subtle); padding:14px; border-radius:8px; border:1px solid var(--line);">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted); margin-bottom:4px;">Project Manager</div>
        <div id="review-pm-name" style="font-size:14px; font-weight:700; color:var(--ink);">-</div>
      </div>
      <div class="stat-box" style="background:var(--bg-subtle); padding:14px; border-radius:8px; border:1px solid var(--line);">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted); margin-bottom:4px;">Assigned Teams</div>
        <div id="review-teams-count" style="font-size:18px; font-weight:800; color:var(--accent);">0</div>
      </div>
      <div class="stat-box" style="background:var(--bg-subtle); padding:14px; border-radius:8px; border:1px solid var(--line);">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted); margin-bottom:4px;">Total Tasks</div>
        <div id="review-tasks-count" style="font-size:18px; font-weight:800; color:var(--active);">0</div>
      </div>
      <div class="stat-box" style="background:var(--bg-subtle); padding:14px; border-radius:8px; border:1px solid var(--line);">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted); margin-bottom:4px;">Priority</div>
        <div id="review-priority-badge">-</div>
      </div>
      <div class="stat-box" style="background:var(--bg-subtle); padding:14px; border-radius:8px; border:1px solid var(--line);">
        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted); margin-bottom:4px;">Deadline</div>
        <div id="review-deadline" style="font-size:13.5px; font-weight:600; color:var(--ink);">-</div>
      </div>
    </div>

    <!-- Structured Breakdown of Teams & Tasks -->
    <div style="margin-bottom:24px;">
     <div style="display:flex; justify-content:space-between; align-items:center;"><h3 style="font-size:14px; font-weight:700; text-transform:uppercase; color:var(--ink-soft); margin-bottom:12px;">Team & Task Breakdown</h3><button type="button" class="btn btn-ghost" onclick="editWizardStep(3)">Edit Tasks</button></div>
      <div id="review-teams-tasks-list" style="display:flex; flex-direction:column; gap:16px;">
        <!-- Populated via JS -->
      </div>
    </div>

    <div class="wizard-actions">
      <button type="button" class="btn btn-ghost" onclick="goToStep(3)">← Back to Tasks</button>
      <button type="button" class="btn btn-accent" style="font-size:14px; padding:9px 24px; font-weight:700;" onclick="finalizeWizard()">Create Project & Launch Tasks</button>
    </div>
  </div>
</form>

<style>
  .wizard-pane { display: none; }
  .wizard-pane.active { display: block; }
  .wizard-step { display:flex; align-items:center; gap:10px; cursor:pointer; opacity:0.6; transition:all 0.2s ease; }
  .wizard-step.active { opacity:1; }
  .wizard-step.completed .wizard-step-circle { background:var(--active); color:#fff; }
  .wizard-step.active .wizard-step-circle { background:var(--accent); color:#fff; box-shadow:0 0 0 4px rgba(59,130,246,0.18); }
  .wizard-step-circle { width:32px; height:32px; border-radius:50%; background:var(--bg-subtle); border:1.5px solid var(--line); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; color:var(--ink-soft); }
  .wizard-step-info { display:flex; flex-direction:column; }
  .step-num { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted); }
  .step-title { font-size:13.5px; font-weight:700; color:var(--ink); }
  .wizard-line { flex:1; height:2px; background:var(--line); margin:0 14px; }
  .wizard-actions { display:flex; justify-content:space-between; align-items:center; margin-top:24px; padding-top:16px; border-top:1px solid var(--line); }
  .team-task-card { background:var(--bg-subtle); border:1px solid var(--line); border-radius:8px; padding:16px; margin-bottom:16px; }
  .task-row-item { display:grid; grid-template-columns:2fr 1.3fr 1fr 1fr 1fr 1fr auto; gap:8px; align-items:center; background:var(--bg-card); border:1px solid var(--line); border-radius:6px; padding:10px 12px; margin-bottom:8px; }
  .task-row-item input:not([type="hidden"]),
  .task-row-item select {
    width:100%;
    border:1px solid var(--line);
    border-radius:8px;
    padding:9px 12px;
    font-size:13.3px;
    font-family:inherit;
    background:var(--surface);
    color:var(--ink);
    box-sizing:border-box;
    transition:border-color .15s ease, box-shadow .15s ease;
  }
  .task-row-item input:focus,
  .task-row-item select:focus {
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px var(--primary-soft);
  }
  .task-row-item .task-field-label { display:block; font-size:11px; font-weight:600; color:var(--ink-muted); margin-bottom:4px; }
  .task-row-item .btn-remove-task { background:var(--surface); border:1px solid var(--line); border-radius:8px; width:34px; height:36px; display:inline-flex; align-items:center; justify-content:center; color:var(--danger); font-size:14px; cursor:pointer; transition:background .15s ease, border-color .15s ease; }
  .task-row-item .btn-remove-task:hover { background:var(--danger-soft); border-color:var(--danger); }
  .team-task-card .btn-add-task { background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:6px 14px; font-size:12.5px; font-weight:600; color:var(--ink-soft); cursor:pointer; transition:background .15s ease, border-color .15s ease; }
  .team-task-card .btn-add-task:hover { background:var(--primary-soft); border-color:var(--primary); color:var(--primary); }

  /* Multi-select assignee picker (Step 3) */
  .assignee-multiselect { position:relative; }
  .assignee-control {
    display:flex; flex-wrap:wrap; align-items:center; gap:6px;
    min-height:38px; padding:5px 8px;
    background:var(--surface); border:1px solid var(--line); border-radius:8px;
    cursor:pointer; transition:border-color .15s ease, box-shadow .15s ease;
  }
  .assignee-control:hover { border-color:var(--primary); }
  .assignee-multiselect.open .assignee-control { border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-soft); }
  .assignee-placeholder { font-size:13px; color:var(--ink-faint); padding:2px 2px; }
  .assignee-search {
    flex:1 1 70px; min-width:70px; border:none; background:transparent; outline:none;
    font-size:13.3px; font-family:inherit; color:var(--ink); padding:3px 2px;
  }
  .assignee-chip {
    display:inline-flex; align-items:center; gap:5px; max-width:100%;
    padding:2px 6px 2px 9px; border-radius:999px;
    background:var(--primary-soft); color:var(--primary);
    font-size:11.5px; font-weight:600; line-height:1.5;
  }
  .assignee-chip .chip-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:150px; }
  .assignee-chip .chip-remove {
    display:inline-flex; align-items:center; justify-content:center;
    width:15px; height:15px; border-radius:50%; border:none; cursor:pointer;
    background:rgba(37,99,235,0.16); color:var(--primary); font-size:10px; line-height:1; padding:0;
  }
  .assignee-chip .chip-remove:hover { background:var(--danger); color:#fff; }
  .assignee-menu {
    position:absolute; z-index:40; top:calc(100% + 6px); left:0; right:0;
    max-height:220px; overflow-y:auto; padding:6px;
    background:var(--surface); border:1px solid var(--line); border-radius:8px;
    box-shadow:0 12px 28px rgba(15,23,42,0.16);
  }
  .assignee-menu[hidden] { display:none; }
  .assignee-option {
    display:flex; align-items:center; gap:9px; width:100%;
    padding:7px 9px; border-radius:6px; cursor:pointer; text-align:left;
    background:transparent; border:none; font:inherit; color:var(--ink);
  }
  .assignee-option:hover, .assignee-option.is-active { background:var(--primary-soft); }
  .assignee-option input[type="checkbox"] { width:15px; height:15px; accent-color:var(--primary); cursor:pointer; margin:0; }
  .assignee-option .option-name { font-size:13px; font-weight:500; }
  .assignee-option .option-role { margin-left:auto; font-size:11px; color:var(--ink-muted); }
  .assignee-empty { padding:9px; font-size:12.5px; color:var(--ink-muted); font-style:italic; }
  .assignee-tools { display:flex; gap:10px; margin-top:6px; }
  .assignee-tools button {
    background:none; border:none; padding:0; cursor:pointer;
    font-size:11.5px; font-weight:600; color:var(--primary);
  }
  .assignee-tools button:hover { text-decoration:underline; }

  @media (max-width: 768px) {
    .wizard-stepper { flex-direction:column; gap:12px; align-items:flex-start; }
    .wizard-line { display:none; }
    .task-row-item { grid-template-columns:1fr; }
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
  window.__TEAMS_DATA__ = {!! json_encode($teamsData) !!};

  let currentStep = 1;
  let taskCounter = 0;
  let savedProjectId = null;

  let saveInProgress = false;

  async function saveWizardStep(step) {
    const form = document.getElementById('project-wizard-form');
    const formData = new FormData(form);
    formData.set('step', step);
    if (savedProjectId) formData.set('project_id', savedProjectId);
    const response = await fetch('{!! route('projects.wizard.save') !!}', {
      method: 'POST', body: formData,
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    });
    const result = await response.json();
    if (!response.ok) {
      showInlineErrors(result.errors || { wizard: [result.message || 'Unable to save this step.'] });
      throw new Error('save failed');
    }
    clearInlineErrors();
    savedProjectId = result.project_id || savedProjectId;
    return result;
  }

  function clearInlineErrors() {
    document.querySelectorAll('.wizard-inline-error').forEach(error => error.remove());
  }

  function showInlineErrors(errors) {
    clearInlineErrors();
    const error = document.createElement('div');
    error.className = 'form-alert wizard-inline-error';
    error.textContent = Object.values(errors).flat().join(' ');
    document.getElementById('project-wizard-form').prepend(error);
  }

  function saveAndGoToStep(step) {
    goToStep(step);
  }

  function finalizeWizard() {
    if (saveInProgress) return;
    clearInlineErrors();
    if (!validateFinalWizard()) return;
    document.querySelectorAll('.task-row-item').forEach(row => {
      if (!row.querySelector('[name*="[task_name]"]')?.value.trim()) row.remove();
    });
    let index = 0;
    document.querySelectorAll('.task-row-item').forEach(row => {
      index++;
      row.querySelectorAll('[name^="tasks["]').forEach(input => {
        input.name = input.name.replace(/tasks\\[\\d+\\]/, `tasks[${index}]`);
      });
    });
    saveInProgress = true;
    const button = document.querySelector('#pane-4 button[onclick="finalizeWizard()"]');
    if (button) { button.disabled = true; button.textContent = 'Creating…'; }
    document.getElementById('project-wizard-form').submit();
  }

  function validateFinalWizard() {
    const name = document.getElementById('project_name').value.trim();
    if (!name) { goToStep(1); showInlineErrors({ project_name: ['Project Name is required.'] }); return false; }
    if (!document.querySelectorAll('.team-checkbox:checked').length) {
      goToStep(2); showInlineErrors({ teams: ['Select at least one team.'] }); return false;
    }
    return true;
  }

  function editWizardStep(step) {
    goToStep(step);
  }

  function goToStep(step) {
    clearInlineErrors();

    currentStep = step;
    document.querySelectorAll('.wizard-pane').forEach(p => p.classList.remove('active'));
    document.getElementById('pane-' + step).classList.add('active');

    for (let i = 1; i <= 4; i++) {
      const el = document.getElementById('step-nav-' + i);
      el.classList.remove('active', 'completed');
      if (i === step) el.classList.add('active');
      else if (i < step) el.classList.add('completed');
    }

    if (step === 3) renderTaskBuilders();
    if (step === 4) buildReviewSummary();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function validateStep1() {
    clearInlineErrors();
    let valid = true;

    const fields = [
      { id: 'project_name', label: 'Project Name' },
      { id: 'project_type', label: 'Project Type' },
      { id: 'priority', label: 'Priority' },
      { id: 'primary_office_id', label: 'Primary Office' },
    ];

    fields.forEach(function (field) {
      const input = document.getElementById(field.id);
      if (!input) return;
      const value = (input.value || '').trim();
      const wrapper = input.closest('.form-field');
      const existingError = wrapper?.querySelector('.field-error');

      if (!value) {
        valid = false;
        input.classList.add('border-red-500');
        if (wrapper && !existingError) {
          const message = document.createElement('div');
          message.className = 'field-error';
          message.style.cssText = 'color:#dc2626; font-size:12px; margin-top:4px; font-weight:600;';
          message.textContent = field.label + ' is required.';
          wrapper.appendChild(message);
        }
      } else {
        input.classList.remove('border-red-500');
        existingError?.remove();
      }
    });

    return valid;
  }

  function validateStep1AndNext() {
    if (validateStep1()) {
      goToStep(2);
    }
  }

  function validateStep2() {
    return document.querySelectorAll('.team-checkbox:checked').length > 0;
  }

  function validateStep2AndNext() {
    saveAndGoToStep(3);
  }

  function toggleTeamSelection(teamId) {
    const cb = document.getElementById('team-checkbox-' + teamId);
    const card = document.getElementById('team-card-' + teamId);
    if (cb && card) {
      card.style.borderColor = cb.checked ? 'var(--accent)' : 'var(--line)';
      card.style.background = cb.checked ? 'rgba(59,130,246,0.04)' : 'var(--bg-card)';
    }
  }

  function renderTaskBuilders() {
    const container = document.getElementById('team-task-builders-container');
    const checkedBoxes = Array.from(document.querySelectorAll('.team-checkbox:checked'));
    const selectedTeamIds = checkedBoxes.map(cb => parseInt(cb.value));

    // Preserve every row, including temporarily empty rows.
    const existingValues = [];
    document.querySelectorAll('.task-row-item').forEach(row => {
      existingValues.push({
        task_name: row.querySelector('[name*="[task_name]"]')?.value || '',
        team_id: parseInt(row.querySelector('[name*="[team_id]"]')?.value),
        user_ids: Array.from(row.querySelectorAll('[name*="[user_ids][]"]') || []).map(i => i.value),
        priority: row.querySelector('[name*="[priority]"]')?.value || 'Medium',
        budget: row.querySelector('[name*="[budget]"]')?.value || '',
        start_date: row.querySelector('[name*="[start_date]"]')?.value || '',
        end_date: row.querySelector('[name*="[end_date]"]')?.value || ''
      });
    });

    container.innerHTML = '';

    selectedTeamIds.forEach(teamId => {
      const team = window.__TEAMS_DATA__.find(t => t.id === teamId);
      if (!team) return;

      const card = document.createElement('div');
      card.className = 'team-task-card';
      card.id = 'team-task-block-' + teamId;

      card.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <div>
            <span style="font-weight:700; font-size:15px; color:var(--ink);">${escapeHtml(team.name)}</span>
            <span style="font-size:12px; color:var(--ink-soft); margin-left:8px;">(Lead: ${escapeHtml(team.leader_name)})</span>
          </div>
          <button type="button" class="btn-add-task" onclick="addTaskRow(${team.id})">+ Add Task</button>
        </div>
        <div id="task-rows-team-${team.id}"></div>
      `;

      container.appendChild(card);

      const teamExisting = existingValues.filter(v => v.team_id === teamId);
      if (teamExisting.length > 0) {
        teamExisting.forEach(v => addTaskRow(teamId, v));
      } else {
        // Provide sample starter tasks based on team
        addTaskRow(teamId);
      }
    });
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
  }

  function addTaskRow(teamId, prefill = {}) {
    const rowsContainer = document.getElementById('task-rows-team-' + teamId);
    if (!rowsContainer) return;

    const team = window.__TEAMS_DATA__.find(t => t.id === teamId);
    taskCounter++;
    const idx = taskCounter;

    const row = document.createElement('div');
    row.className = 'task-row-item';
    row.id = 'task-row-' + idx;

    row.innerHTML = `
      <input type="hidden" name="tasks[${idx}][team_id]" value="${teamId}">
      <div>
        <input type="text" name="tasks[${idx}][task_name]" value="${escapeHtml(prefill.task_name || '')}" placeholder="e.g. Create Authentication API" style="width:100%;">
      </div>
      <div>
        <label class="task-field-label">Assignees</label>
        <div class="assignee-multiselect" data-multiselect data-index="${idx}" data-members="${escapeHtml(JSON.stringify((team && team.members) || []))}">
          <div class="assignee-control" data-control tabindex="0" role="button" aria-haspopup="listbox" aria-expanded="false" aria-label="Task assignees">
            <span class="assignee-placeholder" data-placeholder>Assign to team members…</span>
            <input type="text" class="assignee-search" data-search placeholder="" autocomplete="off" aria-label="Search team members">
          </div>
          <div class="assignee-menu" data-menu hidden></div>
        </div>
        <div class="assignee-tools">
          <button type="button" onclick="selectAllAssignees(this)">Select all</button>
          <button type="button" onclick="clearAssignees(this)">Clear</button>
        </div>
      </div>
      <div>
        <select name="tasks[${idx}][priority]" style="width:100%;">
          <option value="High" ${prefill.priority === 'High' ? 'selected' : ''}>High</option>
          <option value="Medium" ${(!prefill.priority || prefill.priority === 'Medium') ? 'selected' : ''}>Medium</option>
          <option value="Low" ${prefill.priority === 'Low' ? 'selected' : ''}>Low</option>
          <option value="Urgent" ${prefill.priority === 'Urgent' ? 'selected' : ''}>Urgent</option>
        </select>
      </div>
      <div>
        <input type="number" step="0.01" min="0" name="tasks[${idx}][budget]" value="${escapeHtml(prefill.budget || '')}" placeholder="e.g. 25,000 ETB" style="width:100%;">
      </div>
      <div>
        <label class="task-field-label">Start date</label>
        <input type="date" name="tasks[${idx}][start_date]" value="${prefill.start_date || ''}" onchange="validateTaskDates(this)">
      </div>
      <div>
        <label class="task-field-label">End date</label>
        <input type="date" name="tasks[${idx}][end_date]" value="${prefill.end_date || ''}" onchange="validateTaskDates(this)">
      </div>
      <div>
        <button type="button" class="btn-remove-task" title="Remove task" aria-label="Remove task" onclick="removeTaskRow(this)">✕</button>
      </div>
    `;

    rowsContainer.appendChild(row);

    const widget = row.querySelector('[data-multiselect]');
    if (widget) {
      initAssigneeMultiselect(widget, prefill.user_ids || []);
    }
  }

  /*
   * Multi-select assignee picker: a checkbox list with removable user chips.
   * Selected users are stored as tasks[<index>][user_ids][] hidden inputs so a
   * task can be assigned to several team members at once.
   */
  function initAssigneeMultiselect(widget, preselectedIds = []) {
    const index = widget.getAttribute('data-index');
    let members = [];
    try {
      members = JSON.parse(widget.getAttribute('data-members') || '[]');
    } catch (e) {
      members = [];
    }
    members = members.filter(m => m && m.id);

    widget.__members = members;
    widget.__selected = new Set(members.map(m => String(m.id)).filter(id => preselectedIds.map(String).includes(id)));
    widget.__closeHandler = function (e) {
      if (!widget.contains(e.target)) closeAssigneeMenu(widget);
    };

    const control = widget.querySelector('[data-control]');
    const menu = widget.querySelector('[data-menu]');
    const search = widget.querySelector('[data-search]');

    control.addEventListener('click', function (e) {
      if (e.target.closest('.chip-remove')) return;
      openAssigneeMenu(widget);
      if (e.target === control || e.target.classList.contains('assignee-placeholder')) search.focus();
    });

    control.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openAssigneeMenu(widget);
        search.focus();
      }
    });

    search.addEventListener('input', function () {
      renderAssigneeOptions(widget, search.value);
    });

    document.addEventListener('click', widget.__closeHandler);

    renderAssigneeChips(widget);
  }

  function openAssigneeMenu(widget) {
    widget.classList.add('open');
    widget.querySelector('[data-menu]').hidden = false;
    widget.querySelector('[data-control]').setAttribute('aria-expanded', 'true');
    renderAssigneeOptions(widget, widget.querySelector('[data-search]').value);
  }

  function closeAssigneeMenu(widget) {
    widget.classList.remove('open');
    widget.querySelector('[data-menu]').hidden = true;
    widget.querySelector('[data-control]').setAttribute('aria-expanded', 'false');
  }

  function renderAssigneeOptions(widget, query = '') {
    const menu = widget.querySelector('[data-menu]');
    const selected = widget.__selected;
    const needle = (query || '').trim().toLowerCase();

    const matches = widget.__members.filter(m => !needle || String(m.name).toLowerCase().includes(needle));

    if (!widget.__members.length) {
      menu.innerHTML = '<div class="assignee-empty">No team members available for this team.</div>';
      return;
    }

    if (!matches.length) {
      menu.innerHTML = '<div class="assignee-empty">No member matches your search.</div>';
      return;
    }

    menu.innerHTML = matches.map(m => `
      <label class="assignee-option" data-user-id="${m.id}">
        <input type="checkbox" value="${m.id}" ${selected.has(String(m.id)) ? 'checked' : ''}>
        <span class="option-name">${escapeHtml(m.name)}</span>
      </label>
    `).join('');

    menu.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      cb.addEventListener('change', function () {
        toggleAssignee(widget, cb.value, cb.checked);
      });
    });
  }

  function toggleAssignee(widget, userId, isSelected) {
    const id = String(userId);
    if (isSelected) {
      widget.__selected.add(id);
    } else {
      widget.__selected.delete(id);
    }
    renderAssigneeChips(widget);
  }

  function removeAssignee(button) {
    const widget = button.closest('[data-multiselect]');
    const userId = String(button.getAttribute('data-user-id'));
    widget.__selected.delete(userId);
    renderAssigneeChips(widget);

    if (!widget.querySelector('[data-menu]').hidden) {
      renderAssigneeOptions(widget, widget.querySelector('[data-search]').value);
    }
  }

  function selectAllAssignees(button) {
    const widget = button.closest('.task-row-item').querySelector('[data-multiselect]');
    widget.__members.forEach(m => widget.__selected.add(String(m.id)));
    renderAssigneeChips(widget);
    if (!widget.querySelector('[data-menu]').hidden) {
      renderAssigneeOptions(widget, widget.querySelector('[data-search]').value);
    }
  }

  function clearAssignees(button) {
    const widget = button.closest('.task-row-item').querySelector('[data-multiselect]');
    widget.__selected.clear();
    renderAssigneeChips(widget);
    if (!widget.querySelector('[data-menu]').hidden) {
      renderAssigneeOptions(widget, widget.querySelector('[data-search]').value);
    }
  }

  function renderAssigneeChips(widget) {
    const control = widget.querySelector('[data-control]');
    const search = widget.querySelector('[data-search]');
    const placeholder = widget.querySelector('[data-placeholder]');
    const index = widget.getAttribute('data-index');

    // Drop previously rendered chips and hidden inputs before re-rendering.
    control.querySelectorAll('.assignee-chip').forEach(chip => chip.remove());
    widget.querySelectorAll('input[name^="tasks["][name$="[user_ids][]"]').forEach(input => input.remove());

    const selectedMembers = widget.__members.filter(m => widget.__selected.has(String(m.id)));

    selectedMembers.forEach(m => {
      const chip = document.createElement('span');
      chip.className = 'assignee-chip';
      chip.innerHTML = `<span class="chip-label">${escapeHtml(m.name)}</span>
        <button type="button" class="chip-remove" data-user-id="${m.id}" title="Remove ${escapeHtml(m.name)}" aria-label="Remove ${escapeHtml(m.name)}">✕</button>`;
      control.insertBefore(chip, search);
    });

    placeholder.style.display = selectedMembers.length ? 'none' : '';
    search.placeholder = selectedMembers.length ? 'Add more…' : '';

    selectedMembers.forEach(m => {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = `tasks[${index}][user_ids][]`;
      hidden.value = m.id;
      widget.appendChild(hidden);
    });

    control.querySelectorAll('.chip-remove').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        removeAssignee(btn);
      });
    });
  }

  function assigneeNamesForRow(row) {
    const widget = row.querySelector('[data-multiselect]');
    if (!widget) return [];

    return widget.__members
      .filter(m => widget.__selected.has(String(m.id)))
      .map(m => m.name);
  }

  function validateTaskDates(input) {
    const row = input.closest('.task-row-item');
    const start = row?.querySelector('[name*="[start_date]"]')?.value;
    const end = row?.querySelector('[name*="[end_date]"]')?.value;
    if (start && end && end < start) {
      input.setCustomValidity('End date must be on or after the start date.');
    } else {
      input.setCustomValidity('');
    }
  }

  function removeTaskRow(button) {
    const row = button.closest('.task-row-item');
    const widget = row?.querySelector('[data-multiselect]');
    if (widget && widget.__closeHandler) {
      document.removeEventListener('click', widget.__closeHandler);
    }
    row?.remove();
  }

  function buildReviewAndNext() {
    buildReviewSummary();
    saveAndGoToStep(4);
  }

  function buildReviewSummary() {
    document.getElementById('review-proj-name').innerText = document.getElementById('project_name').value || 'Untitled';
    const pmSelect = document.getElementById('project_manager_id');
    document.getElementById('review-pm-name').innerText = pmSelect.options[pmSelect.selectedIndex]?.text || 'Unassigned';
    document.getElementById('review-deadline').innerText = document.getElementById('end_date').value || 'Not set';

    const pri = document.getElementById('priority').value;
    document.getElementById('review-priority-badge').innerHTML = pri ? `<span class="badge p-${pri.toLowerCase()}">${pri}</span>` : 'Not set';

    const selectedTeams = Array.from(document.querySelectorAll('.team-checkbox:checked'));
    document.getElementById('review-teams-count').innerText = selectedTeams.length;

    const taskRows = document.querySelectorAll('.task-row-item');
    let validTasksCount = 0;
    const teamTasksMap = {};

    selectedTeams.forEach(cb => {
      const tid = parseInt(cb.value);
      const team = window.__TEAMS_DATA__.find(t => t.id === tid);
      if (team) {
        teamTasksMap[tid] = { name: team.name, lead: team.leader_name, tasks: [] };
      }
    });

    taskRows.forEach(row => {
      const name = row.querySelector('[name*="[task_name]"]')?.value.trim();
      const teamId = parseInt(row.querySelector('[name*="[team_id]"]')?.value);
      const assigneeNames = assigneeNamesForRow(row);
      const pri = row.querySelector('[name*="[priority]"]')?.value || 'Medium';
      const due = row.querySelector('[name*="[end_date]"]')?.value;
      const bgt = row.querySelector('[name*="[budget]"]')?.value;

      if (name && teamTasksMap[teamId]) {
        validTasksCount++;
        teamTasksMap[teamId].tasks.push({ name, assignees: assigneeNames, priority: pri, start: row.querySelector('[name*="[start_date]"]')?.value, due, budget: bgt });
      }
    });

    document.getElementById('review-tasks-count').innerText = validTasksCount;

    const listContainer = document.getElementById('review-teams-tasks-list');
    listContainer.innerHTML = '';

    Object.values(teamTasksMap).forEach(teamInfo => {
      const block = document.createElement('div');
      block.style.background = 'var(--bg-subtle)';
      block.style.border = '1px solid var(--line)';
      block.style.borderRadius = '8px';
      block.style.padding = '14px 16px';

      let taskListHtml = '';
      if (teamInfo.tasks.length === 0) {
        taskListHtml = `<div style="font-size:12.5px; color:var(--ink-muted); font-style:italic;">No initial tasks added for this team.</div>`;
      } else {
        taskListHtml = teamInfo.tasks.map(t => `
          <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:var(--bg-card); border:1px solid var(--line); border-radius:6px; margin-top:6px;">
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="color:var(--accent);">✓</span>
              <span style="font-weight:600; font-size:13.5px; color:var(--ink);">${escapeHtml(t.name)}</span>
            </div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
              ${t.assignees.length
                ? t.assignees.map(a => `<span class="assignee-chip"><span class="chip-label">${escapeHtml(a)}</span></span>`).join('')
                : `<span style="font-size:12px; color:var(--ink-muted);">👤 Unassigned</span>`}
              <span class="badge p-${t.priority.toLowerCase()}">${t.priority}</span>
              ${t.start ? `<span style="font-size:11.5px; color:var(--ink-muted);">Start ${t.start}</span>` : ''}
              ${t.due ? `<span style="font-size:11.5px; color:var(--ink-muted);">End ${t.due}</span>` : ''}
            </div>
          </div>
        `).join('');
      }

      block.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <span style="font-weight:700; font-size:14.5px; color:var(--ink);">${escapeHtml(teamInfo.name)}</span>
          <span class="badge b-active">${teamInfo.tasks.length} task(s)</span>
        </div>
        ${taskListHtml}
      `;

      listContainer.appendChild(block);
    });
  }

  // Initial setup for styled cards
  document.querySelectorAll('.team-checkbox').forEach(cb => {
    toggleTeamSelection(cb.value);
  });

  document.getElementById('btn-step-1-next')?.addEventListener('click', function (e) {
    e.preventDefault();
    if (validateStep1()) {
      goToStep(2);
    }
  });

  /*
   * Office-scoped project types: when the Primary Office changes, the
   * Filtering happens client-side over data-office attributes rendered
   * server-side; the selection resets when the current type is no longer
   * valid for the chosen office.
   */
  (function () {
    const officeSelect = document.getElementById('primary_office_id');
    const typeSelect = document.getElementById('project_type');
    if (!officeSelect || !typeSelect) return;

    const typeLabelFor = {
      @foreach ($projectTypes as $type)
      {{ $type->project_type_id }}: {!! json_encode($type->name.($type->office_id ? ' · '.optional($type->office)->office_name : '')) !!},
      @endforeach
    };

    function filterTypes() {
      const officeId = officeSelect.value;
      let selectionValid = false;

      typeSelect.querySelectorAll('option').forEach(function (option) {
        if (!option.value) return; // placeholder

        const optionOffice = option.getAttribute('data-office') || '';
        const visible = !officeId || !optionOffice || optionOffice === officeId;
        option.hidden = !visible;
        option.disabled = !visible;

        if (visible) {
          option.textContent = typeLabelFor[option.value] || option.textContent;
        }

        if (option.selected && visible) selectionValid = true;
      });

      if (!selectionValid) typeSelect.value = '';
    }

    officeSelect.addEventListener('change', filterTypes);

    // Apply once on load (e.g. validation errors re-rendering the form).
    filterTypes();
  })();

  /*
   * Office-restricted team selection: teams from unrelated offices are
   * hidden. Allowed offices = primary office + participating offices.
   * (The backend enforces the same rule server-side.)
   */
  (function () {
    const officeSelect = document.getElementById('primary_office_id');
    if (!officeSelect) return;

    function allowedOffices() {
      const ids = new Set();
      if (officeSelect.value) ids.add(officeSelect.value);
      document.querySelectorAll('input[name="participating_offices[]"]:checked').forEach(function (cb) {
        ids.add(cb.value);
      });
      return ids;
    }

    function filterTeams() {
      const allowed = allowedOffices();
      const noOffices = allowed.size === 0;
      let anyVisible = false;

      document.querySelectorAll('.team-select-card').forEach(function (card) {
        const teamOffice = card.getAttribute('data-office') || '';
        const visible = noOffices || !teamOffice || allowed.has(teamOffice);
        card.style.display = visible ? '' : 'none';
        if (visible) anyVisible = true;

        if (!visible) {
          const cb = card.querySelector('.team-checkbox');
          if (cb && cb.checked) {
            cb.checked = false;
            toggleTeamSelection(cb.value);
          }
        }
      });

      const emptyMsg = document.getElementById('teams-empty-message');
      if (emptyMsg) emptyMsg.style.display = anyVisible ? 'none' : '';
    }

    officeSelect.addEventListener('change', filterTeams);
    document.querySelectorAll('input[name="participating_offices[]"]').forEach(function (cb) {
      cb.addEventListener('change', filterTeams);
    });

    filterTeams();
  })();

  /*
   * Filter Project Managers by the selected Primary Office.
   * Show all PMs with no office, plus PMs from the selected office.
   */
  (function () {
    const officeSelect = document.getElementById('primary_office_id');
    const pmSelect = document.getElementById('project_manager_id');
    if (!officeSelect || !pmSelect) return;

    function filterProjectManagers() {
      const selectedOfficeId = officeSelect.value;
      let selectionValid = false;

      pmSelect.querySelectorAll('option').forEach(function (option) {
        if (!option.value) return; // Skip placeholder

        const optionOffice = option.getAttribute('data-office') || '';
        // Show: no office restriction (global PMs) OR matching office
        const visible = !optionOffice || optionOffice === selectedOfficeId;
        option.hidden = !visible;
        option.disabled = !visible;

        if (visible && option.selected) selectionValid = true;
      });

      if (!selectionValid) pmSelect.value = '';
    }

    officeSelect.addEventListener('change', filterProjectManagers);
    filterProjectManagers();
  })();

  // Re-expose wizard functions globally so inline onclick handlers keep working.
  Object.assign(window, {
    goToStep,
    validateStep1,
    validateStep1AndNext,
    validateStep2,
    validateStep2AndNext,
    validateTaskDates,
    toggleTeamSelection,
    addTaskRow,
    removeTaskRow,
    removeAssignee,
    selectAllAssignees,
    clearAssignees,
    buildReviewAndNext,
    buildReviewSummary,
    finalizeWizard,
    editWizardStep,
  });
  });
</script>
@endsection
