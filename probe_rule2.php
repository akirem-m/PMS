<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rule = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', 1);

$v = Illuminate\Support\Facades\Validator::make(['primary_office_id' => 1], ['primary_office_id' => ['required', $rule]]);
echo 'A rule-object passes='.var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";

$v = Illuminate\Support\Facades\Validator::make(['primary_office_id' => 1], ['primary_office_id' => ['required', 'exists:offices,office_id']]);
echo 'B plain string passes='.var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";

$v = Illuminate\Support\Facades\Validator::make(['primary_office_id' => 1], ['primary_office_id' => ['required', Illuminate\Validation\Rule::exists('offices', 'office_id')]]);
echo 'C rule no where passes='.var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";

$v = Illuminate\Support\Facades\Validator::make(['primary_office_id' => 1], ['primary_office_id' => ['required', Illuminate\Validation\Rule::exists('offices', 'office_id')->where(fn ($q) => $q->where('office_id', 1))]]);
echo 'D rule closure where passes='.var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";

echo 'laravel='.app()->version()."\n";