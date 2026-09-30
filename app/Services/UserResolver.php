<?php

namespace App\Services;

use App\Models\TeamMember;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Single source of truth for turning a submitted assignee/member value
 * (primary key, e-mail address or free-text name) into a user id.
 *
 * Team, task and project forms each used to carry their own near-identical
 * copy of this logic and had drifted apart, so the same typed name was
 * accepted on one screen and rejected on another. Everything now funnels
 * through here.
 *
 * A name that matches nobody is treated as a new person and gets a
 * placeholder account so the work item can be assigned immediately. That
 * placeholder is deliberately unprivileged: it keeps the default `guest`
 * role, gets no RBAC role and gets an unguessable password, so it can hold
 * work but cannot sign in until an administrator assigns it a role.
 *
 * It also inherits the office of whoever typed the name. Leaving it
 * office-less would let any member sneak an out-of-office person onto a team
 * or project, because the office-scope checks only bind users that have an
 * office.
 */
class UserResolver
{
    /** Sentinel option labels the assignee/member pickers submit. */
    private const NONE_LABELS = ['none', 'null', 'unassigned', '-', 'n/a'];

    /**
     * Resolve $input to a user id, creating a placeholder user for a typed
     * name that matches nobody. Returns null for an empty value or one of the
     * picker's placeholder options.
     */
    public function resolve(string|int|null $input, ?int $teamId = null): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }

        $user = $this->find($input);

        if ($user) {
            return $user->user_id;
        }

        $name = trim((string) $input);

        if ($this->isSentinel($name) || is_numeric($input)) {
            return null;
        }

        return $this->createPlaceholder($name, $teamId);
    }

    /**
     * Look up an existing user by id, e-mail or name without creating one.
     */
    public function find(string|int|null $input): ?User
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (is_numeric($input)) {
            return User::find((int) $input);
        }

        $name = trim((string) $input);

        if ($this->isSentinel($name)) {
            return null;
        }

        return User::query()
            ->where('email', $name)
            ->orWhere('full_name', $name)
            ->orWhereRaw('LOWER(full_name) = ?', [Str::lower($name)])
            ->first()
            ?? User::where('full_name', 'LIKE', '%'.$name.'%')->first();
    }

    /**
     * True when the value is one of the pickers' placeholder options rather
     * than a person ("— Select Member —", "None", "Unassigned", ...).
     */
    public function isSentinel(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || in_array(Str::lower($value), self::NONE_LABELS, true)) {
            return true;
        }

        return (bool) preg_match('/^[—–-]{1,3}.*[—–-]{1,3}$/u', $value);
    }

    private function createPlaceholder(string $name, ?int $teamId): ?int
    {
        $user = User::create([
            'full_name' => $name,
            'email' => $this->uniqueEmail($name),
            'password_hash' => bcrypt(Str::random(40)),
            'status' => 'Active',
            'department' => 'Staff',
            'office_id' => Auth::user()?->office_id,
        ]);

        if ($teamId) {
            TeamMember::firstOrCreate(
                ['team_id' => $teamId, 'user_id' => $user->user_id],
                ['joined_date' => now()->toDateString()]
            );
        }

        Activity::log('Added new member by name', 'User', $user->user_id, "{$user->full_name} ({$user->email})");

        return $user->user_id;
    }

    /**
     * A slugged, collision-free @example.com address for a typed name.
     */
    private function uniqueEmail(string $name): string
    {
        $slug = trim(Str::lower(preg_replace('/[^a-zA-Z0-9]+/', '.', $name)), '.');

        if ($slug === '') {
            $slug = 'member.'.Str::lower(Str::random(6));
        }

        $email = $slug.'@example.com';
        $counter = 1;

        while (User::where('email', $email)->exists()) {
            $email = $slug.$counter.'@example.com';
            $counter++;
        }

        return $email;
    }
}
