<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (['app', 'production'] as $env) {
    echo "--- env={$env} ---\n";
    foreach ([1, 6, '1', '6'] as $officeId) {
        $v = Illuminate\Support\Facades\Validator::make(
            ['primary_office_id' => $officeId],
            ['primary_office_id' => ['required', Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', $officeId)]]
        );
        echo "  officeId=".var_export($officeId, true).' passes='.var_export(! $v->fails(), true).' errors='.json_encode($v->errors()->all())."\n";
    }
}

echo "--- exists rule internals ---\n";
$r = new ReflectionClass(Illuminate\Validation\Rules\Exists::class);
echo 'file='.$r->getFileName()."\n";
echo "methods: ".implode(', ', array_map(fn ($m) => $m->getName(), $r->getMethods()))."\n";

echo "--- using current env ---\n";
echo 'env='.app()->environment()."\n";