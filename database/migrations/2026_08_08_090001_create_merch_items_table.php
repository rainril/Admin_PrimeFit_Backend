<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merch_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();           // e.g. "MC-001-TSH"
            $table->string('name');                     // e.g. "PrimeFit T-Shirt (All Sizes)"
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('sold')->default(0);      // updated when a merch sale is confirmed
            $table->decimal('revenue', 10, 2)->default(0);    // updated when a merch sale is confirmed
            $table->string('image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merch_items');
    }
};
