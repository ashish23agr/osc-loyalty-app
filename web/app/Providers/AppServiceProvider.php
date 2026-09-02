<?php

namespace App\Providers;

use App\Domain\Events\EventBus;
use App\Domain\Events\MemberEvents;
use App\Domain\Events\NullEventBus;
use App\Domain\Identity\SessionTokenVerifier;
use App\Domain\Identity\StaffRoleResolver;
use App\Domain\Loyalty\AdjustmentService;
use App\Domain\Loyalty\BalanceCalculator;
use App\Domain\Loyalty\BirthdaySweep;
use App\Domain\Loyalty\EarningCalculator;
use App\Domain\Loyalty\ExpiryOutlook;
use App\Domain\Loyalty\ExpirySweep;
use App\Domain\Loyalty\LastSpendResolver;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\LotAllocator;
use App\Domain\Loyalty\MaturitySweep;
use App\Domain\Loyalty\OrderEarningService;
use App\Domain\Loyalty\OrderReversalService;
use App\Domain\Loyalty\ProgrammeSummary;
use App\Domain\Loyalty\QualifyingValueResolver;
use App\Domain\Loyalty\RefundReversalService;
use App\Domain\Loyalty\SegmentSweep;
use App\Domain\Orders\AdminApiOrderSource;
use App\Domain\Orders\OrderSource;
use App\Domain\Redemption\AdminApiDiscountCodeWriter;
use App\Domain\Redemption\AdminApiMetafieldWriter;
use App\Domain\Redemption\AdminApiProductCatalogue;
use App\Domain\Redemption\DiscountCodeGateway;
use App\Domain\Redemption\DiscountCodeWriter;
use App\Domain\Redemption\DiscountFunctionGateway;
use App\Domain\Redemption\ExclusionSync;
use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Redemption\PosCartDiscountGateway;
use App\Domain\Redemption\ProductCatalogue;
use App\Domain\Redemption\QuoteExpirySweep;
use App\Domain\Redemption\RedemptionGateway;
use App\Domain\Redemption\RedemptionService;
use App\Domain\Rules\RulesVersionRepository;
use App\Http\Presenters\MemberPresenter;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\JobAuditor;
use App\Support\Audit\RequestContext;
use App\Support\Webhooks\WebhookDelivery;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singletons so the resolved rule version is looked up once per request
        // rather than per call site, and so the repository cache invalidation on
        // save is seen by everything downstream of it in the same request.
        $this->app->singleton(RulesVersionRepository::class);
        $this->app->singleton(LotAllocator::class);
        $this->app->singleton(BalanceCalculator::class);
        $this->app->singleton(LedgerService::class);
        $this->app->singleton(ExpiryOutlook::class);
        $this->app->singleton(ProgrammeSummary::class);
        $this->app->singleton(AdjustmentService::class);

        // Presenters hold no state, but they depend on the rules repository and
        // are resolved once per row otherwise.
        $this->app->singleton(MemberPresenter::class);

        // RequestContext must be a singleton: the role middleware populates it
        // and the audit logger reads it, which is what lets any service write a
        // correctly attributed entry without an actor being threaded through
        // every call site.
        $this->app->singleton(RequestContext::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(SessionTokenVerifier::class);
        $this->app->singleton(StaffRoleResolver::class);
        $this->app->singleton(JobAuditor::class);

        // The earning side, and the sweeps that run on a schedule.
        $this->app->singleton(QualifyingValueResolver::class);
        $this->app->singleton(EarningCalculator::class);
        $this->app->singleton(OrderEarningService::class);
        $this->app->singleton(OrderReversalService::class);
        $this->app->singleton(RefundReversalService::class);
        $this->app->singleton(LastSpendResolver::class);
        $this->app->singleton(MaturitySweep::class);
        $this->app->singleton(ExpirySweep::class);
        $this->app->singleton(BirthdaySweep::class);
        $this->app->singleton(SegmentSweep::class);

        // M3/M8: the webhook payload is a trigger, not the arithmetic input, so
        // every figure the ledger acts on is re-read through the Admin API. The
        // binding is an interface because the replay command and the suite
        // supply orders from elsewhere, not because the scope is in doubt.
        $this->app->singleton(OrderSource::class, AdminApiOrderSource::class);

        // Sprint 3. **This binding is the online redemption mechanism, and it is
        // the only place it is chosen.** Resolve `RedemptionGateway` to mean
        // "however this shop redeems online"; the POS tile resolves
        // `PosCartDiscountGateway` explicitly because the till is a different
        // mechanism, not a configurable one.
        //
        // D5 revised 2 Sep 2026: the code path ships, because Shopify refuses a
        // function from a custom app below Plus and the live store is on Grow.
        // Keeping the mechanism behind the interface is what made that a
        // one-line change here rather than a rewrite — swap this back to
        // DiscountFunctionGateway and the function path returns intact.
        $this->app->singleton(RedemptionGateway::class, DiscountCodeGateway::class);

        $this->app->singleton(DiscountCodeWriter::class, AdminApiDiscountCodeWriter::class);
        $this->app->singleton(DiscountCodeGateway::class);

        $this->app->singleton(MetafieldWriter::class, AdminApiMetafieldWriter::class);

        // Kept, not retired: the Plus-and-above implementation, proven by the V4
        // and V6a spikes, and still exercised by its own tests.
        $this->app->singleton(DiscountFunctionGateway::class);
        $this->app->singleton(RedemptionService::class);
        $this->app->singleton(ProductCatalogue::class, AdminApiProductCatalogue::class);
        $this->app->singleton(ExclusionSync::class);
        $this->app->singleton(PosCartDiscountGateway::class);
        $this->app->singleton(QuoteExpirySweep::class);

        // Which webhook delivery is being handled, for handlers the Shopify
        // library calls without the request headers.
        $this->app->singleton(WebhookDelivery::class);

        // M11 is Sprint 4. The call sites emit today through a driver that logs
        // and drops, so Sprint 4 binds a real driver here and changes nothing
        // else. Finding the call sites again later is the expensive part.
        $this->app->singleton(NullEventBus::class);
        $this->app->singleton(MemberEvents::class);
        $this->app->singleton(EventBus::class, fn ($app) => $app->make(NullEventBus::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
