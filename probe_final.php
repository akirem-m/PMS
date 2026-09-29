<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. The rule the FormRequest builds for an admin whose office_id is 6.
$admin = App\Models\User::where('email', 'admin@pms.test')->first();
$rule = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', (int) $admin->office_id);
echo 'admin office_id='.$admin->office_id.' rule='.((string) $rule)."\n";

$v = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => 1],
    ['primary_office_id' => ['required', $rule]]
);
echo 'admin rule passes='.var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";

// 2. Non-admin director whose office IS the primary office.
$director = App\Models\User::where('email', 'director@example.com')->first();
$rule2 = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', (int) $director->office_id);
$v2 = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => 1],
    ['primary_office_id' => ['required', $rule2]]
);
echo 'director (office='.$director->office_id.') rule passes='.var_export(! $v2->fails(), true).' errors='.json_encode($v2->errors()->all())."\n";

// 3. Does the currentRule get set on a fresh validator?
echo '--- direct string rule ---'."\n";
$v3 = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => 1],
    ['primary_office_id' => ['required', 'exists:offices,office_id,office_id,1']]
);
echo 'string passes='.var_export(! $v3->fails(), true).' errors='.json_encode($v3->errors()->all())."\n";