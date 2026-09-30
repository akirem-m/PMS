<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenditure approval workflow:
 *
 *   Contributor submits (Pending) -> Team Lead / PM approves (Approved)
 *   -> Head of Office / Finance confirms disbursement (Completed).
 *
 * The approval and disbursement decisions are recorded on the payment row so
 * budgets, reports and the audit log can attribute who authorised the spend
 * and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('payments', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('created_by')
                    ->constrained('users', 'user_id')->nullOnDelete();
            }
            if (! Schema::hasColumn('payments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('payments', 'disbursed_by')) {
                $table->foreignId('disbursed_by')->nullable()->after('approved_at')
                    ->constrained('users', 'user_id')->nullOnDelete();
            }
            if (! Schema::hasColumn('payments', 'disbursed_at')) {
                $table->timestamp('disbursed_at')->nullable()->after('disbursed_by');
            }
            if (! Schema::hasColumn('payments', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('disbursed_at');
            }
        });

        $indexes = collect(Schema::getIndexes('payments'))->pluck('name');

        if (! $indexes->contains('payments_payment_status_index')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->index('payment_status', 'payments_payment_status_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('payments'))->pluck('name');

        Schema::table('payments', function (Blueprint $table) use ($indexes): void {
            if ($indexes->contains('payments_payment_status_index')) {
                $table->dropIndex('payments_payment_status_index');
            }

            foreach (['approved_by', 'disbursed_by'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['approved_at', 'disbursed_at', 'rejection_reason'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
