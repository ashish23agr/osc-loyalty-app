<?php

declare(strict_types=1);

namespace App\Lib\Handlers;

use App\Models\Session;
use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;

class AppUninstalled implements Handler
{
    public function handle(string $topic, string $shop, array $body): void
    {
        Log::info("Webhook $topic received for $shop - deleting stored sessions");

        Session::where('shop', $shop)->delete();
    }
}
