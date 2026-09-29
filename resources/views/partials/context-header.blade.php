@php
    $contextOffice = null;
    $contextProject = null;
    $contextTeam = null;
    $route = request()->route();
    $viewer = auth()->user();

    /*
     * System Administrators (and anyone with organization-wide scope) are not
     * bound to a single office, so their top-left badge must read "Global"
     * rather than the office the record happens to belong to.
     */
    $hasGlobalScope = (bool) $viewer?->canAccessGlobalScope();
    $scopeLabel = 'Global';
    $scopeTitle = 'System-Wide';

    /*
     * The badge is only meaningful for office-bound, non-Administrator users.
     * Administrators work across the whole organization, so they get no badge.
     */
    $shouldRenderContextHeader = $viewer
        && $viewer->office_id
        && ! $viewer->hasRole('Administrator');

    if ($shouldRenderContextHeader && $route && ! request()->routeIs('admin.*')) {
        $resourceProject = $route->parameter('project');
        $resourceTeam = $route->parameter('team');
        $resourceTask = $route->parameter('task');
        $resourcePhase = $route->parameter('phase');

        if ($resourceProject instanceof \App\Models\Project) {
            $contextProject = $resourceProject;
            $contextOffice = $resourceProject->office;
        } elseif ($resourceTeam instanceof \App\Models\Team) {
            $contextTeam = $resourceTeam;
            $contextProject = $resourceTeam->allProjects()
                ->first(fn ($project) => auth()->user()?->can('view', $project));
            $contextOffice = $contextProject?->office ?? $resourceTeam->office;
        } elseif ($resourceTask instanceof \App\Models\Task) {
            $contextTaskProject = $resourceTask->project ?? optional($resourceTask->phase)->project;
            $contextProject = $contextTaskProject;
            $contextTeam = $resourceTask->team;
            $contextOffice = $contextTaskProject?->office ?? $contextTeam?->office;
        } elseif ($resourcePhase instanceof \App\Models\Phase) {
            $contextProject = $resourcePhase->project;
            $contextOffice = $contextProject?->office;
        }

        if (! $contextProject && ! $contextTeam) {
            $contextOffice ??= $viewer?->office;
        }
    }
@endphp

@if (! $shouldRenderContextHeader)
    {{-- Intentionally empty: users without an office, or Administrators, see no context badge. --}}
@elseif ($hasGlobalScope)
    <div class="context-header" aria-label="Current scope">
        <span class="ctx-scope-badge ctx-scope-global" title="{{ $scopeTitle }} access">
            <span class="ctx-scope-dot" aria-hidden="true"></span>{{ $scopeLabel }}
        </span>
        @if ($contextProject)
            <span aria-hidden="true">→</span>
            <span>{{ $contextProject->project_name }}</span>
        @endif
        @if ($contextTeam)
            <span aria-hidden="true">→</span>
            <span>{{ $contextTeam->team_name }}</span>
        @endif
    </div>
@elseif ($contextOffice)
    <div class="context-header" aria-label="Current context">
        <span>{{ $contextOffice->office_name }}</span>
        @if ($contextProject)
            <span aria-hidden="true">→</span>
            <span>{{ $contextProject->project_name }}</span>
        @endif
        @if ($contextTeam)
            <span aria-hidden="true">→</span>
            <span>{{ $contextTeam->team_name }}</span>
        @endif
    </div>
@endif