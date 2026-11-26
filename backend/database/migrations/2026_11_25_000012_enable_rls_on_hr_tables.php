<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $tenantTables = [
        'incidents',
        'justifications',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    current_setting('app.bypass_rls', true) = 'on'
                    OR tenant_id = nullif(current_setting('app.current_tenant_id', true), '')::bigint
                )
                WITH CHECK (
                    current_setting('app.bypass_rls', true) = 'on'
                    OR tenant_id = nullif(current_setting('app.current_tenant_id', true), '')::bigint
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
