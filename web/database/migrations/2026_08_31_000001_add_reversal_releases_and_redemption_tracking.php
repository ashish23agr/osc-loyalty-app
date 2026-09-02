<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D9a and D9d: release allocations, and reversal tracking on a redemption.
 *
 * D9a — restored points return to the lots the redemption consumed, keeping
 * their original expiry. A fresh lot would let a redeem-and-refund cycle extend
 * the life of points indefinitely, which is the same exploit class the
 * proportional rule closes. Because the ledger is append-only, an allocation
 * cannot be edited or removed, so a give-back is a NEGATIVE allocation row
 * pointing at the same lot: a release.
 *
 * The invariant, which every reader of this table already computes:
 *
 *     lot remaining = lot total - SUM(allocations)      releases being negative
 *     0 <= lot remaining <= lot total
 *
 * Both existing readers - LotAllocator::openLotsFor() and ExpiryOutlook - are
 * already SUM(alloc.points), so a signed row nets correctly with no change to
 * either query. What has to change is the CHECK, which forbade a negative.
 *
 * uq_alloc on (consuming_entry_id, lot_entry_id) still holds: one release row
 * per restore entry per lot, so a redelivered refund cannot release twice.
 *
 * D9d — a partial refund leaves loyalty_redemptions.state as it was and records
 * how much has been given back. Only a full reversal flips state to 'reversed'.
 * Flipping on the first partial would lose the handle a later refund needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table): void {
            // Cumulative, not per-refund. The reversal arithmetic is a
            // cumulative floor (D9c), so what it needs to know is the running
            // total already given back, never the size of the last refund.
            $table->unsignedInteger('points_restored')->default(0)->after('points_consumed');
            $table->unsignedInteger('amount_reversed_pence')->default(0)->after('amount_pence');
        });

        // Signed, and this is the half that actually matters. The CHECK below
        // is the visible guard, but the column was unsignedInteger: on MySQL a
        // negative release is rejected by the COLUMN TYPE before any CHECK is
        // consulted, with "Out of range value for column 'points'". SQLite does
        // not enforce unsigned at all, so a suite that runs on SQLite passes
        // either way and only production finds out. Both have to change.
        Schema::table('loyalty_lot_allocations', function (Blueprint $table): void {
            $table->integer('points')->change();
        });

        if (DB::getDriverName() === 'mysql') {
            // points <> 0 rather than points > 0: a release is negative, and an
            // allocation of nothing is still meaningless.
            DB::statement('ALTER TABLE loyalty_lot_allocations DROP CHECK ck_alloc_points');
            DB::statement(
                'ALTER TABLE loyalty_lot_allocations
                 ADD CONSTRAINT ck_alloc_points CHECK (points <> 0)'
            );

            DB::statement(
                'ALTER TABLE loyalty_redemptions
                 ADD CONSTRAINT ck_redemption_restored
                 CHECK (points_restored <= points_consumed)'
            );
            DB::statement(
                'ALTER TABLE loyalty_redemptions
                 ADD CONSTRAINT ck_redemption_reversed_value
                 CHECK (amount_reversed_pence <= amount_pence)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE loyalty_redemptions DROP CHECK ck_redemption_reversed_value');
            DB::statement('ALTER TABLE loyalty_redemptions DROP CHECK ck_redemption_restored');
            DB::statement('ALTER TABLE loyalty_lot_allocations DROP CHECK ck_alloc_points');
            DB::statement(
                'ALTER TABLE loyalty_lot_allocations
                 ADD CONSTRAINT ck_alloc_points CHECK (points > 0)'
            );
        }

        Schema::table('loyalty_lot_allocations', function (Blueprint $table): void {
            $table->unsignedInteger('points')->change();
        });

        Schema::table('loyalty_redemptions', function (Blueprint $table): void {
            $table->dropColumn(['points_restored', 'amount_reversed_pence']);
        });
    }
};
