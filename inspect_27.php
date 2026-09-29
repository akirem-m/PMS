<?php

/** Inspect project 27 and every account that would be denied access to it. */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Project;
use App\Models\User;
use App\Services\OrgHierarchyService;
use App\Services\RbacService;
use Illuminate\Contracts\Console\Kernel;

$p = Project::find(27);

if (! $p) {
    echo 'NO PROJECT 27'.PHP_EOL;
    exit(0);
}

echo 'PROJECT 27: '.$p->project_name.PHP_EOL;
echo '  primary_office_id = '.var_export($p->primary_office_id, true).PHP_EOL;
echo '  created_by        = '.var_export($p->created_by, true).PHP_EOL;
echo '  project_manager_id= '.var_export($p->project_manager_id, true).PHP_EOL;
echo '  team_id           = '.var_export($p->team_id, true).PHP_EOL;
echo '  offices pivot     = ['.$p->offices()->pluck('offices.office_id')->implode(', ').']'.PHP_EOL;
echo PHP_EOL;

// Who can view this project right now?
$users = User::where('status', 'Active')->get();

$allowed = [];
$denied = [];

foreach ($users as $u) {
    $can = $u->can('view', $p);
    $line = str_pad($u->email, 36)
        .' office='.str_pad((string) var_export($u->office_id, true), 6)
        .' view='.($can ? 'ALLOW' : 'DENY')
        .' roles=['.$u->roles()->pluck('role_name')->implode(', ').']';

    if ($can) {
        $allowed[] = $line;
    } else {
        $denied[] = $line;
    }
}

echo 'ALLOW ('.count($allowed).'):'.PHP_EOL;
foreach ($allowed as $l) {
    echo '  '.$l.PHP_EOL;
}

echo PHP_EOL.'DENY ('.count($denied).'):'.PHP_EOL;
foreach ($denied as $l) {
    echo '  '.$l.PHP_EOL;
}

echo PHP_EOL.'--- Why? Detail for any Head of Office ---'.PHP_EOL;

foreach (User::whereHas('roles', fn ($q) => $q->where('role_name', 'Head of Office'))->get() as $head) {
    $rbac = app(RbacService::class);
    $hier = app(OrgHierarchyService::class);

    $pivot = $head->roles()->where('role_name', 'Head of Office')->first()?->pivot;

    echo $head->email.' office_id='.var_export($head->office_id, true).PHP_EOL;
    echo '  pivot scope_type='.var_export($pivot->scope_type ?? null, true)
        .' scope_id='.var_export($pivot->scope_id ?? null, true).PHP_EOL;
    echo '  canAccessGlobalScope = '.($head->canAccessGlobalScope() ? 'true' : 'false').PHP_EOL;
    echo '  isAdmin              = '.($head->isAdmin() ? 'true' : 'false').PHP_EOL;
    echo '  isTeamMember         = '.($head->isTeamMember() ? 'true' : 'false').PHP_EOL;
    echo '  headOfficeIds        = ['.$head->headOfficeIds()->implode(', ').']'.PHP_EOL;
    echo '  officeScopeIds       = ['.$head->officeScopeIds()->implode(', ').']'.PHP_EOL;
    echo '  canManage(project27) = '.($hier->canManage($head, $p) ? 'true' : 'false').PHP_EOL;
    echo '  view_projects@project= '.($rbac->can($head, 'view_projects', $p) ? 'true' : 'false').PHP_EOL;
    echo '  participatesIn       = '.($p->participatesIn($head) ? 'true' : 'false').PHP_EOL;
    echo '  policy view          = '.($head->can('view', $p) ? 'true' : 'false').PHP_EOL;
}
