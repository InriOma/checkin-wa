<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Employees\Models\Employee;
use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EmployeeWhatsAppLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeWhatsAppController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('view', $employee);

        $links = $employee->whatsappLinks()->with('contact')->get();

        return EmployeeWhatsAppLinkResource::collection($links);
    }

    public function destroy(Request $request, Employee $employee, EmployeeWhatsAppContact $link): JsonResponse
    {
        Gate::authorize('update', $employee);
        abort_if($link->employee_id !== $employee->id, 404);

        $link->delete();
        $this->auditLogger->log('whatsapp.contact_unlinked', $employee, $request->user(), null, [
            'link_id' => $link->id,
        ], 'user', $request);

        return response()->json(['message' => 'WhatsApp link removed.']);
    }
}
