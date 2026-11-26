<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Holds the active tenant for the current HTTP request or queued job.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->syncPostgresSessionSetting();
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function requireId(): int
    {
        if ($this->tenant === null) {
            throw new RuntimeException('No active tenant in TenantContext.');
        }

        return $this->tenant->id;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->syncPostgresSessionSetting();
    }

    /**
     * Execute a callback within the scope of a specific tenant and restore prior state afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAsTenant(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->setTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->setTenant($previous);
        }
    }

    /**
     * Synchronize PostgreSQL session setting when inside an active transaction for RLS defense-in-depth.
     */
    public function syncPostgresSessionSetting(): void
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'pgsql') {
                return;
            }

            $value = $this->tenant?->id ? (string) $this->tenant->id : '';
            $isLocal = $connection->transactionLevel() > 0;
            $connection->statement('SELECT set_config(?, ?, ?)', [
                'app.current_tenant_id',
                $value,
                $isLocal,
            ]);
        } catch (\Throwable) {
            // Connection may not be booted yet during early container resolution or unit tests.
        }
    }
}
