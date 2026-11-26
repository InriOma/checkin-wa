<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenantContext->requireId();
        abort_unless($request->user()->hasTenantRole($tenantId, TenantRole::adminRoles()), 403);

        $query = AuditLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', (string) $request->string('action'));
        }

        return AuditLogResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 30), 100))
        );
    }
}
