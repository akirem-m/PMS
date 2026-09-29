<?php

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
