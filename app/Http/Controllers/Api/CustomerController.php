<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Membership;
use Illuminate\Http\Request;
use App\Services\QrTokenService;
use App\Services\MemberAccountService;

class CustomerController extends Controller
{
    // GET /api/customers - listahan ng lahat ng members (para sa Test QR Codes / dropdown)
    public function index()
    {
        $customers = Customer::with(['memberships.plan'])->get();

        $result = $customers->map(function ($c) {
            $activeMembership = $c->memberships->sortByDesc('created_at')->first();

            return [
                'id' => $c->qr_code_data,
                'name' => trim($c->first_name . ' ' . $c->last_name),
                'email' => $c->email,
                'plan' => $activeMembership?->plan?->duration_label ?? 'No Plan',
                'subscriptionStatus' => $activeMembership?->status ?? 'expired',
                'expiryDate' => $activeMembership?->next_renewal_date,
            ];
        });

        return response()->json($result);
    }

    // GET /api/customers/{qrCode} - hanapin ang customer base sa QR code (para sa Scan)
    public function findByQr($qrCode)
    {
        $customer = Customer::with(['memberships.plan'])
            ->where('qr_code_data', $qrCode)
            ->first();

        if (!$customer) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        $activeMembership = $customer->memberships->sortByDesc('created_at')->first();

        return response()->json([
            'id' => $customer->qr_code_data,
            'name' => trim($customer->first_name . ' ' . $customer->last_name),
            'email' => $customer->email,
            'plan' => $activeMembership?->plan?->duration_label ?? 'No Plan',
            'subscriptionStatus' => $activeMembership?->status ?? 'expired',
            'expiryDate' => $activeMembership?->next_renewal_date,
        ]);
    }

    // POST /api/customers - gumawa ng bagong member
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'plan' => 'required|string',
            'expiryDate' => 'nullable|date',
        ]);

        $nameParts = explode(' ', $request->name, 2);

        $customer = Customer::create([
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1] ?? '',
            'email' => $request->email,
            'qr_code_data' => 'M' . str_pad(Customer::count() + 1, 3, '0', STR_PAD_LEFT),
            'member_since' => now(),
        ]);

        return response()->json([
            'id' => $customer->qr_code_data,
            'name' => trim($customer->first_name . ' ' . $customer->last_name),
            'email' => $customer->email,
        ], 201);
    }

    // POST /api/verify-qr - ive-verify ang signed QR token, kukunin ang
    // detalye ng member mula sa memberaccount_db, i-sync papunta sa
    // lokal na customers table (sync-on-verify).
    public function verifyQr(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $memberId = QrTokenService::verify($request->token);

        if ($memberId === null) {
            return response()->json(['message' => 'Invalid or tampered QR code.'], 422);
        }

        $details = MemberAccountService::getMemberDetails($memberId);

        if ($details === null) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        // I-upsert papunta sa lokal na customers table para ma-link sa
        // attendance_logs natin.
        $customer = Customer::updateOrCreate(
            ['qr_code_data' => 'MA-' . $details['memberId']],
            [
                'first_name' => $details['firstName'],
                'last_name' => $details['lastName'],
                'email' => $details['email'],
                'member_since' => now(),
            ]
        );

        return response()->json([
            'id' => $customer->qr_code_data,
            'name' => trim($details['firstName'] . ' ' . $details['lastName']),
            'email' => $details['email'],
            'plan' => $details['plan'],
            'subscriptionStatus' => $details['subscriptionStatus'],
            'expiryDate' => $details['expiryDate'],
        ]);
    }

    // GET /api/live-members - real-time na listahan ng members mula
    // sa memberaccount_db, direkta na basa bawat request.
    public function liveMembers()
    {
        return response()->json(MemberAccountService::getAllMembersLive());
    }
}