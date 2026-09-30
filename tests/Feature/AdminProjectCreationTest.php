<?php

use App\Models\Office;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('admin can access project create page', function () {
    $this->seed(DatabaseSeeder::class);

    // Get the seeded admin user who now should have an office assigned
    $admin = User::where('email', 'admin@pms.test')->first();

    // Verify admin has the right setup
    expect($admin)->not->toBeNull();
    expect($admin->canAccessGlobalScope())->toBeTrue();
    expect($admin->office_id)->not->toBeNull(); // Admin now has an office

    // Act: Try to access project create page
    $response = $this->actingAs($admin, 'web')
        ->get(route('projects.create'));

    // Assert: Should succeed, not abort with 403
    $response->assertStatus(200);
    $response->assertViewIs('projects.create');
    $response->assertViewHas('offices');
    $response->assertViewHas('teams');
});

test('admin can see all offices and teams in create page', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@pms.test')->first();

    $response = $this->actingAs($admin, 'web')
        ->get(route('projects.create'));

    $offices = $response->viewData('offices');
    $teams = $response->viewData('teams');

    // Admin should see all active offices (not just their own)
    expect($offices->count())->toBeGreaterThan(0);
});

test('administrator can create a project for an office other than their own', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@pms.test')->firstOrFail();
    // A global manager is not confined to a single office, so the picklist on
    // the create page has to be honoured server-side too.
    $office = Office::where('office_code', 'FIN')->firstOrFail();
    $team = Team::where('office_id', $office->office_id)->firstOrFail();

    $this->actingAs($admin, 'web')
        ->post(route('projects.store'), [
            'project_name' => 'Admin Cross Office Project',
            'project_type' => 'Software',
            'primary_office_id' => $office->office_id,
            'team_id' => $team->team_id,
        ])
        ->assertSessionHasNoErrors();

    $project = Project::where('project_name', 'Admin Cross Office Project')->first();

    expect($project)->not->toBeNull();
    expect((int) $project->primary_office_id)->toBe((int) $office->office_id);
});

test('office bound manager cannot create a project in another office', function () {
    $this->seed(DatabaseSeeder::class);

    $director = User::where('email', 'director@example.com')->firstOrFail();
    $otherOffice = Office::where('office_id', '!=', $director->office_id)->firstOrFail();

    $this->actingAs($director, 'web')
        ->post(route('projects.store'), [
            'project_name' => 'Out Of Office Project',
            'project_type' => 'Software',
            'primary_office_id' => $otherOffice->office_id,
        ])
        ->assertSessionHasErrors('primary_office_id');

    expect(Project::where('project_name', 'Out Of Office Project')->exists())->toBeFalse();
});
