<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->date('work_date');
            $table->string('status', 20);
            $table->timestampTz('clock_in_at');
            $table->timestampTz('clock_out_at')->nullable();
            $table->unsignedInteger('total_worked_minutes')->nullable();
            $table->unsignedInteger('total_break_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_departure_minutes')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches');
            $table->foreign(['tenant_id', 'schedule_id'])
                ->references(['tenant_id', 'id'])
                ->on('schedules');
            $table->index(['tenant_id', 'work_date', 'status']);
            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });

        DB::statement("
            CREATE UNIQUE INDEX attendance_one_open_shift_per_employee
            ON attendance_records (tenant_id, employee_id)
            WHERE status IN ('working', 'on_break')
        ");

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('attendance_record_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('event_type', 30);
            $table->string('source', 30);
            $table->foreignId('whatsapp_channel_id')->nullable()->constrained('whatsapp_channels')->nullOnDelete();
            $table->string('wamid', 128)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('event_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->unsignedInteger('geofence_radius_meters')->nullable();
            $table->boolean('geofence_passed')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'attendance_record_id'])
                ->references(['tenant_id', 'id'])
                ->on('attendance_records')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches');
            $table->index(['tenant_id', 'employee_id', 'event_at']);
            $table->index(['tenant_id', 'attendance_record_id']);
        });

        DB::statement('
            CREATE UNIQUE INDEX attendance_events_wamid_type_unique
            ON attendance_events (tenant_id, wamid, event_type)
            WHERE wamid IS NOT NULL
        ');

        Schema::create('breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('attendance_record_id');
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'attendance_record_id'])
                ->references(['tenant_id', 'id'])
                ->on('attendance_records')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'attendance_record_id']);
        });

        DB::statement('
            CREATE UNIQUE INDEX breaks_one_open_break_per_record
            ON breaks (tenant_id, attendance_record_id)
            WHERE ended_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('breaks');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_records');
    }
};
