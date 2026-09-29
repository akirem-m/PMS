<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces the reported post-creation authorization failure on
 * ProjectController@store -> projects.show.
 */
class ProjectCreationAuthProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_project_manager_can_open_project_they_just_created(): void
    {
        $pm = User::where('email', 'pm.ict@pms.test')->firstOrFail();

        $this->assertTrue($pm->can('create_projects'), 'PM should hold create_projects');

        $payload = [
            'project_name' => 'ZZ Authorization Probe',
            'description' => 'probe',
            'project_type' => 'Software',
            'primary_office_id' => $pm->office_id,
            'priority' => 'Medium',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'allocated_amount' => 1000,
        ];

        $response = $this->actingAs($pm)->post('/projects', $payload);

        $status = $response->getStatusCode();
        $location = $response->headers->get('Location');

        fwrite(STDERR, "POST /projects -> {$status} Location=".($location ?? 'none').PHP_EOL);

        $project = Project::where('project_name', 'ZZ Authorization Probe')->first();

        if (! $project) {
            $this->fail('No project was persisted. Status was '.$status);
        }

        fwrite(STDERR, 'persisted id='.$project->project_id
            .' primary_office_id='.var_export($project->primary_office_id, true)
            .' created_by='.var_export($project->created_by, true).PHP_EOL);
        fwrite(STDERR, 'offices pivot=['.$project->offices()->pluck('offices.office_id')->implode(', ').']'.PHP_EOL);
        fwrite(STDERR, 'policy view: '.($pm->can('view', $project) ? 'ALLOW' : 'DENY').PHP_EOL);

        $show = $this->actingAs($pm)->get('/projects/'.$project->project_id);
        fwrite(STDERR, 'GET /projects/'.$project->project_id.' -> '.$show->getStatusCode()
            .($show->getStatusCode() === 403 ? ' <-- ACCESS DENIED' : '').PHP_EOL);

        $this->assertSame(200, $show->getStatusCode(), 'PM must be able to open the project they created');
    }
}
