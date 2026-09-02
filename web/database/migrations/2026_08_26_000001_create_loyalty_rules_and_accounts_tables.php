<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rule versions and member accounts.
 *
 * Order matters across the loyalty migrations: rule versions come first because
 * every later table records the version its numbers were calculated against.
 *
 * CHECK constraints are added only on MySQL. SQLite cannot add one with
 * ALTER TABLE, and the test suite runs on SQLite in memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rules_versions', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->unsignedInteger('version');

            // A full snapshot, never updated in place, so any historical
            // calculation can be replayed against the rules in force at the time.
            $table->json('payload');

            // Changes apply from the moment they are saved and are never
            // applied retrospectively.
            $table->dateTime('effective_from', 3);

            $table->string('change_summary', 500)->nullable();
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['shop_domain', 'version'], 'uq_rules_version');
            $table->index(['shop_domain', 'effective_from'], 'ix_rules_effective');
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');

            // Null only while a staged legacy record is waiting for a Shopify
            // customer to be created or matched.
            $table->unsignedBigInteger('shopify_customer_id')->nullable();

            // Nullable, client-confirmed 2026-08-06: legacy members with no
            // email address migrate and must stay searchable at the till and in
            // the console. Identity for those members falls back to the card
            // number, then postcode plus surname (see MatchStrategy).
            $table->string('email')->nullable();

            // Lower-cased and trimmed. This is the deduplication key, and the
            // unique index on it is the enforcement of D10. Both MySQL and
            // SQLite permit repeated NULLs in a unique index, so any number of
            // members may exist without an email while the index still forbids
            // two members sharing one.
            $table->string('email_normalised')->nullable();

            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('postcode', 16)->nullable();
            $table->string('postcode_normalised', 10)->nullable();
            // Optional. A member may give only a day and month, so the full
            // date is the incomplete case rather than the canonical one.
            $table->date('date_of_birth')->nullable();

            // Real writable columns, not generated ones. Client-confirmed
            // 2026-08-06: a customer may supply day and month with no year and
            // must still receive the birthday voucher, so these are the
            // authoritative birthday fields. When date_of_birth is present it
            // populates them; when it is absent they stand on their own.
            // (Generated columns were never viable anyway: MONTH() and DAY()
            // do not exist in SQLite, where the test suite runs.)
            $table->unsignedTinyInteger('dob_month')->nullable();
            $table->unsignedTinyInteger('dob_day')->nullable();

            // Mirrored from the OSC customer metafield, for report filtering only.
            $table->string('gender', 32)->nullable();

            $table->string('legacy_card_number', 64)->nullable();
            $table->enum('enrolment_channel', ['online', 'pos', 'admin', 'migration']);
            $table->dateTime('enrolled_at', 3);

            $table->enum('segment', ['active', 'lapsed', 'unknown'])->default('unknown');
            $table->dateTime('segment_calculated_at', 3)->nullable();
            $table->dateTime('last_qualifying_spend_at', 3)->nullable();

            // Caches. Disposable by design: loyalty:rebuild-balances reconstructs
            // every one of them from the ledger, and the ledger wins any
            // disagreement. points_available may go negative after a clawback.
            $table->integer('points_pending')->default(0);
            $table->integer('points_available')->default(0);
            $table->unsignedBigInteger('points_lifetime')->default(0);
            $table->unsignedBigInteger('voucher_balance_pence')->default(0);
            $table->dateTime('caches_rebuilt_at', 3)->nullable();

            $table->enum('status', ['active', 'suspended', 'merged', 'closed'])->default('active');
            $table->unsignedBigInteger('merged_into_account_id')->nullable();

            $table->boolean('email_marketing_consent')->nullable();
            $table->dateTime('consent_updated_at', 3)->nullable();

            $table->timestamps();

            $table->unique(['shop_domain', 'shopify_customer_id'], 'uq_account_customer');
            $table->unique(['shop_domain', 'email_normalised'], 'uq_account_email');
            $table->index(['shop_domain', 'legacy_card_number'], 'ix_account_card');
            $table->index(['shop_domain', 'postcode_normalised', 'last_name'], 'ix_account_postcode');
            $table->index(['shop_domain', 'segment', 'last_qualifying_spend_at'], 'ix_account_segment');
            $table->index(['shop_domain', 'dob_month', 'dob_day'], 'ix_account_birthday');
            $table->index(['shop_domain', 'enrolment_channel', 'enrolled_at'], 'ix_account_enrolled');

            $table->foreign('merged_into_account_id', 'fk_account_merged_into')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE loyalty_accounts
                 ADD CONSTRAINT ck_account_pending_non_negative CHECK (points_pending >= 0)'
            );
            DB::statement(
                "ALTER TABLE loyalty_accounts
                 ADD CONSTRAINT ck_account_merge_target
                 CHECK (status <> 'merged' OR merged_into_account_id IS NOT NULL)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_rules_versions');
    }
};
