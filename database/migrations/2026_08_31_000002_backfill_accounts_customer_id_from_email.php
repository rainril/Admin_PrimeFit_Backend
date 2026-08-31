<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: link existing `accounts` rows to a `customers` row
 * by matching email, so the PayMongo billing flow can resolve the paying
 * customer from the authenticated account.
 *
 * Only UNAMBIGUOUS matches are written:
 *   - the account is a member account (accounts.role = 'customer'), and
 *   - the account's customer_id is still NULL, and
 *   - exactly one customer has that (non-empty) email.
 * Accounts whose email matches 0 or >1 customers are left NULL and must
 * be linked manually.
 *
 * down() is intentionally a no-op -- there's no safe way to tell a
 * backfilled link from one set deliberately afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE accounts a
            JOIN (
                SELECT c.email, MIN(c.id) AS customer_id
                FROM customers c
                WHERE c.email IS NOT NULL AND c.email <> ''
                GROUP BY c.email
                HAVING COUNT(*) = 1
            ) m ON m.email = a.email
            SET a.customer_id = m.customer_id
            WHERE a.customer_id IS NULL
              AND a.role = 'customer'
        SQL);
    }

    public function down(): void
    {
        // no-op: cannot distinguish a backfilled link from a manual one.
    }
};
