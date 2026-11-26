<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('geofence_radius_meters')->default(100);
            $table->string('timezone', 64);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_latitude_range CHECK (latitude >= -90 AND latitude <= 90)');
            DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_longitude_range CHECK (longitude >= -180 AND longitude <= 180)');
            DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_geofence_radius_min CHECK (geofence_radius_meters >= 10)');
        }

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_code', 32);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('hired_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'employee_code']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'default_branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches');
        });

        Schema::create('employee_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'effective_from'], 'eba_tenant_emp_from_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branch_assignments');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('branches');
    }
};
