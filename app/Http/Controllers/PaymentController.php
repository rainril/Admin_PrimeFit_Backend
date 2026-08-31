<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo billing endpoints para sa PrimeFit membership payments
 * (GCash / Maya). Hiwalay ito sa App\Http\Controllers\Api\PaymentController
 * na hawak ang admin Billing page (index / send-earnings-email).
 */
class PaymentController extends Controller
{
    /**
     * POST /api/payments  (auth:sanctum)
     * Sinisimulan ang isang GCash/Maya checkout para sa isang plan.
     * Ang customer ay galing sa naka-authenticate na account -- dapat
     * role = 'customer' na may naka-set na accounts.customer_id.
     * Hindi na tinatanggap ang customer_id sa request body.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
            'method' => 'required|string|in:gcash,paymaya,maya',
        ]);

        $user = $request->user();
        $customerId = ($user && $user->role === 'customer') ? $user->customer_id : null;

        if (!$customerId) {
            return response()->json([
                'success' => false,
                'message' => 'This account is not linked to a member profile.',
            ], 403);
        }

        try {
            $checkoutUrl = PaymentService::createPayment(
                (int) $customerId,
                (int) $validated['plan_id'],
                $validated['method'],
            );
        } catch (\Throwable $e) {
            Log::error('createPayment failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Could not start the payment. Please try again.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'checkout_url' => $checkoutUrl,
        ]);
    }

    /**
     * POST /api/payments/webhook
     * Tinatawag ng PayMongo (external), kaya WALA itong auth/CSRF.
     * Laging sumasagot ng 200 basta valid ang signature, ayon sa
     * kinakailangan ng PayMongo (kung hindi, uulit-ulitin nila ito).
     */
    public function webhook(Request $request)
    {
        if (!$this->signatureIsValid($request)) {
            Log::warning('PayMongo webhook: invalid signature');

            return response()->json(['received' => false], 401);
        }

        $type = $request->input('data.attributes.type');
        $resource = $request->input('data.attributes.data', []); // source o payment resource

        try {
            if ($type === 'source.chargeable') {
                PaymentService::handleSourceChargeable($resource);
            } elseif ($type === 'payment.paid') {
                $sourceId = data_get($resource, 'attributes.source.id');
                if ($sourceId) {
                    PaymentService::markPaymentPaid($sourceId);
                }
            } elseif ($type === 'payment.failed') {
                $sourceId = data_get($resource, 'attributes.source.id');
                if ($sourceId) {
                    PaymentService::markPaymentFailed($sourceId);
                }
            }
        } catch (\Throwable $e) {
            // I-log lang -- huwag hayaang bumagsak, kailangan pa ring 200.
            Log::error('PayMongo webhook handler error', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['received' => true], 200);
    }

    /**
     * GET /api/payments/{paymentId}/status
     * Pino-poll ito ng Flutter app habang hinihintay ang webhook.
     */
    public function status(Request $request, $paymentId)
    {
        $payment = Payment::find($paymentId);

        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'payment_id' => (int) $payment->id,
            'status' => $payment->status, // Pending | Paid | Failed
        ]);
    }

    /**
     * GET /api/customers/{customerId}/billing-history
     * Payments + kaugnay na membership at plan para sa isang customer.
     */
    public function history(Request $request, $customerId)
    {
        $rows = DB::table('payments')
            ->leftJoin('memberships', 'payments.membership_id', '=', 'memberships.id')
            ->leftJoin('plans', 'memberships.plan_id', '=', 'plans.id')
            ->where('payments.customer_id', $customerId)
            ->orderByDesc('payments.created_at')
            ->get([
                'payments.id',
                'payments.date',
                'payments.amount',
                'payments.method',
                'payments.status',
                'payments.source_id',
                'payments.created_at',
                'memberships.id as membership_id',
                'memberships.status as membership_status',
                'memberships.start_date as membership_start_date',
                'memberships.next_renewal_date',
                'plans.id as plan_id',
                'plans.duration_label as plan_label',
                'plans.duration_months as plan_months',
                'plans.price as plan_price',
            ]);

        return response()->json([
            'success' => true,
            'customer_id' => (int) $customerId,
            'history' => $rows,
        ]);
    }

    /**
     * I-verify ang "Paymongo-Signature" header:
     *   header = "t=<timestamp>,te=<test sig>,li=<live sig>"
     *   valid  = hmac_sha256("<t>.<raw body>", webhook_secret) === te|li
     * Kung walang naka-set na webhook_secret, papayagan (dev convenience)
     * pero mag-lo-log ng babala.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = config('services.paymongo.webhook_secret');

        if (empty($secret)) {
            Log::warning('PayMongo webhook_secret is not set -- skipping signature check');

            return true;
        }

        $header = $request->header('Paymongo-Signature', '');
        if (!$header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $segment) {
            $kv = explode('=', trim($segment), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        $timestamp = $parts['t'] ?? null;
        $provided = $parts['te'] ?? $parts['li'] ?? null;

        if (!$timestamp || !$provided) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
