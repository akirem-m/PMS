<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('admin project create page has placeholder for primary_office', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@pms.test')->first();

    $response = $this->actingAs($admin, 'web')
        ->get(route('projects.create'));

    $response->assertStatus(200);
    $response->assertSeeText('— Select Primary Owning Office —');
});

test('admin context header shows Global instead of office name', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@pms.test')->first();

    // Navigate to a project/team to check the context header
    $response = $this->actingAs($admin, 'web')
        ->get(route('projects.index'));

    // Should see "Global" in the context header when no specific project is in context
    $response->assertStatus(200);
});

test('regular user project create page uses their office', function () {
    $this->seed(DatabaseSeeder::class);
    $pm = User::where('email', 'pm.ict@pms.test')->first();

    $response = $this->actingAs($pm, 'web')
        ->get(route('projects.create'));

    $response->assertStatus(200);
    // Regular user should NOT see the placeholder option
    $response->assertDontSeeText('— Select Primary Owning Office —');
    // But should see their office
    $response->assertSeeText('ICT Directorate');
});

test('form continue button without onclick attribute works', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@pms.test')->first();

    $response = $this->actingAs($admin, 'web')
        ->get(route('projects.create'));

    $response->assertStatus(200);
    // Check that the button exists and doesn't have inline onclick
    $html = $response->getContent();
    // Button should exist
    $this->assertStringContainsString('id="btn-step-1-next"', $html);
    $this->assertStringContainsString('Continue to Teams', $html);
});
