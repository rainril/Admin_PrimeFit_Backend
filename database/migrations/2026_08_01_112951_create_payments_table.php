<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->onDelete('set null');
            $table->date('date');
            $table->integer('amount');
            $table->string('method');
            $table->enum('status', ['Paid', 'Pending', 'Failed'])->default('Paid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};