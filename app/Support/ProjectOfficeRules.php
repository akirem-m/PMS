<?php

namespace App\Support;

use App\Models\User;
use App\Services\UserResolver;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The office boundary rules shared by the project form request and the
 * multi-step creation wizard.
 *
 * Both paths must agree: when they did not, a payload the wizard accepted was
 * rejected by the form request a step later, and an administrator (who owns
 * no office) could never satisfy either one even though the create page offers
 * them a picklist of every office.
 */
class ProjectOfficeRules
{
    /**
     * True when the creator may only create projects inside their own office.
     * A system administrator has no office and picks one from the full list.
     */
    public static function officeBound(?User $creator): bool
    {
        return (int) ($creator?->office_id ?? 0) > 0
            && ! ($creator?->canAccessGlobalScope() ?? false);
    }

    /**
     * Any existing office for a global creator, the creator's own otherwise.
     */
    public static function primaryOffice(?User $creator): Exists
    {
        $rule = Rule::exists('offices', 'office_id');

        return self::officeBound($creator)
            ? $rule->where('office_id', (int) $creator->office_id)
            : $rule;
    }

    /**
     * A project manager / member value, which may be a user id, an e-mail
     * address or a typed name. A value that resolves must be an active account
     * inside the creator's office; a typed name that matches nobody is created
     * as a placeholder on submit, so validation lets it through untouched.
     */
    public static function directoryUser(?User $creator): Closure
    {
        $officeBound = self::officeBound($creator);
        $creatorOfficeId = (int) ($creator?->office_id ?? 0);

        return function (string $attribute, mixed $value, Closure $fail) use ($officeBound, $creatorOfficeId): void {
            if ($value === null || $value === '' || is_array($value)) {
                return;
            }

            $user = app(UserResolver::class)->find($value);

            if (! $user) {
                if (is_numeric($value)) {
                    $fail('The selected user is invalid.');
                }

                return;
            }

            if (! $user->isActive()) {
                $fail('The selected user is not an active account.');

                return;
            }

            if ($officeBound && ! $user->isGlobal() && (int) $user->office_id !== $creatorOfficeId) {
                $fail('The selected user belongs to a different office than your own.');
            }
        };
    }
}
