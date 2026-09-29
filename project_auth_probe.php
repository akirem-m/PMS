<?php

/**
 * Reproduces the reported "Access denied after creating a project" failure.
 * Posts a valid wizard payload as a Project Manager, then follows the redirect.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Phase;
use App\Models\PhaseBudget;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$kernel = app(Illuminate\Contracts\Http\Kernel::class);

function post(string $uri, array $data, $user, $kernel): array
{
    Auth::login($user);

    $request = Request::create($uri, 'POST', $data);
    $request->setLaravelSession(app('session.store'));

    $response = $kernel->handle($request);

    return [$response->getStatusCode(), $response->headers->get('Location')];
}

function get(string $uri, $user, $kernel): int
{
    Auth::login($user);

    $response = $kernel->handle(Request::create($uri, 'GET'));

    return $response->getStatusCode();
}

$pm = User::where('email', 'pm.ict@pms.test')->firstOrFail();

echo 'actor: '.$pm->email.' office_id='.$pm->office_id
    .' can(create_projects)='.($pm->can('create_projects') ? 'true' : 'false').PHP_EOL;

$payload = [
    'project_name' => 'ZZ Authorization Probe '.now()->timestamp,
    'description' => 'probe',
    'project_type' => 'Software',
    'primary_office_id' => $pm->office_id,
    'priority' => 'Medium',
    'start_date' => now()->toDateString(),
    'end_date' => now()->addMonth()->toDateString(),
    'allocated_amount' => 1000,
];

[$status, $location] = post('/projects', $payload, $pm, $kernel);

echo 'POST /projects -> '.$status.' Location='.($location ?? 'none').PHP_EOL;

if (! $location) {
    echo 'No redirect; cannot continue.'.PHP_EOL;
    exit(1);
}

// Inspect the persisted row for the two fields named in the report.
$project = Project::where('project_name', $payload['project_name'])->first();

if (! $project) {
    echo 'No project row was persisted.'.PHP_EOL;
    exit(1);
}

echo 'persisted: id='.$project->project_id
    .' primary_office_id='.var_export($project->primary_office_id, true)
    .' created_by='.var_export($project->created_by, true).PHP_EOL;

echo 'offices pivot: ['.$project->offices()->pluck('offices.office_id')->implode(', ').']'.PHP_EOL;

echo 'policy view: '.($pm->can('view', $project) ? 'ALLOW' : 'DENY').PHP_EOL;

$showStatus = get('/projects/'.$project->project_id, $pm, $kernel);
echo 'GET '.$location.' -> '.$showStatus.($showStatus === 403 ? ' <-- ACCESS DENIED' : '').PHP_EOL;

// Clean up the probe row so the check is repeatable.
$project->offices()->detach();
Phase::where('project_id', $project->project_id)->each(function ($p) {
    PhaseBudget::where('phase_id', $p->phase_id)->delete();
    $p->delete();
});
ProjectBudget::where('project_id', $project->project_id)->delete();
$project->delete();

echo 'probe row cleaned up.'.PHP_EOL;
