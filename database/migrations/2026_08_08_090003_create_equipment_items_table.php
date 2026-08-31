<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->string('barcode')->unique();     // e.g. "EQ-001-TRD"
            $table->string('name');
            $table->string('category');               // e.g. "Cardio", "Strength"
            $table->unsignedInteger('qty')->default(0);
            $table->enum('status', ['Available', 'Maintenance', 'Damaged'])->default('Available');
            $table->string('location')->nullable();
            $table->date('next_maintenance')->nullable();
            $table->text('description')->nullable();

            // Optional: only used if you later switch to backend-hosted
            // images instead of the local-asset mapping in Flutter.
            $table->string('image_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_items');
    }
};
