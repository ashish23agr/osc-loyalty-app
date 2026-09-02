<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy migration staging.
 *
 * Built in sprint 1 with the rest of the schema so sprint 5 needs no schema
 * churn, and so the exception report has somewhere to land the day the
 * Dynamics / ORD export arrives.
 *
 * Every raw row is kept, so an exception can be understood without reopening
 * the source file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_batches', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');

            // A dry run commits nothing; the distinction is recorded, not implied.
            $table->enum('mode', ['profile', 'dry_run', 'import', 'delta']);

            $table->string('source_filename');

            // SHA-256. Half of the import idempotency key, so a resumed run
            // cannot double-post.
            $table->char('source_checksum', 64);

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('attached_count')->default(0);
            $table->unsignedInteger('exception_count')->default(0);
            $table->bigInteger('points_imported')->default(0);

            $table->enum('state', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->unsignedBigInteger('run_by_staff_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'mode', 'created_at'], 'ix_batch');
        });

        Schema::create('migration_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('migration_batch_id');
            $table->unsignedInteger('row_number');

            $table->json('raw');
            $table->json('parsed');

            $table->string('email_normalised')->nullable();
            $table->string('postcode_normalised', 10)->nullable();
            $table->string('legacy_card_number', 64)->nullable();

            // Postcode and surname support review, never a silent merge (D10).
            $table->enum('match_method', ['email', 'card', 'postcode_name', 'none']);

            // combine: two legacy rows share an exact email, so they are one
            //   person and their balances are added into a single account. The
            //   client is explicit that the unique identifier is the email, so
            //   this is the default resolution rather than an exception.
            // marketing_only: an email-only contact with no card and no
            //   membership becomes a Shopify marketing contact with NO loyalty
            //   account and no ledger.
            // exception: reserved for genuinely ambiguous matches.
            $table->enum('decision', [
                'create', 'attach', 'combine', 'marketing_only', 'review', 'exception', 'skipped',
            ]);

            $table->unsignedBigInteger('loyalty_account_id')->nullable();
            $table->integer('opening_balance_points')->nullable();

            // Feeds segmentation where Shopify order history cannot reach (D7).
            $table->dateTime('last_spend_at', 3)->nullable();

            $table->dateTime('created_at', 3);

            $table->unique(['migration_batch_id', 'row_number'], 'uq_record_row');
            $table->index(['migration_batch_id', 'decision'], 'ix_record_decision');
            $table->index('email_normalised', 'ix_record_email');
            $table->index('legacy_card_number', 'ix_record_card');

            $table->foreign('migration_batch_id', 'fk_record_batch')
                ->references('id')->on('migration_batches')->restrictOnDelete();
            $table->foreign('loyalty_account_id', 'fk_record_account')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
        });

        Schema::create('migration_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('migration_batch_id');
            $table->unsignedBigInteger('migration_record_id');

            // duplicate_email, no_match, bad_postcode, missing_dob, bad_balance
            $table->string('reason_code', 48);
            $table->string('reason_detail', 500)->nullable();

            $table->string('email')->nullable();

            // Included for OSC review, per the Blueprint.
            $table->string('postcode', 16)->nullable();
            $table->string('legacy_card_number', 64)->nullable();

            $table->enum('resolution', ['created', 'attached', 'merged', 'discarded'])->nullable();
            $table->dateTime('resolved_at', 3)->nullable();
            $table->unsignedBigInteger('resolved_by_staff_id')->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['migration_batch_id', 'reason_code'], 'ix_exception_batch');
            $table->index(['migration_batch_id', 'resolved_at'], 'ix_exception_open');

            $table->foreign('migration_batch_id', 'fk_exception_batch')
                ->references('id')->on('migration_batches')->restrictOnDelete();
            $table->foreign('migration_record_id', 'fk_exception_record')
                ->references('id')->on('migration_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_exceptions');
        Schema::dropIfExists('migration_records');
        Schema::dropIfExists('migration_batches');
    }
};
