<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Validate through a real FormRequest subclass so we exercise the same path
// as the controller (validator factory, redirect, error bag).
class ProbeStoreRequest extends App\Http\Requests\StoreProjectRequest
{
    public function authorize(): bool
    {
        return true;
    }
}

$ict = App\Models\Office::where('office_code', 'ICT')->first();
$admin = App\Models\User::where('email', 'admin@pms.test')->first();
$director = App\Models\User::where('email', 'director@example.com')->first();

foreach (['admin' => $admin, 'director' => $director] as $label => $user) {
    auth()->login($user);

    $request = ProbeStoreRequest::create('/projects', 'POST', [
        'project_name' => 'Admin Assign Project',
        'project_type' => 'Software',
        'primary_office_id' => $ict->office_id,
        'team_id' => 1,
        'members' => [['user_id' => $user->user_id, 'specialty' => 'Backend Developer']],
    ]);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    try {
        $request->validateResolved();
        echo "{$label}: PASSED validation\n";
    } catch (Illuminate\Validation\ValidationException $e) {
        echo "{$label}: FAILED -> ".json_encode($e->errors())."\n";
    }

    auth()->logout();
}