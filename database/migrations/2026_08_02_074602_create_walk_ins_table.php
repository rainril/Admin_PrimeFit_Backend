<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walk_ins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('date');
            $table->string('check_in')->nullable();
            $table->string('check_out')->nullable();
            $table->integer('amount')->default(0);
            $table->string('method')->default('Cash');
            $table->enum('status', ['Paid', 'Pending', 'Failed'])->default('Paid');
            $table->foreignId('handled_by_admin_id')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_ins');
    }
};