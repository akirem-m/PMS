<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = App\Models\User::where('email', 'admin@pms.test')->first();
echo 'admin office_id raw='.var_export($admin->office_id, true).' cast='.var_export((int) $admin->office_id, true)."\n";
echo 'ICT office_id raw='.var_export(App\Models\Office::where('office_code', 'ICT')->value('office_id'), true)."\n";

echo 'office exists='.var_export(App\Models\Office::where('office_id', 1)->exists(), true)."\n";
echo 'Office find 1='.var_export(App\Models\Office::find(1)?->office_name, true)."\n";

$v = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => 1],
    ['primary_office_id' => ['required', Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', (int) $admin->office_id)]]
);
echo 'rule(int) passes='.var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";

$v2 = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => 1],
    ['primary_office_id' => ['required', Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', $admin->office_id)]]
);
echo 'rule(raw) passes='.var_export(! $v2->fails(), true).' errors='.json_encode($v2->errors()->all())."\n";

echo 'raw sql: '.\Illuminate\Support\Facades\DB::table('offices')->where('office_id', 1)->toSql()."\n";
echo 'count='.\Illuminate\Support\Facades\DB::table('offices')->where('office_id', 1)->count()."\n";