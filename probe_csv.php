<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rule = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', 1);
echo 'rule: '.((string) $rule)."\n";

$params = explode(',', (string) $rule);
echo 'explode() default: '.json_encode($params)."\n";

$params2 = str_getcsv((string) $rule, ',', '"');
echo 'str_getcsv(): '.json_encode($params2)."\n";

// Does the validator parse CSV?
$ref = new ReflectionClass(Illuminate\Validation\Validator::class);
echo "validator file: ".$ref->getFileName()."\n";