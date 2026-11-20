<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30); // App\Enums\LeadSource
            $table->string('external_id')->nullable(); // leadgen_id / first message id
            // Chat sender id (Messenger PSID, IG-scoped id, WhatsApp wa_id): needed to reply and to dedupe chats without a phone.
            $table->string('contact_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('phone', 20)->nullable(); // normalized +91XXXXXXXXXX
            $table->string('email')->nullable();
            $table->string('city', 100)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status', 20)->default('new'); // App\Enums\LeadStatus
            $table->string('campaign_name')->nullable();
            $table->string('ad_name')->nullable();
            $table->string('form_name')->nullable();
            $table->string('agency_name')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index(); // inbox sort; bumped by new messages
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['phone', 'created_at']); // dedupe lookups
            $table->index(['branch_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
