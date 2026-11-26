<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('timezone', 64);
            $table->json('work_days');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('late_tolerance_minutes')->default(10);
            $table->unsignedSmallInteger('absence_threshold_minutes')->default(120);
            $table->unsignedSmallInteger('break_duration_minutes')->default(30);
            $table->unsignedSmallInteger('max_breaks_per_shift')->default(2);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('schedule_id');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'schedule_id'])
                ->references(['tenant_id', 'id'])
                ->on('schedules')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'effective_from'], 'sa_tenant_emp_from_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('schedules');
    }
};
