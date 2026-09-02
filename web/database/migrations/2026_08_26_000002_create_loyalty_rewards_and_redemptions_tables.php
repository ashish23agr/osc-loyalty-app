<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issued rewards and redemptions.
 *
 * Only genuinely issued objects live in loyalty_rewards. Per D1 the
 * points-derived voucher balance is NOT stored here: it is computed on read
 * from the ledger, so there is nothing to reconcile and nothing to expire
 * except the underlying points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->unsignedBigInteger('loyalty_account_id');

            $table->enum('reward_type', ['birthday', 'goodwill']);
            $table->unsignedInteger('value_pence');
            $table->char('currency', 3)->default('GBP');

            // Set for birthday rewards only. The unique index below is what
            // makes the daily job idempotent: it cannot double-issue, and needs
            // no bookkeeping of its own to know that.
            $table->unsignedSmallInteger('birthday_year')->nullable();

            $table->enum('state', ['issued', 'redeemed', 'expired', 'cancelled', 'superseded'])
                ->default('issued');

            $table->dateTime('issued_at', 3);
            $table->dateTime('expires_at', 3);
            $table->dateTime('redeemed_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->string('cancelled_reason', 500)->nullable();

            $table->unsignedBigInteger('superseded_by_reward_id')->nullable();

            // Null for the automatic birthday job.
            $table->unsignedBigInteger('issued_by_staff_id')->nullable();

            $table->unsignedBigInteger('rules_version_id');
            $table->timestamps();

            $table->unique(
                ['shop_domain', 'loyalty_account_id', 'reward_type', 'birthday_year'],
                'uq_reward_birthday'
            );
            $table->index(['shop_domain', 'state', 'expires_at'], 'ix_reward_state');
            $table->index(['loyalty_account_id', 'issued_at'], 'ix_reward_account');

            $table->foreign('loyalty_account_id', 'fk_reward_account')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
            $table->foreign('rules_version_id', 'fk_reward_rules')
                ->references('id')->on('loyalty_rules_versions')->restrictOnDelete();
            $table->foreign('superseded_by_reward_id', 'fk_reward_superseded')
                ->references('id')->on('loyalty_rewards')->restrictOnDelete();
        });

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');

            // Carried in the POS discount reason string, so the till receipt and
            // the audit log share an identifier for free (D6).
            $table->string('reference', 32);

            $table->unsignedBigInteger('loyalty_account_id');
            $table->enum('channel', ['online', 'pos']);
            $table->enum('state', ['quoted', 'applied', 'confirmed', 'void', 'reversed'])
                ->default('quoted');

            $table->unsignedInteger('amount_pence');

            // Stays 0 until the order is paid. A quote that is never confirmed
            // consumes nothing.
            $table->unsignedInteger('points_consumed')->default(0);

            // Set when an issued reward was spent rather than points.
            $table->unsignedBigInteger('reward_id')->nullable();

            $table->enum('discount_mechanism', ['function', 'pos_cart_discount']);
            $table->string('shopify_discount_gid')->nullable();
            $table->unsignedBigInteger('shopify_order_id')->nullable();
            $table->string('order_name', 64)->nullable();
            $table->unsignedBigInteger('shopify_location_id')->nullable();
            $table->string('staff_reference', 128)->nullable();

            $table->unsignedBigInteger('rules_version_id');

            // Every figure in the limit ladder, as it stood at quote time, so a
            // dispute months later has an answer.
            $table->json('validation_snapshot');

            $table->dateTime('quoted_at', 3);
            $table->dateTime('quote_expires_at', 3);
            $table->dateTime('confirmed_at', 3)->nullable();
            $table->dateTime('reversed_at', 3)->nullable();
            $table->timestamps();

            $table->unique(['shop_domain', 'reference'], 'uq_redemption_ref');
            $table->index(['shop_domain', 'shopify_order_id'], 'ix_redemption_order');
            $table->index(['loyalty_account_id', 'quoted_at'], 'ix_redemption_acct');
            $table->index(['shop_domain', 'state', 'quote_expires_at'], 'ix_redemption_state');
            $table->index(['shop_domain', 'channel', 'confirmed_at'], 'ix_redemption_chan');

            $table->foreign('loyalty_account_id', 'fk_redemption_account')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
            $table->foreign('reward_id', 'fk_redemption_reward')
                ->references('id')->on('loyalty_rewards')->restrictOnDelete();
            $table->foreign('rules_version_id', 'fk_redemption_rules')
                ->references('id')->on('loyalty_rules_versions')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE loyalty_rewards
                 ADD CONSTRAINT ck_reward_birthday_year
                 CHECK (reward_type <> 'birthday' OR birthday_year IS NOT NULL)"
            );
            DB::statement(
                "ALTER TABLE loyalty_rewards
                 ADD CONSTRAINT ck_reward_cancel_reason
                 CHECK (state <> 'cancelled' OR cancelled_reason IS NOT NULL)"
            );
            DB::statement(
                'ALTER TABLE loyalty_redemptions
                 ADD CONSTRAINT ck_redemption_amount CHECK (amount_pence > 0)'
            );
            DB::statement(
                "ALTER TABLE loyalty_redemptions
                 ADD CONSTRAINT ck_redemption_confirmed_order
                 CHECK (state <> 'confirmed' OR shopify_order_id IS NOT NULL)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_rewards');
    }
};
