<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach ([1, 6] as $officeId) {
    $rule = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', $officeId);
    echo "officeId={$officeId} -> rule string: ".((string) $rule)."\n";

    $v = Illuminate\Support\Facades\Validator::make(
        ['primary_office_id' => $officeId],
        ['primary_office_id' => ['required', (string) $rule]]
    );
    echo "   as-string passes=".var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";
}

// What does a real StoreProjectRequest build for the admin?
$admin = App\Models\User::where('email', 'admin@pms.test')->first();
echo 'admin office_id='.var_export($admin->office_id, true)."\n";
echo "creatorOfficeId cast = ".(int) ($admin->office_id ?? 0)."\n";

$rule = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', (int) $admin->office_id);
echo "admin rule string: ".((string) $rule)."\n";