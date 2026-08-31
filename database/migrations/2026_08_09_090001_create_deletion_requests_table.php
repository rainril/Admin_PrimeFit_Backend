<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('item_type', ['equipment', 'merch']);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name'); // snapshot, in case the item is later renamed/deleted
            $table->string('requested_by')->nullable(); // staff name/email at time of request
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_requests');
    }
};
