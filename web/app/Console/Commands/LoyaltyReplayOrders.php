<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\OrderEarningService;
use App\Domain\Loyalty\OrderReversalService;
use App\Domain\Orders\OrderSource;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use App\Support\Audit\RequestContext;
use Illuminate\Support\Carbon;

/**
 * Reprocess orders whose webhooks were missed, failed, or arrived while the
 * queue was down (M3).
 *
 * The whole design rests on one property: **replaying an order that was already
 * processed correctly changes nothing.** Earning is cumulative — it posts the
 * difference between what an order should have earned and what it already has —
 * and reversals are a cumulative floor. So the safe thing to do when nobody is
 * sure what was missed is to replay a wider range than necessary.
 *
 * That is deliberate. A replay command that had to be aimed precisely would be
 * used late and nervously, which is exactly the wrong instinct when points have
 * stopped moving.
 *
 * Three ways to choose what to replay:
 *
 *   --order=123 --order=456    named orders
 *   --from / --to              every order the ledger already knows about in a window
 *   --failed                   every webhook delivery that failed or was never processed
 */
class LoyaltyReplayOrders extends ShopScopedCommand
{
    protected $signature = 'loyalty:replay-orders
                            {--shop= : Limit to one shop domain}
                            {--order=* : Specific Shopify order ids}
                            {--from= : Replay orders with activity on or after this date}
                            {--to= : Replay orders with activity before this date}
                            {--failed : Replay every failed or unprocessed webhook delivery}
                            {--reversals : Also re-run the refund and cancellation path}
                            {--dry-run : Report what would be replayed and change nothing}';

    protected $description = 'Reprocess orders whose loyalty webhooks were missed or failed';

    public function handle(
        OrderSource $orders,
        OrderEarningService $earning,
        OrderReversalService $reversals,
        RequestContext $context,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        return $this->eachShop(function (string $shop) use (
            $orders, $earning, $reversals, $context, $dryRun
        ): array {
            $context->forJob($shop, $this->jobName());

            $orderIds = $this->orderIdsFor($shop);

            $counts = [
                'orders' => count($orderIds),
                'earned' => 0,
                'points_posted' => 0,
                'reversed' => 0,
                'unchanged' => 0,
                'unreadable' => 0,
                'dry_run' => $dryRun,
            ];

            foreach ($orderIds as $orderId) {
                if ($dryRun) {
                    $this->line('  would replay order '.$orderId);

                    continue;
                }

                $order = $orders->fetch($shop, $orderId);

                if ($order === null) {
                    $counts['unreadable']++;

                    continue;
                }

                $result = $earning->apply($shop, $order);

                if ($result['points_posted'] !== 0) {
                    $counts['earned']++;
                    $counts['points_posted'] += $result['points_posted'];
                } else {
                    $counts['unchanged']++;
                }

                if ($this->option('reversals')) {
                    $reversal = $reversals->apply($shop, $order);

                    if ($reversal['reversed'] + $reversal['restored'] > 0) {
                        $counts['reversed']++;
                    }
                }
            }

            return $counts;
        });
    }

    protected function report(string $shop, array $counts): void
    {
        $this->components->twoColumnDetail(
            $shop,
            $counts['orders'].' order(s) · '.$counts['earned'].' earned ('
            .$counts['points_posted'].' points) · '.$counts['unchanged'].' unchanged'
            .($counts['reversed'] > 0 ? ' · '.$counts['reversed'].' reversed' : '')
            .($counts['unreadable'] > 0 ? ' · '.$counts['unreadable'].' unreadable' : '')
            .($counts['dry_run'] ? ' · DRY RUN, nothing written' : ''),
        );
    }

    /**
     * @return list<int>
     */
    private function orderIdsFor(string $shop): array
    {
        $named = array_map('intval', (array) $this->option('order'));

        if ($named !== []) {
            return array_values(array_unique($named));
        }

        if ($this->option('failed')) {
            return WebhookEvent::query()
                ->where('shop_domain', $shop)
                ->whereIn('state', ['received', 'failed'])
                ->whereNotNull('resource_id')
                ->distinct()
                ->pluck('resource_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        // Everything the ledger already knows about in the window. It cannot
        // find an order that never reached us at all — that is what --order and
        // --failed are for — but it is the right net for "the queue was down
        // and we re-ran the sweeps afterwards".
        $query = LedgerEntry::query()
            ->where('shop_domain', $shop)
            ->whereNotNull('shopify_order_id')
            ->distinct();

        if ($from = $this->option('from')) {
            $query->where('occurred_at', '>=', Carbon::parse($from));
        }

        if ($to = $this->option('to')) {
            $query->where('occurred_at', '<', Carbon::parse($to));
        }

        return $query->pluck('shopify_order_id')->map(fn ($id): int => (int) $id)->all();
    }
}
