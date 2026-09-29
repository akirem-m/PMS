<?php

use App\Models\Office;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

// Quick verification of the merged RBAC + dev feature integration (no test harness needed).
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// 1. Team model reconciliations
$team = new ReflectionClass(Team::class);
$methods = array_map(fn ($m) => $m->getName(), $team->getMethods());
foreach (['subTeams', 'allDescendantIds', 'wouldCauseCycle', 'projectManager', 'departmentHead', 'leadershipUserIds'] as $expected) {
    echo in_array($expected, $methods) ? "OK   Team::{$expected}\n" : "MISS Team::{$expected}\n";
}
// no duplicates of case-insensitive relation
$subs = array_values(array_filter($methods, fn ($m) => strtolower($m) === 'subteams'));
echo count($subs) === 1 ? "OK   Team::subTeams declared exactly once\n" : "FAIL duplicate subTeams\n";

// 2. User scopes unified with is_global
$user = new ReflectionClass(User::class);
$umethods = array_map(fn ($m) => $m->getName(), $user->getMethods());
foreach (['scopeInOfficeBranch', 'scopeForOffice', 'scopeAvailableTo', 'isGlobal'] as $expected) {
    echo in_array($expected, $umethods) ? "OK   User::{$expected}\n" : "MISS User::{$expected}\n";
}
$src = file_get_contents(__DIR__.'/app/Models/User.php');
echo str_contains($src, "->orWhere('is_global', true)") ? "OK   is_global unified into office scopes\n" : "FAIL is_global not in scopes\n";

// 3. Policies: dev workflow methods + our hierarchical leadership
$tp = file_get_contents(__DIR__.'/app/Policies/TaskPolicy.php');
foreach (['leadsOrOversees', 'function accept', 'function reject', 'function modifyLocked'] as $needle) {
    echo str_contains($tp, $needle) ? "OK   TaskPolicy has {$needle}\n" : "FAIL TaskPolicy missing {$needle}\n";
}

// 4. Routes standardized on policies
$routes = file_get_contents(__DIR__.'/routes/web.php');
echo substr_count($routes, 'can:manage_team') === 0 ? "OK   raw can:manage_team removed from routes\n" : 'FAIL can:manage_team still in routes ('.substr_count($routes, 'can:manage_team').")\n";
foreach (['can:manageMembers,team', 'can:update,team', 'can:delete,team'] as $needle) {
    echo str_contains($routes, $needle) ? "OK   route middleware {$needle}\n" : "FAIL route middleware missing {$needle}\n";
}

// 5. DB: sub_teams table + dev tables coexist
$schema = Schema::hasTable('sub_teams') ? 'OK   sub_teams table exists' : 'FAIL sub_teams missing';
echo $schema."\n";
echo Schema::hasTable('task_assignments') && Schema::hasTable('payments') ? "OK   dev tables (task_assignments, payments) exist\n" : "FAIL dev tables missing\n";
echo Schema::hasColumn('users', 'is_global') && Schema::hasColumn('tasks', 'sub_team_id') ? "OK   users.is_global + tasks.sub_team_id columns exist\n" : "FAIL expected columns missing\n";

// 6. Scope query smoke test on real data
$office = Office::first();
if ($office) {
    $count = User::query()->inOfficeBranch($office)->count();
    echo "OK   inOfficeBranch scope runs ({$count} users visible for office {$office->office_id})\n";
} else {
    echo "SKIP no offices seeded\n";
}
