<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable `source_id` column to `payments` so the PayMongo
 * billing flow can match an incoming webhook (source.chargeable /
 * payment.paid / payment.failed) back to the local payments row it
 * created. No existing column fits this — `method` holds the channel
 * ("gcash"/"paymaya") and `membership_id` is an FK integer — so a new
 * column is required. This is purely additive; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('source_id')->nullable()->after('method')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['source_id']);
            $table->dropColumn('source_id');
        });
    }
};
