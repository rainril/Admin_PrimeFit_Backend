<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo billing flow para sa PrimeFit membership payments.
 *
 * Daloy (GCash / Maya via PayMongo Sources API):
 *   1. createPayment()           -> gumagawa ng PayMongo "source" + local
 *                                   payments row (status = "Pending"),
 *                                   ibinabalik ang checkout_url.
 *   2. handleSourceChargeable()  -> tinatawag ng webhook kapag inapprove
 *                                   na ng customer sa GCash/Maya app --
 *                                   dito ginagawa ang aktwal na charge.
 *   3. markPaymentPaid()         -> "payment.paid" -- status = "Paid" +
 *                                   ina-activate ang membership.
 *   4. markPaymentFailed()       -> "payment.failed" -- status = "Failed".
 *
 * Ang PayMongo source id ("src_...") ay iniimbak sa payments.source_id
 * (idinagdag ng 2026_08_31_000000_add_source_id_to_payments_table).
 */
class PaymentService
{
    private const BASE_URL = 'https://api.paymongo.com/v1';

    /**
     * Gumawa ng bagong GCash/Maya checkout para sa isang plan.
     *
     * @param  string  $method  "gcash" o "paymaya"
     * @return string  ang PayMongo checkout_url na bubuksan ng Flutter app
     */
    public static function createPayment(int $customerId, int $planId, string $method): string
    {
        $method = strtolower(trim($method));
        $type = match ($method) {
            'gcash' => 'gcash',
            'paymaya', 'maya' => 'paymaya',
            default => throw new \InvalidArgumentException("Unsupported payment method: {$method}"),
        };

        $plan = Plan::findOrFail($planId);
        $amountCentavos = (int) round($plan->price * 100); // PayMongo = centavos

        // Isang "corresponding" membership row na maha-hook sa payment.
        // firstOrCreate: hindi nito binabago ang umiiral nang membership
        // (baka may aktibong plan pa ang customer habang nagre-renew) --
        // ina-activate lang ito sa markPaymentPaid() kapag bayad na.
        $membership = Membership::firstOrCreate(
            ['customer_id' => $customerId, 'plan_id' => $plan->id],
            [
                'start_date' => now()->toDateString(),
                'next_renewal_date' => null,
                'status' => 'expired', // placeholder hanggang ma-confirm ang bayad
            ]
        );

        $redirectBase = rtrim(config('app.frontend_url', config('app.url')), '/');

        $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')
            ->acceptJson()
            ->post(self::BASE_URL . '/sources', [
                'data' => [
                    'attributes' => [
                        'amount' => $amountCentavos,
                        'currency' => 'PHP',
                        'type' => $type,
                        'redirect' => [
                            'success' => $redirectBase . '/payments/return?status=success',
                            'failed' => $redirectBase . '/payments/return?status=failed',
                        ],
                    ],
                ],
            ]);

        $response->throw();

        $source = $response->json('data');
        $sourceId = $source['id'] ?? null;
        $checkoutUrl = $source['attributes']['redirect']['checkout_url'] ?? null;

        if (!$sourceId || !$checkoutUrl) {
            Log::error('PayMongo source response missing id/checkout_url', ['body' => $response->json()]);
            throw new \RuntimeException('PayMongo did not return a checkout URL.');
        }

        Payment::create([
            'customer_id' => $customerId,
            'membership_id' => $membership->id,
            'date' => now()->toDateString(),
            'amount' => $plan->price, // pesos, katugma ng ibang payments rows
            'method' => $method,
            'status' => 'Pending',
            'source_id' => $sourceId,
        ]);

        return $checkoutUrl;
    }

    /**
     * "source.chargeable" -- inapprove na ng customer sa GCash/Maya app,
     * kaya kailangan nang i-charge ang source para makuha ang pera.
     *
     * @param  array  $payload  ang source resource mula sa webhook
     *                          ($request->input('data.attributes.data'))
     */
    public static function handleSourceChargeable(array $payload): void
    {
        $sourceId = $payload['id'] ?? null;
        $amount = $payload['attributes']['amount'] ?? null;

        if (!$sourceId || $amount === null) {
            Log::warning('source.chargeable webhook missing id/amount', ['payload' => $payload]);
            return;
        }

        $payment = Payment::where('source_id', $sourceId)->latest('id')->first();

        $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')
            ->acceptJson()
            ->post(self::BASE_URL . '/payments', [
                'data' => [
                    'attributes' => [
                        'amount' => (int) $amount,
                        'currency' => $payload['attributes']['currency'] ?? 'PHP',
                        'description' => 'PrimeFit membership'
                            . ($payment ? " (payment #{$payment->id})" : ''),
                        'source' => [
                            'id' => $sourceId,
                            'type' => 'source',
                        ],
                    ],
                ],
            ]);

        // Huwag mag-throw: kailangan pa ring sumagot ng 200 ang webhook.
        if ($response->failed()) {
            Log::error('PayMongo charge creation failed', [
                'source_id' => $sourceId,
                'body' => $response->json(),
            ]);
        }
    }

    /**
     * "payment.paid" -- kumpirmado nang bayad.
     * I-mark na "Paid" ang payments row at i-activate ang membership,
     * na ang next_renewal_date ay now() + plan->duration_months.
     */
    public static function markPaymentPaid(string $sourceId): void
    {
        $payment = Payment::where('source_id', $sourceId)->latest('id')->first();

        if (!$payment) {
            Log::warning('payment.paid: walang katugmang payments row', ['source_id' => $sourceId]);
            return;
        }

        $payment->update(['status' => 'Paid']);

        $membership = $payment->membership;

        if (!$membership) {
            Log::warning('payment.paid: payment #' . $payment->id . ' has no linked membership', [
                'source_id' => $sourceId,
            ]);
            return;
        }

        $plan = $membership->plan ?? Plan::find($membership->plan_id);
        $months = $plan ? (int) $plan->duration_months : 1;

        // Mirror ng expire-then-activate logic sa SyncController: iisa lang
        // ang totoong aktibong membership ng customer sa anumang oras.
        Membership::where('customer_id', $payment->customer_id)
            ->where('id', '!=', $membership->id)
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        $membership->update([
            'next_renewal_date' => now()->addMonths($months)->toDateString(),
            'status' => 'active',
        ]);
    }

    /**
     * "payment.failed" -- i-mark na "Failed" ang payments row.
     */
    public static function markPaymentFailed(string $sourceId): void
    {
        $payment = Payment::where('source_id', $sourceId)->latest('id')->first();

        if ($payment) {
            $payment->update(['status' => 'Failed']);
        } else {
            Log::warning('payment.failed: walang katugmang payments row', ['source_id' => $sourceId]);
        }
    }
}
