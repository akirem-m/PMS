<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$params = ['offices', 'office_id', 'office_id', '1'];

$extra = [];
$segments = array_values(array_slice($params, 2));
$count = count($segments);
for ($i = 0; $i < $count; $i += 2) {
    $extra[$segments[$i]] = $segments[$i + 1];
}
echo 'extra conditions='.json_encode($extra)."\n";

$count = Illuminate\Support\Facades\DB::table('offices')
    ->where('office_id', '=', 1)
    ->where('office_id', $extra['office_id'])
    ->count();
echo "verifier count for office 1 = {$count} (expected 1)\n";

$count6 = Illuminate\Support\Facades\DB::table('offices')
    ->where('office_id', '=', 6)
    ->where('office_id', $extra['office_id'])
    ->count();
echo "verifier count for office 6 = {$count6} (expected 1 if office 6 exists)\n";

echo 'office 6 exists='.var_export(Illuminate\Support\Facades\DB::table('offices')->where('office_id', 6)->exists(), true)."\n";

// Now run the full validator the way the FormRequest does.
$validator = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => 1],
    ['primary_office_id' => ['required', Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', 1)]]
);
echo 'validator passes='.var_export(! $validator->fails(), true).' errors='.json_encode($validator->errors()->all())."\n";

$validator2 = Illuminate\Support\Facades\Validator::make(
    ['primary_office_id' => 1],
    ['primary_office_id' => ['required', 'exists:offices,office_id,office_id,1']]
);
echo 'string-rule validator passes='.var_export(! $validator2->fails(), true).' errors='.json_encode($validator2->errors()->all())."\n";