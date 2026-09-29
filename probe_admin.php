<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ict = App\Models\Office::where('office_code', 'ICT')->first();
$admin = App\Models\User::where('email', 'admin@pms.test')->first();
$director = App\Models\User::where('email', 'director@example.com')->first();

echo 'admin canAccessGlobalScope='.var_export($admin->canAccessGlobalScope(), true)."\n";
echo 'admin hasRole(Administrator)='.var_export($admin->hasRole('Administrator'), true)."\n";
echo 'admin can create_projects='.var_export($admin->can('create_projects'), true)."\n";
echo 'ICT office_id='.var_export($ict->office_id, true)."\n";

$rule = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', (int) $admin->office_id);
$v = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => $ict->office_id],
    ['primary_office_id' => ['required', $rule]]
);
echo 'admin office rule passes='.var_export(! $v->fails(), true)."\n";
echo 'errors='.json_encode($v->errors()->all())."\n";

echo 'admin would abort in wizard='.var_export(! $admin->canAccessGlobalScope() && (int) $ict->office_id !== (int) $admin->office_id, true)."\n";
echo 'director would abort in wizard='.var_export(! $director->canAccessGlobalScope() && (int) $ict->office_id !== (int) $director->office_id, true)."\n";