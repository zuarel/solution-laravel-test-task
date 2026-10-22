<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceWebhookRequest;
use Illuminate\Http\Response;

class MarketplaceWebhookController extends Controller
{
    public function __invoke(MarketplaceWebhookRequest $request): Response
    {
        return response('OK', Response::HTTP_OK);
    }
}
