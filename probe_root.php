<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Reproduce the internal path: Rule::exists()->where() serialises to
// exists:offices,office_id,office_id,"6" which the validator explodes into
// segments ["offices","office_id","office_id","\"6\""]. getExtraConditions()
// pairs them as [office_id => "\"6\""], i.e. a quote-wrapped value.

foreach ([1, 6] as $officeId) {
    $rule = Illuminate\Validation\Rule::exists('offices', 'office_id')->where('office_id', $officeId);
    $string = (string) $rule;
    echo "rule: {$string}\n";

    // What the validator ends up querying:
    $segments = explode(',', substr($string, strlen('exists:')));
    echo '  segments: '.json_encode($segments)."\n";
    $extra = [];
    for ($i = 2; $i < count($segments); $i += 2) {
        $extra[$segments[$i]] = $segments[$i + 1];
    }
    echo '  extra conditions: '.json_encode($extra)."\n";

    $count = Illuminate\Support\Facades\DB::table('offices')
        ->where('office_id', '=', $officeId)
        ->where('office_id', $extra['office_id'] ?? null)
        ->count();
    echo "  resulting count={$count} (expected 1)\n\n";
}