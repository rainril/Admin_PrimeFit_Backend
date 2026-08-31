<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    // GET /api/payments - listahan ng lahat ng membership payments
    public function index()
    {
        // Order by created_at (full timestamp) instead of date (date-only) --
        // multiple payments on the same calendar date would otherwise have
        // an unpredictable order among themselves. created_at DESC gives a
        // true most-recent-first ordering.
        $payments = Payment::with('customer')->orderByDesc('created_at')->get();

        $result = $payments->map(function ($p) {
            return [
                'member' => $p->customer ? trim($p->customer->first_name . ' ' . $p->customer->last_name) : 'Unknown',
                'plan' => $p->membership?->plan?->duration_label ?? '—',
                'amount' => '₱' . number_format($p->amount),
                'method' => $p->method,
                'date' => $p->date,
                'status' => $p->status,
            ];
        });

        return response()->json($result);
    }

    public function sendEarningsEmail(Request $request)
{
    $request->validate([
        'email'   => 'required|email',
        'subject' => 'required|string',
        'body'    => 'required|string',
        'pdf'     => 'required|file|mimes:pdf|max:10240',
    ]);

    try {
        $pdfPath = $request->file('pdf')->getRealPath();

        Mail::raw($request->input('body'), function ($message) use ($request, $pdfPath) {
            $message->to($request->input('email'))
                    ->subject($request->input('subject'))
                    ->attach($pdfPath, [
                        'as'   => 'PrimeFit_Earnings_Statement.pdf',
                        'mime' => 'application/pdf',
                    ]);
        });

        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
}