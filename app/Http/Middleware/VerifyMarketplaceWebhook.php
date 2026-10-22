<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMarketplaceWebhook
{
    private const CONTENT_TYPE_CHARSET_PATTERN = '/;\s*charset\s*=\s*"?([^;"\s]+)/i';

    private const TIMESTAMP_PATTERN = '/\A(?:0|[1-9][0-9]*)\z/';

    private const SIGNATURE_PATTERN = '/\Asha256=[a-f0-9]{64}\z/';

    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $this->validateContentType($request);
        $this->validateHeaders($request);
        $this->validateTimestamp($request);
        $this->verifySignature($request);

        return $next($request);
    }

    private function validateContentType(Request $request): void
    {
        $contentType = $request->header('Content-Type', '');
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));

        if ($mediaType !== 'application/json') {
            abort(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Expected application/json.');
        }

        $hasCharset = preg_match(self::CONTENT_TYPE_CHARSET_PATTERN, $contentType, $matches);

        if ($hasCharset && strcasecmp($matches[1], 'utf-8') !== 0) {
            abort(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Expected UTF-8 encoding.');
        }
    }

    private function validateHeaders(Request $request): void
    {
        $eventId = $request->header('X-Webhook-Id');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $signature = $request->header('X-Webhook-Signature');

        if (!is_string($eventId) || trim($eventId) === '') {
            abort(Response::HTTP_BAD_REQUEST, 'Missing X-Webhook-Id.');
        }

        if (!is_string($timestamp)
            || !preg_match(self::TIMESTAMP_PATTERN, $timestamp)
            || filter_var($timestamp, FILTER_VALIDATE_INT) === false) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid X-Webhook-Timestamp.');
        }

        if (!is_string($signature) || !preg_match(self::SIGNATURE_PATTERN, $signature)) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid X-Webhook-Signature.');
        }
    }

    private function validateTimestamp(Request $request): void
    {
        $timestamp = (int)$request->header('X-Webhook-Timestamp');
        $difference = abs(now()->getTimestamp() - $timestamp);

        if ($difference > self::TIMESTAMP_TOLERANCE_SECONDS) {
            abort(Response::HTTP_UNAUTHORIZED, 'Webhook timestamp is outside the allowed window.');
        }
    }

    private function verifySignature(Request $request): void
    {
        $secret = config('marketplace.webhook_secret');

        if (!is_string($secret) || $secret === '') {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Webhook verification is not configured.');
        }

        $timestamp = $request->header('X-Webhook-Timestamp');
        $signature = $request->header('X-Webhook-Signature');
        $signedContent = $timestamp . '.' . $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $signedContent, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid webhook signature.');
        }
    }
}
