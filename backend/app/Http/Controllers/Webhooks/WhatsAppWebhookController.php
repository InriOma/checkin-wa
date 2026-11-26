<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\WhatsApp\Services\WebhookIngestionService;
use App\Domains\WhatsApp\Services\WebhookSignatureValidator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookSignatureValidator $signatureValidator,
        private readonly WebhookIngestionService $ingestionService,
    ) {}

    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if (! $this->signatureValidator->isVerifyTokenValid(
            is_string($mode) ? $mode : null,
            is_string($token) ? $token : null,
        )) {
            return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
        }

        return response((string) $challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        if (! $this->signatureValidator->isValid($rawBody, $signature)) {
            Log::warning('Rejected WhatsApp webhook due to missing or invalid signature.', [
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
            ]);

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON payload.'], 400);
        }

        $stats = $this->ingestionService->ingest($payload);

        return response()->json([
            'status' => 'accepted',
            'stats' => $stats,
        ]);
    }
}
