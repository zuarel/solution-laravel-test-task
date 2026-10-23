<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->text('marketplace_id')->unique();
            $table->text('number');
            $table->string('status');
            $table->char('currency', 3);
            $table->text('customer_email');
            $table->text('customer_name');
            $table->text('customer_phone')->nullable();
            $table->decimal('delivery_price', 18, 2);
            $table->decimal('discount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->text('shipment_carrier')->nullable();
            $table->text('shipment_tracking_number')->nullable();
            $table->text('shipment_tracking_url')->nullable();
            $table->timestampTz('paid_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('marketplace_created_at', 6);
            $table->timestampTz('marketplace_updated_at', 6);
            $table->timestampsTz(6);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->text('sku');
            $table->text('name');
            $table->integer('quantity');
            $table->decimal('price', 18, 2);
            $table->integer('position');

            $table->unique(['order_id', 'position']);
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_event_id')->constrained('webhook_events')->restrictOnDelete();
            $table->string('status');
            $table->timestampTz('occurred_at', 6);

            $table->index(['order_id', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
