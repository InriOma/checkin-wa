<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Employees\Models\Employee;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\Justification;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IncidentReviewService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function reviewIncident(
        Incident $incident,
        IncidentStatus $targetStatus,
        User $reviewer,
        ?string $note = null,
    ): Incident {
        if (! in_array($targetStatus, [IncidentStatus::Approved, IncidentStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid review decision.']]);
        }

        return DB::transaction(function () use ($incident, $targetStatus, $reviewer, $note): Incident {
            $oldValues = $incident->only(['status', 'justification_note', 'reviewed_by_user_id', 'reviewed_at']);

            $incident->update([
                'status' => $targetStatus,
                'justification_note' => $note ?? $incident->justification_note,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ]);

            $this->auditLogger->log(
                "incident.{$targetStatus->value}",
                $incident,
                $reviewer,
                $oldValues,
                $incident->only(['status', 'justification_note', 'reviewed_by_user_id', 'reviewed_at']),
            );

            return $incident->refresh()->load(['employee', 'branch', 'reviewer']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createJustification(array $data, User $actor): Justification
    {
        $tenantId = $this->tenantContext->requireId();

        $employeeExists = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $data['employee_id'])
            ->exists();

        if (! $employeeExists) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee does not belong to the active tenant.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $data, $actor): Justification {
            $justification = Justification::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'employee_id' => (int) $data['employee_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'reason_type' => $data['reason_type'],
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? IncidentStatus::Pending->value,
                'reviewed_by_user_id' => ($data['status'] ?? null) === IncidentStatus::Approved->value ? $actor->id : null,
                'reviewed_at' => ($data['status'] ?? null) === IncidentStatus::Approved->value ? now()->utc() : null,
            ]);

            $this->auditLogger->log('justification.created', $justification, $actor, null, $justification->toArray());

            return $justification->load('employee');
        });
    }

    public function reviewJustification(
        Justification $justification,
        IncidentStatus $targetStatus,
        User $reviewer,
        ?string $notes = null,
    ): Justification {
        return DB::transaction(function () use ($justification, $targetStatus, $reviewer, $notes): Justification {
            $oldValues = $justification->only(['status', 'notes', 'reviewed_by_user_id', 'reviewed_at']);

            $justification->update([
                'status' => $targetStatus,
                'notes' => $notes ?? $justification->notes,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ]);

            $this->auditLogger->log(
                "justification.{$targetStatus->value}",
                $justification,
                $reviewer,
                $oldValues,
                $justification->only(['status', 'notes', 'reviewed_by_user_id', 'reviewed_at']),
            );

            return $justification->refresh()->load(['employee', 'reviewer']);
        });
    }
}
