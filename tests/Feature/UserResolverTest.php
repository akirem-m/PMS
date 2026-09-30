<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\UserResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The team, task and project forms all funnel typed assignee/member values
 * through this resolver, so its behaviour is the contract they share.
 */
class UserResolverTest extends TestCase
{
    use RefreshDatabase;

    private UserResolver $resolver;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->resolver = app(UserResolver::class);
        $this->actor = User::where('email', 'director@example.com')->firstOrFail();
        $this->actingAs($this->actor);
    }

    public function test_it_resolves_an_existing_user_by_id_email_and_name(): void
    {
        $target = User::where('email', 'abebe@example.com')->firstOrFail();

        $this->assertSame($target->user_id, $this->resolver->resolve($target->user_id));
        $this->assertSame($target->user_id, $this->resolver->resolve('abebe@example.com'));
        $this->assertSame($target->user_id, $this->resolver->resolve('Abebe Bikila'));
        $this->assertSame($target->user_id, $this->resolver->resolve('abebe bikila'));
    }

    public function test_it_treats_picker_placeholders_and_empty_values_as_nobody(): void
    {
        foreach ([null, '', 'None', 'Unassigned', '— Select Member —', '— Unassigned —'] as $value) {
            $this->assertNull($this->resolver->resolve($value), 'Expected null for '.var_export($value, true));
        }
    }

    public function test_an_unknown_numeric_id_resolves_to_nobody_and_creates_nothing(): void
    {
        $before = User::count();

        $this->assertNull($this->resolver->resolve(999999));
        $this->assertSame($before, User::count());
    }

    public function test_a_typed_name_creates_an_unprivileged_placeholder_in_the_actors_office(): void
    {
        $team = Team::where('office_id', $this->actor->office_id)->firstOrFail();

        $userId = $this->resolver->resolve('Dawit Bekele', $team->team_id);

        $this->assertNotNull($userId);

        $created = User::findOrFail($userId);

        $this->assertSame('Dawit Bekele', $created->full_name);
        $this->assertSame('dawit.bekele@example.com', $created->email);
        $this->assertSame('Active', $created->status);
        // Unprivileged: assignment must not mint a working account.
        $this->assertSame('guest', $created->role);
        $this->assertFalse($created->roles()->exists());
        $this->assertFalse(password_verify('ChangeMe123!', $created->password_hash));
        $this->assertTrue($created->isGuest());
        // Inherits the actor's office so office-scope checks still bind it.
        $this->assertSame($this->actor->office_id, $created->office_id);
        $this->assertTrue($team->fresh()->members->contains('user_id', $created->user_id));
    }

    public function test_repeated_typed_names_reuse_the_placeholder_instead_of_duplicating_it(): void
    {
        $first = $this->resolver->resolve('New Person');

        $this->assertSame($first, $this->resolver->resolve('New Person'));
        $this->assertSame(1, User::where('full_name', 'New Person')->count());
        $this->assertSame(1, User::where('email', 'new.person@example.com')->count());
    }

    public function test_placeholder_addresses_stay_unique_across_names_that_slug_alike(): void
    {
        $this->resolver->resolve('New Person');
        $this->resolver->resolve('New-Person');

        $this->assertSame(1, User::where('email', 'new.person@example.com')->count());
        $this->assertSame(1, User::where('email', 'new.person1@example.com')->count());
    }
}
