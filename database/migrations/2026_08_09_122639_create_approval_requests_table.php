<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'edit' | 'delete'
            $table->string('target_type'); // 'walk_in'
            $table->unsignedBigInteger('target_id');
            $table->json('payload')->nullable(); // new values, for 'edit' only
            $table->json('original')->nullable(); // snapshot of the row, for display in the approvals list
            $table->string('requested_by')->nullable(); // staff's name/email
            $table->string('status')->default('pending'); // 'pending' | 'approved' | 'rejected'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};