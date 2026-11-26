<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('channel_type', 20);
            $table->string('phone_number_id', 64)->unique();
            $table->string('business_account_id', 64);
            $table->string('display_phone_number', 32);
            $table->text('access_token_encrypted');
            $table->string('status', 30)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['channel_type', 'status']);
            $table->index(['tenant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE whatsapp_channels
                ADD CONSTRAINT whatsapp_channels_type_tenant_check
                CHECK (
                    (channel_type = 'central' AND tenant_id IS NULL)
                    OR (channel_type = 'dedicated' AND tenant_id IS NOT NULL)
                )
            ");
        }

        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164', 20)->unique();
            $table->string('wa_id', 32)->nullable();
            $table->string('profile_name', 150)->nullable();
            $table->timestampsTz();
        });

        Schema::create('employee_whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('whatsapp_contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('linked_via_channel_id')->nullable()->constrained('whatsapp_channels')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->unique(['tenant_id', 'employee_id', 'whatsapp_contact_id'], 'ewc_tenant_emp_contact_unique');
            $table->unique(['tenant_id', 'whatsapp_contact_id'], 'ewc_tenant_contact_unique');
            $table->index(['whatsapp_contact_id', 'verified_at']);
        });

        Schema::create('whatsapp_link_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('whatsapp_contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->foreignId('whatsapp_channel_id')->constrained('whatsapp_channels')->cascadeOnDelete();
            $table->string('code_hash', 128);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])
                ->on('employees')
                ->cascadeOnDelete();
            $table->index(['whatsapp_contact_id', 'whatsapp_channel_id', 'consumed_at'], 'wlo_contact_channel_consumed_idx');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_channel_id')->constrained('whatsapp_channels')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->nullable()->constrained('whatsapp_contacts')->nullOnDelete();
            $table->string('direction', 10);
            $table->string('wamid', 128)->nullable()->unique();
            $table->string('message_type', 30);
            $table->string('delivery_status', 20)->nullable();
            $table->json('payload_redacted');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['whatsapp_channel_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['whatsapp_contact_id', 'created_at']);
        });

        Schema::create('pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->foreignId('whatsapp_channel_id')->constrained('whatsapp_channels')->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->string('action_type', 40);
            $table->json('context')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['whatsapp_channel_id', 'whatsapp_contact_id', 'consumed_at'], 'pa_channel_contact_consumed_idx');
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_actions');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_link_otps');
        Schema::dropIfExists('employee_whatsapp_contacts');
        Schema::dropIfExists('whatsapp_contacts');
        Schema::dropIfExists('whatsapp_channels');
    }
};
