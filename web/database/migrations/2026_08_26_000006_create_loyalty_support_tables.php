<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables: Klaviyo sync state, webhook delivery record, report exports.
 *
 * webhook_events exists because the Blueprint assumes webhooks arrive late,
 * twice or out of order. Recording the delivery is what makes that diagnosable
 * rather than mysterious, and it is the outer guard in front of the ledger
 * idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('klaviyo_sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->unsignedBigInteger('loyalty_account_id');

            $table->string('klaviyo_profile_id', 64)->nullable();

            // An unchanged profile is never pushed twice, which is what makes
            // the nightly reconcile cheap.
            $table->char('traits_hash', 64)->nullable();

            $table->dateTime('last_pushed_at', 3)->nullable();
            $table->enum('last_status', ['ok', 'failed', 'skipped'])->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_retry_at', 3)->nullable();
            $table->timestamps();

            $table->unique('loyalty_account_id', 'uq_klaviyo_account');
            $table->index(['shop_domain', 'last_status', 'next_retry_at'], 'ix_klaviyo_retry');

            $table->foreign('loyalty_account_id', 'fk_klaviyo_account')
                ->references('id')->on('loyalty_accounts')->restrictOnDelete();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->string('topic', 64);

            // The X-Shopify-Webhook-Id header. Unique per shop, so a redelivery
            // is recognised before any handler runs.
            $table->char('shopify_webhook_id', 36);

            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->enum('state', ['received', 'processed', 'failed', 'ignored'])->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();

            $table->dateTime('received_at', 3);
            $table->dateTime('processed_at', 3)->nullable();

            $table->unique(['shop_domain', 'shopify_webhook_id'], 'uq_webhook');
            $table->index(['shop_domain', 'topic', 'received_at'], 'ix_webhook_topic');
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain');
            $table->string('report_key', 48);
            $table->enum('format', ['pdf', 'csv']);
            $table->json('filters');

            $table->enum('state', ['queued', 'running', 'ready', 'failed'])->default('queued');
            $table->string('file_path', 500)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('requested_by_staff_id')->nullable();

            // Generated files are cleaned up rather than kept forever.
            $table->dateTime('expires_at', 3)->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'report_key', 'created_at'], 'ix_export');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('klaviyo_sync_state');
    }
};
