<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log, staff roles, and the record of a merge.
 *
 * The audit log has no updated_at because an entry is never modified, and no
 * write endpoint exists anywhere in the application: entries are only ever
 * created in process, inside the same transaction as the effect they describe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');

            $table->enum('actor_type', ['staff', 'system', 'pos', 'migration']);
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('actor_name')->nullable();

            // points.adjusted, reward.cancelled, rules.saved, account.merged, ...
            $table->string('action', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();

            // Verbatim, and mandatory for the actions that require one.
            $table->string('reason', 500)->nullable();

            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();

            $table->enum('channel', ['admin', 'pos', 'account', 'system']);
            $table->string('ip_address', 45)->nullable();

            // Joins an audit entry to the application log lines for one request.
            $table->char('request_id', 36)->nullable();

            $table->dateTime('created_at', 3);

            $table->index(['shop_domain', 'created_at'], 'ix_audit_time');
            $table->index(['shop_domain', 'subject_type', 'subject_id', 'created_at'], 'ix_audit_subject');
            $table->index(['shop_domain', 'actor_staff_id', 'created_at'], 'ix_audit_actor');
            $table->index(['shop_domain', 'action', 'created_at'], 'ix_audit_action');
        });

        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');

            // The subject of the App Bridge session token. Resolving it to a
            // name needs read_users, which is protected, so an Administrator
            // labels each person once instead.
            $table->unsignedBigInteger('shopify_staff_id');
            $table->string('staff_name')->nullable();
            $table->string('staff_email')->nullable();

            $table->enum('role', ['viewer', 'agent', 'manager', 'administrator']);

            // Overrides the rule-set default for an Agent.
            $table->unsignedInteger('adjustment_limit_points')->nullable();

            $table->unsignedBigInteger('assigned_by_staff_id')->nullable();
            $table->timestamps();

            $table->unique(['shop_domain', 'shopify_staff_id'], 'uq_staff');
            $table->index(['shop_domain', 'role'], 'ix_staff_role');
        });

        Schema::create('account_merges', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->unsignedBigInteger('source_account_id');
            $table->unsignedBigInteger('target_account_id');
            $table->unsignedInteger('entries_replayed');

            // Balances before the merge, so a merge can be explained afterwards.
            $table->json('source_snapshot');

            $table->unsignedBigInteger('performed_by_staff_id');
            $table->string('reason', 500);
            $table->dateTime('created_at', 3);

            $table->index(['shop_domain', 'created_at'], 'ix_merge');

            $table->foreign('source_account_id', 'fk_merge_source')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
            $table->foreign('target_account_id', 'fk_merge_target')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE account_merges
                 ADD CONSTRAINT ck_merge_distinct CHECK (source_account_id <> target_account_id)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_merges');
        Schema::dropIfExists('staff_roles');
        Schema::dropIfExists('loyalty_audit_log');
    }
};
