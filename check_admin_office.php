<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$admin = User::where('email', 'admin@pms.test')->first();
if ($admin) {
    echo "Admin User: {$admin->full_name}\n";
    echo "  Email: {$admin->email}\n";
    echo '  Office ID: '.($admin->office_id ?? 'NULL')."\n";
    echo '  Office Name: '.($admin->office?->office_name ?? 'N/A')."\n";
    echo '  Has Administrator role: '.($admin->hasRole('Administrator') ? 'YES' : 'NO')."\n";
    echo '  Can access global scope: '.($admin->canAccessGlobalScope() ? 'YES' : 'NO')."\n";
} else {
    echo "Admin user not found!\n";
}
