<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an `accounts` row to a `customers` row. Member accounts
 * (accounts.role = 'customer') carry this so the PayMongo billing
 * flow can resolve the paying customer from the authenticated user
 * instead of trusting a customer_id in the request body.
 *
 * Nullable: admin/staff accounts leave it NULL. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('role')
                ->constrained('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
