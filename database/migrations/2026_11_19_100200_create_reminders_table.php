<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_to_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('sent_at');

            $table->index(['lead_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
