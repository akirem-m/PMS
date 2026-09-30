<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two historical migrations disagreed about the shape of `task_assignments`:
 *
 *  - 2026_09_02_000002 created it with `id`, `acceptance_status`,
 *    `rejection_reason`, `assigned_by`, `role_label`.
 *  - 2026_09_12_000003 expected `task_assignment_id`, `status`,
 *    `response_reason` (and only created the table when it was absent, so on
 *    every database built in file order those columns never existed).
 *
 * This migration makes the richer schema canonical: the primary key is the
 * codebase-wide `task_assignment_id` convention, `acceptance_status` /
 * `rejection_reason` are the single source of truth for the assignment
 * decision, and the legacy `status` / `response_reason` columns are folded in
 * and dropped so no code path can read the wrong one again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_assignments')) {
            return;
        }

        // 1. Ensure every canonical column exists (defensive: a database built
        //    through the 2026_09_12 path may be missing some of them).
        Schema::table('task_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('task_assignments', 'role_label')) {
                $table->string('role_label', 100)->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('task_assignments', 'acceptance_status')) {
                $table->string('acceptance_status', 30)->default('Pending Acceptance')->after('role_label');
            }
            if (! Schema::hasColumn('task_assignments', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('acceptance_status');
            }
            if (! Schema::hasColumn('task_assignments', 'assigned_by')) {
                $table->foreignId('assigned_by')->nullable()->after('rejection_reason')
                    ->constrained('users', 'user_id')->nullOnDelete();
            }
            if (! Schema::hasColumn('task_assignments', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            }
            if (! Schema::hasColumn('task_assignments', 'responded_at')) {
                $table->timestamp('responded_at')->nullable()->after('assigned_at');
            }
            if (! Schema::hasColumn('task_assignments', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('task_assignments', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        // 2. Fold legacy `status` / `response_reason` values into the canonical
        //    columns, then drop them.
        if (Schema::hasColumn('task_assignments', 'status')) {
            DB::table('task_assignments')->whereIn('status', ['accepted', 'Accepted'])
                ->update(['acceptance_status' => 'Accepted']);
            DB::table('task_assignments')->whereIn('status', ['rejected', 'Rejected'])
                ->update(['acceptance_status' => 'Rejected']);
            DB::table('task_assignments')->whereIn('status', ['pending', 'Pending'])
                ->update(['acceptance_status' => 'Pending Acceptance']);

            if (Schema::hasColumn('task_assignments', 'response_reason')
                && Schema::hasColumn('task_assignments', 'rejection_reason')) {
                DB::table('task_assignments')
                    ->whereNotNull('response_reason')
                    ->whereNull('rejection_reason')
                    ->update(['rejection_reason' => DB::raw('response_reason')]);
            }

            Schema::table('task_assignments', function (Blueprint $table): void {
                $table->dropColumn('status');
                if (Schema::hasColumn('task_assignments', 'response_reason')) {
                    $table->dropColumn('response_reason');
                }
            });
        } elseif (Schema::hasColumn('task_assignments', 'response_reason')) {
            DB::table('task_assignments')
                ->whereNotNull('response_reason')
                ->whereNull('rejection_reason')
                ->update(['rejection_reason' => DB::raw('response_reason')]);

            Schema::table('task_assignments', function (Blueprint $table): void {
                $table->dropColumn('response_reason');
            });
        }

        // 3. Normalize any surviving lowercase decision values to the canonical
        //    vocabulary used across the application.
        DB::table('task_assignments')->where('acceptance_status', 'pending')
            ->update(['acceptance_status' => 'Pending Acceptance']);
        DB::table('task_assignments')->where('acceptance_status', 'accepted')
            ->update(['acceptance_status' => 'Accepted']);
        DB::table('task_assignments')->where('acceptance_status', 'rejected')
            ->update(['acceptance_status' => 'Rejected']);

        // 4. Rename the primary key to the application-wide convention so the
        //    model and route-model binding resolve the pivot row correctly.
        if (Schema::hasColumn('task_assignments', 'id')
            && ! Schema::hasColumn('task_assignments', 'task_assignment_id')) {
            Schema::table('task_assignments', function (Blueprint $table): void {
                $table->renameColumn('id', 'task_assignment_id');
            });
        }

        // 5. Missing lookup indexes: user_id alone is not covered by the
        //    (task_id, user_id) unique key, and acceptance_status filters run
        //    for every task panel load.
        $indexes = collect(Schema::getIndexes('task_assignments'))->pluck('name');

        Schema::table('task_assignments', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->contains('task_assignments_user_id_index')) {
                $table->index('user_id', 'task_assignments_user_id_index');
            }
            if (! $indexes->contains('task_assignments_acceptance_status_index')) {
                $table->index('acceptance_status', 'task_assignments_acceptance_status_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('task_assignments')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('task_assignments'))->pluck('name');

        Schema::table('task_assignments', function (Blueprint $table) use ($indexes): void {
            if ($indexes->contains('task_assignments_user_id_index')) {
                $table->dropIndex('task_assignments_user_id_index');
            }
            if ($indexes->contains('task_assignments_acceptance_status_index')) {
                $table->dropIndex('task_assignments_acceptance_status_index');
            }

            if (Schema::hasColumn('task_assignments', 'task_assignment_id')
                && ! Schema::hasColumn('task_assignments', 'id')) {
                $table->renameColumn('task_assignment_id', 'id');
            }
        });
    }
};
