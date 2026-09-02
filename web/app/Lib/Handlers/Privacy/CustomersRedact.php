<?php

declare(strict_types=1);

namespace App\Lib\Handlers\Privacy;

use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;

/**
 * Mandatory GDPR compliance webhook. Fill in real handling before submitting
 * the app to the Shopify App Store.
 */
class CustomersRedact implements Handler
{
    public function handle(string $topic, string $shop, array $body): void
    {
        Log::info("GDPR webhook $topic received for $shop", ['payload' => $body]);
    }
}
