<?php

use App\Http\Controllers\MarketplaceWebhookController;
use App\Http\Middleware\VerifyMarketplaceWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/marketplace', MarketplaceWebhookController::class)
    ->middleware(VerifyMarketplaceWebhook::class);
