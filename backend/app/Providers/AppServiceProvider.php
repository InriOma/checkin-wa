<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Attendance\Events\AttendanceGeofenceRejected;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Attendance\Policies\AttendancePolicy;
use App\Domains\Branches\Models\Branch;
use App\Domains\Branches\Policies\BranchPolicy;
use App\Domains\Employees\Models\Employee;
use App\Domains\Employees\Policies\EmployeePolicy;
use App\Domains\Incidents\Listeners\CreateGeofenceIncidentListener;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\Justification;
use App\Domains\Incidents\Policies\IncidentPolicy;
use App\Domains\Incidents\Policies\JustificationPolicy;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Schedules\Policies\SchedulePolicy;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Policies\TenantPolicy;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Policies\WhatsAppChannelPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(WhatsAppChannel::class, WhatsAppChannelPolicy::class);
        Gate::policy(AttendanceRecord::class, AttendancePolicy::class);
        Gate::policy(Incident::class, IncidentPolicy::class);
        Gate::policy(Justification::class, JustificationPolicy::class);

        Event::listen(AttendanceGeofenceRejected::class, CreateGeofenceIncidentListener::class);

        Gate::define('viewApiDocs', fn (?User $user = null): bool => app()->environment(['local', 'testing']) || ($user?->is_platform_admin ?? false));
    }
}
