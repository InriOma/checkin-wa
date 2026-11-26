<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('attendance_record_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('incident_date');
            $table->string('type', 30);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('minutes_affected')->nullable();
            $table->text('justification_note')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'attendance_record_id'])
                ->references(['tenant_id', 'id'])
                ->on('attendance_records')
                ->nullOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches');
            $table->index(['tenant_id', 'incident_date', 'status']);
            $table->index(['tenant_id', 'employee_id', 'incident_date']);
        });

        DB::statement("
            CREATE UNIQUE INDEX incidents_unique_daily_type
            ON incidents (tenant_id, employee_id, incident_date, type)
            WHERE type IN ('late', 'absence', 'early_departure', 'incomplete_shift', 'excess_break')
        ");

        Schema::create('justifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason_type', 30);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'start_date', 'end_date', 'status'], 'justifications_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('justifications');
        Schema::dropIfExists('incidents');
    }
};
