<?php

namespace App\Services;

class QrTokenService
{
    /**
     * Ive-verify ang QR token na naka-format: "<MemberID>.<timestamp>.<signature>"
     * Nire-recreate ang parehong HMAC-SHA256 logic gamit ang qr_helper.php sa Member app.
     * Nagbabalik ng MemberID (int) kung valid, o null kung tampered/invalid.
     */
    public static function verify(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$memberId, $timestamp, $signature] = $parts;

        if (!ctype_digit($memberId) || !ctype_digit($timestamp)) {
            return null;
        }

        $expectedPayload = $memberId . '.' . $timestamp;
        $expectedSignature = hash_hmac('sha256', $expectedPayload, env('QR_SECRET_KEY'));

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return (int) $memberId;
    }
}