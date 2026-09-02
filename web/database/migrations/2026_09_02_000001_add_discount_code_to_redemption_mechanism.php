<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third redemption mechanism: the single-use discount code (D5, revised
 * 2 Sep 2026).
 *
 * Additive and nothing else. The existing values keep their meaning and no row
 * is rewritten, because `function` rows are real history — the mechanism that
 * applied a discount months ago is not changed by us choosing a different one
 * today.
 *
 * **Why the enum grows rather than becoming a string.** This column is read to
 * decide which gateway withdraws a quote, so a typo in it would silently strand
 * a published entitlement. The database refusing an unknown mechanism is the
 * same reasoning as `ck_alloc_points` on the allocations table.
 *
 * **Verify on MySQL, not only SQLite.** D9a's lesson: the suite runs on SQLite,
 * where an enum is a CHECK constraint and unsigned columns are not enforced at
 * all, so a column change that looks fine in the suite can be refused by MySQL.
 * `->change()` is used because Laravel rebuilds the table on SQLite and issues a
 * `MODIFY` on MySQL, which is exactly the difference that has to be right.
 */
return new class extends Migration
{
    /** The mechanism a redemption was applied by. Matches RedemptionGateway::mechanism(). */
    private const MECHANISMS = ['function', 'pos_cart_discount', 'discount_code'];

    private const BEFORE = ['function', 'pos_cart_discount'];

    public function up(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table): void {
            $table->enum('discount_mechanism', self::MECHANISMS)->change();
        });
    }

    /**
     * Reversible only while no row uses the new value.
     *
     * Deliberately not defensive about that: a down migration that quietly
     * rewrote live `discount_code` rows to something else would destroy the
     * record of how a customer's discount was actually applied. If the rollback
     * fails because such rows exist, that is the correct outcome.
     */
    public function down(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table): void {
            $table->enum('discount_mechanism', self::BEFORE)->change();
        });
    }
};
