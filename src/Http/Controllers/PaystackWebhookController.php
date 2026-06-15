<?php

declare(strict_types=1);

namespace Laracaise\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laracaise\Billing\Facades\Billing;
use RuntimeException;

final class PaystackWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? null;

        if (! is_string($event) || ! is_array($data)) {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        if (! in_array($event, ['charge.success', 'charge.failed'], true)) {
            return response()->json(['message' => 'Ignored.']);
        }

        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return response()->json(['message' => 'Missing reference.'], 422);
        }

        Billing::driver('paystack')->verifyTransaction($reference);

        return response()->json(['message' => 'Processed.']);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = config('laracaise-billing.drivers.paystack.webhook_secret')
            ?: config('laracaise-billing.drivers.paystack.secret_key');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Paystack webhook secret is not configured.');
        }

        $signature = $request->header('X-Paystack-Signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
