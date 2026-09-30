<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Models\User;
use App\Services\UserResolver;
use App\Support\ProjectOfficeRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by Gate::authorize('create_projects') in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creator = $this->user();

        // Teams must belong to the project's primary or participating offices.
        $allowedOfficeIds = collect([(int) $this->input('primary_office_id')])
            ->merge((array) $this->input('participating_offices', []))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $teamRule = [
            'bail',
            'exists:teams,team_id',
            function (string $attribute, mixed $value, Closure $fail) use ($allowedOfficeIds): void {
                if ($allowedOfficeIds->isEmpty()) {
                    return;
                }

                $team = Team::find($value);

                // Teams with no office assignment are allowed anywhere
                // (matches the client-side filter in the wizard).
                if ($team && $team->office_id && ! $allowedOfficeIds->contains((int) $team->office_id)) {
                    $fail("The selected team's office is not one of this project's offices.");
                }
            },
        ];

        $userOfficeRule = function (string $attribute, mixed $value, Closure $fail) use ($allowedOfficeIds): void {
            $user = $this->resolveUserInput($value);
            if ($user && ! $user->isGlobal() && $allowedOfficeIds->isNotEmpty() && $user->office_id
                && ! $allowedOfficeIds->contains((int) $user->office_id)) {
                $fail("The selected user's office is not one of this project's offices.");
            }
        };

        // Project manager / member fields accept a user id, an e-mail address
        // or a typed name; see ProjectOfficeRules for the shared boundary.
        $directoryUserRule = ProjectOfficeRules::directoryUser($creator);

        return [
            'project_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'client' => ['nullable', 'string', 'max:150'],
            'project_type' => ['nullable', 'string', 'max:100'],
            'project_type_id' => ['nullable', 'exists:project_types,project_type_id'],
            'primary_office_id' => ['required', ProjectOfficeRules::primaryOffice($creator)],
            'project_manager_id' => ['nullable', $directoryUserRule],
            'project_manager_name' => ['nullable', $directoryUserRule],
            'team_id' => ['nullable', ...$teamRule],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => $teamRule,
            'teams' => ['nullable', 'array'],
            'teams.*' => $teamRule,
            'priority' => ['nullable', 'string', 'in:Low,Medium,High,Urgent'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'allocated_amount' => ['nullable', 'numeric', 'min:0'],
            'members' => ['nullable', 'array'],
            'members.*.user_id' => ['nullable', $directoryUserRule, $userOfficeRule],
            'members.*.user_name' => ['nullable', $directoryUserRule, $userOfficeRule],
            'members.*.role_id' => ['nullable', 'exists:roles,role_id'],
            'members.*.specialty' => ['nullable', 'string', 'max:100'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.task_name' => ['nullable', 'string', 'max:150'],
            'tasks.*.team_id' => $teamRule,
            'tasks.*.assigned_to' => ['nullable', $userOfficeRule],
            'tasks.*.user_ids' => ['nullable', 'array'],
            'tasks.*.user_ids.*' => ['nullable', $directoryUserRule, $userOfficeRule],
            'tasks.*.priority' => ['nullable', 'in:Low,Medium,High,Urgent'],
            'tasks.*.status' => ['nullable', 'string'],
            'tasks.*.budget' => ['nullable', 'numeric', 'min:0'],
            'tasks.*.start_date' => ['nullable', 'date'],
            'tasks.*.end_date' => ['nullable', 'date'],
            'tasks.*.description' => ['nullable', 'string'],
        ];
    }

    /**
     * Look up a submitted user value (id, e-mail or name) without creating
     * anything: validation must never have side effects.
     */
    private function resolveUserInput(mixed $value): ?User
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return app(UserResolver::class)->find($value);
    }
}
