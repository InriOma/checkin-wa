<?php

declare(strict_types=1);

namespace App\Domains\Geolocation\Services;

use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Employees\Models\EmployeeBranchAssignment;
use App\Domains\Geolocation\Data\GeofenceEvaluationResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class GeofenceEvaluator
{
    public function __construct(
        private readonly HaversineDistanceCalculator $distanceCalculator,
    ) {}

    public function evaluate(
        Employee $employee,
        float $latitude,
        float $longitude,
        CarbonInterface $atUtc,
    ): GeofenceEvaluationResult {
        $this->distanceCalculator->validateCoordinates($latitude, $longitude);

        $authorizedBranches = $this->resolveAuthorizedBranches($employee, $atUtc);
        if ($authorizedBranches->isEmpty()) {
            return new GeofenceEvaluationResult(
                passed: false,
                branch: null,
                distanceMeters: null,
                appliedRadiusMeters: null,
                reason: 'no_authorized_branches',
            );
        }

        $closestPassing = null;
        $closestPassingDistance = null;
        $closestOverall = null;
        $closestOverallDistance = null;

        /** @var Branch $branch */
        foreach ($authorizedBranches as $branch) {
            $distance = $this->distanceCalculator->calculateMeters(
                $latitude,
                $longitude,
                (float) $branch->latitude,
                (float) $branch->longitude,
            );

            if ($closestOverallDistance === null || $distance < $closestOverallDistance) {
                $closestOverall = $branch;
                $closestOverallDistance = $distance;
            }

            if ($distance <= (float) $branch->geofence_radius_meters) {
                if ($closestPassingDistance === null || $distance < $closestPassingDistance) {
                    $closestPassing = $branch;
                    $closestPassingDistance = $distance;
                }
            }
        }

        if ($closestPassing !== null && $closestPassingDistance !== null) {
            return new GeofenceEvaluationResult(
                passed: true,
                branch: $closestPassing,
                distanceMeters: $closestPassingDistance,
                appliedRadiusMeters: (int) $closestPassing->geofence_radius_meters,
                reason: 'ok',
            );
        }

        return new GeofenceEvaluationResult(
            passed: false,
            branch: $closestOverall,
            distanceMeters: $closestOverallDistance,
            appliedRadiusMeters: $closestOverall !== null ? (int) $closestOverall->geofence_radius_meters : null,
            reason: 'outside_geofence',
        );
    }

    /**
     * @return Collection<int, Branch>
     */
    public function resolveAuthorizedBranches(Employee $employee, CarbonInterface $atUtc): Collection
    {
        $assignments = EmployeeBranchAssignment::withoutGlobalScopes()
            ->with('branch')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->get()
            ->filter(function (EmployeeBranchAssignment $assignment) use ($atUtc): bool {
                $branch = $assignment->branch;
                if ($branch === null || ! $branch->isActive()) {
                    return false;
                }

                $localDate = $atUtc->copy()->setTimezone($branch->timezone)->toDateString();

                if ($assignment->effective_from->toDateString() > $localDate) {
                    return false;
                }

                if ($assignment->effective_until !== null && $assignment->effective_until->toDateString() < $localDate) {
                    return false;
                }

                return true;
            })
            ->map(fn (EmployeeBranchAssignment $a): ?Branch => $a->branch)
            ->filter()
            ->unique('id')
            ->values();

        if ($assignments->isNotEmpty()) {
            return $assignments;
        }

        if ($employee->default_branch_id !== null) {
            $defaultBranch = Branch::withoutGlobalScopes()
                ->where('tenant_id', $employee->tenant_id)
                ->where('id', $employee->default_branch_id)
                ->where('status', 'active')
                ->first();

            if ($defaultBranch !== null) {
                return collect([$defaultBranch]);
            }
        }

        return collect();
    }
}
