<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only ledger, and the lot allocations that record which earning was
 * consumed by a redemption, an expiry or a reversal.
 *
 * Two signed delta columns rather than one signed amount plus a state column:
 * points live in one of two buckets, and every movement is a transfer between
 * buckets or in and out of one. Balances are then SUM(pending_delta) and
 * SUM(available_delta) over immutable rows, which is what lets
 * loyalty:rebuild-balances reproduce a balance exactly rather than approximately.
 *
 * There is deliberately no UPDATE or DELETE path in the application. In
 * production the application database user should not hold those grants on this
 * table or on loyalty_audit_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->unsignedBigInteger('loyalty_account_id');

            $table->enum('entry_type', [
                'earn',
                'maturity',
                'expiry',
                'redemption',
                'redemption_restore',
                'earn_reversal',
                'adjustment',
                'opening_balance',
            ]);

            // Signed. earn: pending +N. maturity: pending -N, available +N.
            // expiry / redemption / reversal: one bucket each.
            $table->integer('pending_delta')->default(0);
            $table->integer('available_delta')->default(0);

            // Unique per shop. A redelivered webhook cannot double-post.
            $table->string('idempotency_key', 191);

            $table->unsignedBigInteger('rules_version_id');
            $table->enum('channel', ['online', 'pos', 'admin', 'system', 'migration']);

            $table->unsignedBigInteger('shopify_order_id')->nullable();
            $table->string('order_name', 64)->nullable();
            $table->unsignedBigInteger('shopify_refund_id')->nullable();
            $table->unsignedBigInteger('shopify_location_id')->nullable();
            $table->string('staff_reference', 128)->nullable();

            $table->unsignedBigInteger('redemption_id')->nullable();
            $table->unsignedBigInteger('reward_id')->nullable();

            // Maturity points at the earn it matured; a reversal points at what
            // it reversed. Lets a member history explain every movement without
            // inference.
            $table->unsignedBigInteger('parent_entry_id')->nullable();

            // The base the earn was computed from (D3), kept so an earn can be
            // explained without re-reading the order.
            $table->unsignedBigInteger('qualifying_value_pence')->nullable();

            // Earn and opening_balance entries only. Per D2 the expiry clock
            // starts at maturity, so expires_at is derived from matures_at and
            // every member gets a full expiry period of usable life.
            $table->dateTime('matures_at', 3)->nullable();
            $table->dateTime('expires_at', 3)->nullable();

            // Mandatory for adjustments.
            $table->string('reason', 500)->nullable();

            // The business event time, not the write time. A webhook that
            // arrives late still records when the thing happened.
            $table->dateTime('occurred_at', 3);

            $table->dateTime('created_at', 3);

            $table->unique(['shop_domain', 'idempotency_key'], 'uq_ledger_idem');
            $table->index(['loyalty_account_id', 'occurred_at', 'id'], 'ix_ledger_account');
            $table->index(['shop_domain', 'entry_type', 'matures_at'], 'ix_ledger_maturity');
            $table->index(['shop_domain', 'entry_type', 'expires_at'], 'ix_ledger_expiry');
            $table->index(['shop_domain', 'shopify_order_id'], 'ix_ledger_order');
            $table->index(['shop_domain', 'entry_type', 'occurred_at'], 'ix_ledger_type_time');

            $table->foreign('loyalty_account_id', 'fk_ledger_account')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
            $table->foreign('rules_version_id', 'fk_ledger_rules')
                ->references('id')->on('loyalty_rules_versions')->restrictOnDelete();
            $table->foreign('redemption_id', 'fk_ledger_redemption')
                ->references('id')->on('loyalty_redemptions')->restrictOnDelete();
            $table->foreign('reward_id', 'fk_ledger_reward')
                ->references('id')->on('loyalty_rewards')->restrictOnDelete();
            $table->foreign('parent_entry_id', 'fk_ledger_parent')
                ->references('id')->on('loyalty_ledger')->restrictOnDelete();
        });

        Schema::create('loyalty_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');

            // An earn or opening_balance entry.
            $table->unsignedBigInteger('lot_entry_id');

            // A redemption, expiry or reversal entry.
            $table->unsignedBigInteger('consuming_entry_id');

            $table->unsignedInteger('points');
            $table->dateTime('created_at', 3);

            $table->unique(['consuming_entry_id', 'lot_entry_id'], 'uq_alloc');
            $table->index('lot_entry_id', 'ix_alloc_lot');
            $table->index(['shop_domain', 'created_at'], 'ix_alloc_shop');

            $table->foreign('lot_entry_id', 'fk_alloc_lot')
                ->references('id')->on('loyalty_ledger')->restrictOnDelete();
            $table->foreign('consuming_entry_id', 'fk_alloc_consuming')
                ->references('id')->on('loyalty_ledger')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE loyalty_ledger
                 ADD CONSTRAINT ck_ledger_non_empty
                 CHECK (pending_delta <> 0 OR available_delta <> 0)'
            );
            DB::statement(
                "ALTER TABLE loyalty_ledger
                 ADD CONSTRAINT ck_ledger_adjustment_reason
                 CHECK (entry_type <> 'adjustment' OR reason IS NOT NULL)"
            );
            DB::statement(
                'ALTER TABLE loyalty_lot_allocations
                 ADD CONSTRAINT ck_alloc_points CHECK (points > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_lot_allocations');
        Schema::dropIfExists('loyalty_ledger');
    }
};
