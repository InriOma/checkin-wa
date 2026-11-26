<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreWhatsAppChannelRequest;
use App\Http\Requests\V1\UpdateWhatsAppChannelRequest;
use App\Http\Resources\V1\WhatsAppChannelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WhatsAppChannelController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WhatsAppChannel::class);

        $user = $request->user();
        $tenantId = $this->tenantContext->id();

        $query = WhatsAppChannel::query()->orderBy('id');
        if (! $user->is_platform_admin) {
            $query->where('channel_type', 'dedicated')
                ->where('tenant_id', $tenantId);
        }

        return WhatsAppChannelResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 20), 100))
        );
    }

    public function store(StoreWhatsAppChannelRequest $request): JsonResponse
    {
        Gate::authorize('create', WhatsAppChannel::class);

        $validated = $request->validated();
        $user = $request->user();
        $tenantId = $this->tenantContext->id();

        if ($validated['channel_type'] === 'central' && ! $user->is_platform_admin) {
            throw ValidationException::withMessages([
                'channel_type' => ['Only platform administrators can register central channels.'],
            ]);
        }

        $resolvedTenantId = null;
        if ($validated['channel_type'] === 'dedicated') {
            $resolvedTenantId = $user->is_platform_admin && isset($validated['tenant_id'])
                ? (int) $validated['tenant_id']
                : $tenantId;

            if ($resolvedTenantId === null) {
                throw ValidationException::withMessages([
                    'tenant_id' => ['A tenant_id is required for dedicated channels.'],
                ]);
            }
        }

        $channel = WhatsAppChannel::query()->create([
            'tenant_id' => $resolvedTenantId,
            'channel_type' => $validated['channel_type'],
            'phone_number_id' => $validated['phone_number_id'],
            'business_account_id' => $validated['business_account_id'],
            'display_phone_number' => $validated['display_phone_number'],
            'access_token_encrypted' => $validated['access_token'],
            'status' => $validated['status'] ?? 'active',
        ]);

        $this->auditLogger->log('whatsapp_channel.created', $channel, $user, null, [
            'channel_type' => $channel->channel_type,
            'phone_number_id' => $channel->phone_number_id,
            'status' => $channel->status,
        ], 'user', $request);

        return (new WhatsAppChannelResource($channel))
            ->response()
            ->setStatusCode(201);
    }

    public function show(WhatsAppChannel $channel): WhatsAppChannelResource
    {
        Gate::authorize('view', $channel);

        return new WhatsAppChannelResource($channel);
    }

    public function update(UpdateWhatsAppChannelRequest $request, WhatsAppChannel $channel): WhatsAppChannelResource
    {
        Gate::authorize('update', $channel);

        $validated = $request->validated();
        $updateData = collect($validated)->except(['access_token'])->all();
        if (isset($validated['access_token'])) {
            $updateData['access_token_encrypted'] = $validated['access_token'];
        }

        $channel->update($updateData);

        $this->auditLogger->log('whatsapp_channel.updated', $channel, $request->user(), null, [
            'status' => $channel->status,
            'token_rotated' => isset($validated['access_token']),
        ], 'user', $request);

        return new WhatsAppChannelResource($channel->refresh());
    }

    public function destroy(Request $request, WhatsAppChannel $channel): JsonResponse
    {
        Gate::authorize('delete', $channel);

        $channel->delete();
        $this->auditLogger->log('whatsapp_channel.deleted', $channel, $request->user(), null, null, 'user', $request);

        return response()->json(['message' => 'WhatsApp channel deleted.']);
    }
}
