<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Mirror ValidationRuleParser::parseParameters() exactly.
$param = 'offices,office_id,office_id,"1"';
echo 'str_getcsv(escape: "\\") = '.json_encode(str_getcsv($param, escape: '\\'))."\n";
echo 'str_getcsv(escape: "") = '.json_encode(str_getcsv($param, escape: ''))."\n";
echo 'str_getcsv(escape: "\0") = '.json_encode(str_getcsv($param, escape: "\0"))."\n";

echo 'php version='.PHP_VERSION."\n";

// And through the real parser:
$parsed = Illuminate\Validation\ValidationRuleParser::parse('exists:offices,office_id,office_id,"1"');
echo 'parsed='.json_encode($parsed)."\n";

$parsed2 = Illuminate\Validation\ValidationRuleParser::parse('exists:offices,office_id,office_id,"6"');
echo 'parsed2='.json_encode($parsed2)."\n";