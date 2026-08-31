<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Membership;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    // POST /api/sync-membership
    // Tinatawag ito ng Member app (membership_api.php) sa sandaling
    // matagumpay na makagawa ng bagong membership doon, para ma-record
    // din agad ito sa primefit_db (Admin/Owner app) — kasama na ang
    // payment record.
    public function syncMembership(Request $request)
    {
        $request->validate([
            'memberId' => 'required|integer',
            'firstName' => 'required|string',
            'lastName' => 'required|string',
            'email' => 'nullable|email',
            'planLabel' => 'required|string',
            'planPrice' => 'required|integer',
            'planMonths' => 'required|integer',
            'startDate' => 'required|date',
            'nextRenewalDate' => 'nullable|date',
            'status' => 'required|string',
            'paymentMethod' => 'nullable|string',
            'passwordHash' => 'nullable|string',
        ]);

        // 1. I-upsert ang customer
        $customer = Customer::updateOrCreate(
            ['qr_code_data' => 'MA-' . $request->memberId],
            [
                'first_name' => $request->firstName,
                'last_name' => $request->lastName,
                'email' => $request->email,
                'member_since' => $request->startDate,
            ]
        );

        // 1b. I-link ang customer sa isang login account (accounts.role =
        //     'customer'). Ito ang ginagamit ng billing flow
        //     (PaymentController@store) para kunin ang customer mula sa
        //     naka-authenticate na account sa halip na sa request body.
        //
        //     Additive lang: hindi hinahawakan ang mga admin/staff account
        //     o account na naka-link na. Ang password_hash(PASSWORD_DEFAULT)
        //     ng PHP member app ay bcrypt din, kaya tugma sa Hash::check().
        //     Kung walang naipasang hash, random secret muna (reset-only).
        if ($request->filled('email')) {
            $account = Account::where('email', $request->email)->first();

            if (!$account) {
                Account::create([
                    'email' => $request->email,
                    'password' => $request->passwordHash ?: Hash::make(Str::random(40)),
                    'role' => 'customer',
                    'customer_id' => $customer->id,
                ]);
            } elseif ($account->role === 'customer' && $account->customer_id === null) {
                $account->update(['customer_id' => $customer->id]);
            }
        }

        // 2. I-upsert ang plan — itugma base sa duration_months + price
        // (mas stable kaysa duration_label, para maiwasan ang duplicate
        // kapag may pagkakaiba sa text mula sa Member app).
        $plan = Plan::firstOrCreate(
            [
                'duration_months' => $request->planMonths,
                'price' => $request->planPrice,
            ],
            [
                'duration_label' => trim($request->planLabel),
                'discount_percent' => 0,
                'features' => 'Unlimited time, Free Coach, Free Drinking Water, Clean Facility & Toilets',
            ]
        );

        // 3. I-expire muna ang lahat ng ibang "active" membership ng
        //    customer na ito (maliban sa plan na ito mismo, kung meron)
        //    -- mirror ng expire-then-create logic sa membership_api.php
        //    (member app side). Kailangan ito dahil updateOrCreate() base
        //    sa customer_id+plan_id ay gumagawa ng BAGONG row kapag
        //    nag-upgrade sa ibang plan, pero hindi na-expire ang luma --
        //    kaya nagkakaroon ng maraming "active" rows kahit isa lang
        //    dapat ang totoong kasalukuyang membership.
        Membership::where('customer_id', $customer->id)
            ->where('plan_id', '!=', $plan->id)
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        // 4. I-upsert ang membership record
        $membership = Membership::updateOrCreate(
            ['customer_id' => $customer->id, 'plan_id' => $plan->id],
            [
                'start_date' => $request->startDate,
                'next_renewal_date' => $request->nextRenewalDate,
                'status' => $request->status,
            ]
        );

        // 4. Gumawa ng payment record para makita agad sa Billing page
        $payment = Payment::create([
            'customer_id' => $customer->id,
            'membership_id' => $membership->id,
            'date' => $request->startDate,
            'amount' => $request->planPrice,
            'method' => $request->paymentMethod ?? 'Cash',
            'status' => 'Paid',
        ]);

        return response()->json([
            'message' => 'Membership and payment synced successfully',
            'customerId' => $customer->id,
            'membershipId' => $membership->id,
            'paymentId' => $payment->id,
        ], 201);
    }
}