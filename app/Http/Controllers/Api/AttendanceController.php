<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Customer;
use App\Services\MemberAccountService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    // GET /api/attendance - listahan ng attendance records
    public function index()
    {
        $logs = AttendanceLog::with('customer')->orderByDesc('created_at')->get();

        $result = $logs->map(function ($log) {
            return [
                'id' => (string) $log->id,
                'memberId' => $log->customer->qr_code_data,
                'name' => trim($log->customer->first_name . ' ' . $log->customer->last_name),
                'email' => $log->customer->email,
                'date' => $log->date,
                'checkIn' => $this->formatTime($log->check_in_time),
                'checkOut' => $this->formatTime($log->check_out_time),
                'status' => $log->status,
            ];
        });

        return response()->json($result);
    }

    // Converts a raw "HH:MM:SS" time column into a friendly 12-hour
    // "h:mm AM/PM" string, matching how Walk-in times are displayed.
    // Returns '--' for null/empty values (e.g. no check-out yet).
    private function formatTime(?string $time): string
    {
        if (empty($time)) {
            return '--';
        }

        try {
            return \Carbon\Carbon::parse($time)->format('g:i A');
        } catch (\Exception $e) {
            return $time;
        }
    }

    // POST /api/attendance - i-record ang check-in (galing sa Scan page)
    // + real-time na ide-deduct ang 1 session credit sa memberaccount_db,
    // dahil doon ang totoong "source of truth" ng SessionCredits.
    public function store(Request $request)
    {
        $request->validate([
            'qrCode' => 'required|string',
            'status' => 'required|string',
            'adminId' => 'nullable|integer',
        ]);

        $customer = Customer::where('qr_code_data', $request->qrCode)->first();

        if (!$customer) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        // I-extract ang totoong MemberID (mula sa memberaccount_db) mula sa
        // qr_code_data, dahil laging naka-format itong "MA-<MemberID>" para
        // sa mga totoong member (galing sa verify-qr / sync-membership flow).
        // Kung hindi "MA-" ang prefix (halimbawa mga lumang test customer na
        // ginawa via manual "Add Customer"), skip ang deduction -- wala
        // silang katapat na record sa memberaccount_db.
        $creditResult = null;
        if (str_starts_with($customer->qr_code_data, 'MA-')) {
            $memberId = (int) str_replace('MA-', '', $customer->qr_code_data);
            $creditResult = MemberAccountService::deductSessionCredit($memberId);

            if (!$creditResult['success']) {
                return response()->json(['message' => $creditResult['message']], 422);
            }
        }

        $log = AttendanceLog::create([
            'customer_id' => $customer->id,
            'handled_by_admin_id' => $request->adminId,
            'date' => now()->toDateString(),
            'check_in_time' => now()->toTimeString(),
            'status' => $request->status,
        ]);

        $response = [
            'message' => 'Checked in successfully',
            'id' => (string) $log->id,
            'name' => trim($customer->first_name . ' ' . $customer->last_name),
        ];

        if ($creditResult) {
            $response['sessions_used'] = $creditResult['sessions_used'];
            $response['credits_total'] = $creditResult['credits_total'];
            $response['credits_left']  = $creditResult['credits_left'];
        }

        return response()->json($response, 201);
    }
}