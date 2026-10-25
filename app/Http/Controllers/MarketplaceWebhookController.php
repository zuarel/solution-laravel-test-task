<?php

namespace App\Http\Controllers;

use App\Actions\EnqueueWebhookEvent;
use App\Http\Requests\MarketplaceWebhookRequest;
use App\Models\WebhookEvent;
use App\Support\DateTimeFormat;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MarketplaceWebhookController extends Controller
{
    public function __invoke(MarketplaceWebhookRequest $request, EnqueueWebhookEvent $enqueue): Response
    {
        $event = $request->validated();

        DB::transaction(function () use ($request, $event, $enqueue): void {
            $eventId = DB::table('webhook_events')->insertOrIgnoreReturning(
                [
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'raw_body' => $request->getContent(),
                    'status' => WebhookEvent::STATUS_PENDING,
                    'received_at' => now()->format(DateTimeFormat::DATABASE),
                ],
                returning: ['id'],
                uniqueBy: ['event_id'],
            )->first()?->id;

            if ($eventId !== null) {
                $enqueue->handle((int) $eventId);
            }
        }, attempts: 3);

        return response('OK', Response::HTTP_OK);
    }
}
