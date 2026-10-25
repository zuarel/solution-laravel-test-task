<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->text('event_id')->unique();
            $table->text('type');
            $table->longText('raw_body');
            $table->string('status')->default('pending');
            $table->timestampTz('received_at', 6);
            $table->timestampTz('queued_at', 6)->nullable();
            $table->json('validation_errors')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('checked_at', 6)->nullable();

            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
