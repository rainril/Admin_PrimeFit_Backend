<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merch_sales', function (Blueprint $table) {
            $table->id();

            // ASSUMPTION: your merch/inventory items table is called
            // 'merch_items' with an auto-increment 'id'. If your actual
            // table/model has a different name (e.g. 'inventory_items',
            // 'products'), rename this column's foreign key target below.
            $table->unsignedBigInteger('item_id');

            // Snapshots — kept even if the item's name/price changes later,
            // so historical sales records stay accurate.
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_amount', 10, 2);

            $table->string('buyer_name')->nullable();
            $table->string('payment_method')->default('Cash');
            $table->enum('status', ['Pending', 'Completed', 'Voided'])->default('Pending');

            // ASSUMPTION: you have an 'admins' table (same as
            // AttendanceLog::handled_by_admin_id in your existing schema).
            $table->unsignedBigInteger('recorded_by_admin_id')->nullable();

            $table->date('date');
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merch_sales');
    }
};
