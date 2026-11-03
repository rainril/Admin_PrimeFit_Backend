<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PendingRegistrationController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->role === 'admin'
            && $request->user()->admin()->exists(), 403);
    }

    private function sendRejectionNotice(string $email, string $name, ?string $plan, string $reason): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $resendKey = config('services.resend.key');
        $resendFrom = config('services.resend.from_address');
        $reason = trim($reason) ?: 'We could not verify the payment receipt or submitted information.';
        $planLine = $plan ? "Plan: {$plan}\n" : '';
        $body = "Hello {$name},\n\n"
            . "Your PrimeFit membership payment request was reviewed but could not be approved.\n"
            . $planLine
            . "Reason: {$reason}\n\n"
            . "Please review the reason and contact the gym or submit corrected payment information. "
            . "Your membership and session credits were not changed by this rejection.\n\n"
            . "PrimeFit";

        // Resend's HTTPS API works on Render free services, which block direct
        // outbound SMTP traffic. Prefer it whenever an API key is configured.
        if (is_string($resendKey) && $resendKey !== ''
            && is_string($resendFrom) && $resendFrom !== '') {
            try {
                $response = Http::withToken($resendKey)->acceptJson()->timeout(15)
                    ->post('https://api.resend.com/emails', [
                        'from' => $resendFrom,
                        'to' => [$email],
                        'subject' => 'PrimeFit payment request update',
                        'text' => $body,
                    ]);
                if ($response->successful()) return true;
                Log::error('Resend rejected the payment rejection email.', [
                    'recipient' => $email,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                return false;
            } catch (\Throwable $e) {
                Log::error('Could not send payment rejection email through Resend.', [
                    'recipient' => $email,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            Log::warning('Payment rejection email was not delivered because the mailer is not configured for delivery.', [
                'recipient' => $email,
                'mailer' => config('mail.default'),
            ]);
            return false;
        }

        try {
            Mail::raw($body, function ($message) use ($email) {
                $message->to($email)->subject('PrimeFit payment request update');
            });
            return true;
        } catch (\Throwable $e) {
            Log::error('Could not send payment rejection email.', [
                'recipient' => $email,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function index(Request $request)
    {
        $this->requireAdmin($request);
        $rows = DB::connection('memberaccount')->table('PendingRegistrations as r')
            ->join('Plans as p', 'p.PlanID', '=', 'r.PlanID')
            ->where('r.Status', 'Pending')
            ->orderByDesc('r.RegistrationID')
            ->get(['r.RegistrationID', 'r.FirstName', 'r.LastName', 'r.Email',
                'r.Amount', 'r.PaymentMethod', 'r.CreatedAt', 'p.DurationLabel']);
        $pending = $rows->map(fn ($r) => [
            'payment_id' => 'R-'.$r->RegistrationID,
            'member_name' => trim($r->FirstName.' '.$r->LastName),
            'member_email' => $r->Email,
            'plan' => $r->DurationLabel,
            'plan_price' => $r->Amount,
            'method' => $r->PaymentMethod,
            'date' => $r->CreatedAt,
            'purpose' => 'New Registration',
        ]);
        // Legacy signups already stored a Member and linked pending Payment.
        // Include those receipts so they can be reviewed without changing data.
        $legacy = DB::connection('memberaccount')->table('Payments as py')
            ->join('Members as m', 'm.MemberID', '=', 'py.MemberID')
            ->leftJoin('Memberships as ms', 'ms.MembershipID', '=', 'py.MembershipID')
            ->leftJoin('Plans as p', 'p.PlanID', '=', 'ms.PlanID')
            ->where('py.Status', 'Pending')->whereNotNull('py.ReceiptURL')
            ->get(['py.PaymentID', 'py.MemberID', 'py.MembershipID', 'py.Amount', 'py.Method',
                'py.Date', 'm.FirstName', 'm.LastName', 'm.Email', 'ms.PlanID as MembershipPlanID',
                'ms.Status as MembershipStatus', 'p.DurationLabel']);
        $pending = $pending->concat($legacy->map(function ($r) {
            $plan = $r->DurationLabel;
            $selectedPlan = null;
            if (!$plan) {
                $matches = DB::connection('memberaccount')->table('Plans')
                    ->where('Price', $r->Amount)->limit(2)->get(['PlanID', 'DurationLabel']);
                if ($matches->count() === 1) {
                    $selectedPlan = $matches->first();
                    $plan = $selectedPlan->DurationLabel;
                }
            } else {
                $selectedPlan = DB::connection('memberaccount')->table('Plans')
                    ->where('PlanID', $r->MembershipPlanID)->first(['PlanID', 'DurationLabel']);
            }

            if ($r->MembershipID && $r->MembershipStatus !== 'Active') {
                $purpose = 'New Registration';
            } else {
                $activePlanId = DB::connection('memberaccount')->table('Memberships')
                    ->where('MemberID', $r->MemberID)->where('Status', 'Active')
                    ->orderByDesc('MembershipID')->value('PlanID');
                if (!$activePlanId) {
                    $purpose = 'New Registration';
                } elseif ($selectedPlan && (int) $activePlanId === (int) $selectedPlan->PlanID) {
                    $purpose = 'Renewal';
                } elseif ($selectedPlan) {
                    $purpose = 'Upgrade';
                } else {
                    $purpose = 'Payment review';
                }
            }
            return [
                'payment_id' => 'P-'.$r->PaymentID,
                'member_name' => trim($r->FirstName.' '.$r->LastName),
                'member_email' => $r->Email, 'plan' => $plan,
                'plan_price' => $r->Amount, 'method' => $r->Method,
                'date' => $r->Date,
                'purpose' => $purpose,
            ];
        }));
        return response()->json(['success' => true, 'pending' => $pending->values()]);
    }

    public function receipt(Request $request, int $id)
    {
        $this->requireAdmin($request);
        $row = DB::connection('memberaccount')->table('PendingRegistrations')
            ->where('RegistrationID', $id)->first(['Receipt', 'ReceiptMime']);
        abort_unless($row, 404);
        return response($row->Receipt, 200, [
            'Content-Type' => $row->ReceiptMime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approvedPayments(Request $request)
    {
        $this->requireAdmin($request);
        $rows = DB::connection('memberaccount')->table('Payments as py')
            ->join('Members as m', 'm.MemberID', '=', 'py.MemberID')
            ->leftJoin('Memberships as ms', 'ms.MembershipID', '=', 'py.MembershipID')
            ->leftJoin('Plans as p', 'p.PlanID', '=', 'ms.PlanID')
            ->where('py.Status', 'Paid')->orderByDesc('py.PaymentID')
            ->get(['py.Amount', 'py.Method', 'py.Date', 'm.FirstName', 'm.LastName', 'p.DurationLabel']);
        return response()->json(['success' => true, 'payments' => $rows->map(fn ($r) => [
            'member' => trim($r->FirstName.' '.$r->LastName), 'plan' => $r->DurationLabel,
            'amount' => $r->Amount, 'method' => $r->Method, 'date' => $r->Date, 'status' => 'Paid',
        ])]);
    }

    public function approve(Request $request, int $id)
    {
        $this->requireAdmin($request);
        $secret = env('QR_SECRET_KEY');
        abort_unless(is_string($secret) && $secret !== '', 503, 'QR signing is not configured.');
        $db = DB::connection('memberaccount');
        $memberId = $db->transaction(function () use ($db, $id, $secret) {
            $r = $db->table('PendingRegistrations')->where('RegistrationID', $id)->lockForUpdate()->first();
            abort_unless($r, 404);
            abort_unless($r->Status === 'Pending', 409, 'This registration has already been reviewed.');
            abort_if($db->table('Members')->where('Email', $r->Email)->exists(), 409, 'An account with this email already exists. Review it manually.');
            $plan = $db->table('Plans')->where('PlanID', $r->PlanID)->first();
            abort_unless($plan && (int) $plan->DurationMonths > 0, 422, 'The selected plan is unavailable.');
            $memberId = $db->table('Members')->insertGetId([
                'FirstName' => $r->FirstName, 'LastName' => $r->LastName,
                'Email' => $r->Email, 'PasswordHash' => $r->PasswordHash,
                'Phone' => $r->Phone, 'DateOfBirth' => $r->DateOfBirth,
                'Gender' => $r->Gender, 'Address' => $r->Address,
            ]);
            $payload = $memberId.'.'.time();
            $db->table('Members')->where('MemberID', $memberId)->update([
                'QRCodeData' => $payload.'.'.hash_hmac('sha256', $payload, $secret),
            ]);
            $months = (int) $plan->DurationMonths;
            $membershipId = $db->table('Memberships')->insertGetId([
                'MemberID' => $memberId, 'PlanID' => $r->PlanID,
                'StartDate' => now()->toDateString(),
                'NextRenewalDate' => now()->addMonthsNoOverflow($months)->toDateString(),
                'Status' => 'Active', 'SessionCredits' => $months * 30, 'SessionsUsed' => 0,
            ]);
            $db->table('Payments')->insert([
                'MemberID' => $memberId, 'MembershipID' => $membershipId,
                'Date' => now()->toDateString(), 'Amount' => $r->Amount,
                'Method' => $r->PaymentMethod, 'Status' => 'Paid',
            ]);
            $db->table('PendingRegistrations')->where('RegistrationID', $id)->update([
                'Status' => 'Approved', 'ReviewedAt' => now(), 'MemberID' => $memberId,
                'PasswordHash' => '',
            ]);
            return $memberId;
        });
        return response()->json(['success' => true, 'member_id' => $memberId, 'message' => 'Registration approved.']);
    }

    public function reject(Request $request, int $id)
    {
        $this->requireAdmin($request);
        $db = DB::connection('memberaccount');
        $registration = $db->table('PendingRegistrations')->where('RegistrationID', $id)
            ->where('Status', 'Pending')->first(['FirstName', 'Email', 'PlanID']);
        if (!$registration) return response()->json(['success' => false, 'message' => 'Registration not found or already reviewed.'], 409);
        $updated = $db->table('PendingRegistrations')
            ->where('RegistrationID', $id)->where('Status', 'Pending')
            ->update(['Status' => 'Rejected', 'ReviewedAt' => now(), 'PasswordHash' => '']);
        if (!$updated) return response()->json(['success' => false, 'message' => 'Registration not found or already reviewed.'], 409);
        $plan = $db->table('Plans')->where('PlanID', $registration->PlanID)->value('DurationLabel');
        $emailSent = $this->sendRejectionNotice(
            (string) $registration->Email,
            (string) $registration->FirstName,
            $plan ? (string) $plan : null,
            (string) $request->input('reason', ''),
        );
        return response()->json([
            'success' => true,
            'email_sent' => $emailSent,
            'message' => $emailSent
                ? 'Registration rejected and email sent.'
                : 'Registration rejected, but the notification email was not delivered. Check the mail settings.',
        ]);
    }

    public function legacyReceipt(Request $request, int $id)
    {
        $this->requireAdmin($request);
        $row = DB::connection('memberaccount')->table('Payments')
            ->where('PaymentID', $id)->first(['ReceiptURL']);
        $path = $row->ReceiptURL ?? '';
        abort_unless(preg_match('#^uploads/receipts/[A-Za-z0-9_.-]+$#D', $path), 404);
        $response = Http::timeout(10)->get('https://member-account-backend.onrender.com/'.$path);
        $contentType = (string) $response->header('Content-Type');
        abort_unless($response->successful() && str_starts_with($contentType, 'image/'), 404);
        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reviewLegacy(Request $request, int $id, string $decision)
    {
        $this->requireAdmin($request);
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        // Do not approve an older registration when its receipt can no
        // longer be retrieved for review (for example after a redeploy).
        if ($decision === 'approve') $this->legacyReceipt($request, $id);
        $db = DB::connection('memberaccount');
        $db->transaction(function () use ($db, $id, $decision) {
            $payment = $db->table('Payments')->where('PaymentID', $id)->lockForUpdate()->first();
            abort_unless($payment && $payment->ReceiptURL, 404);
            abort_unless($payment->Status === 'Pending', 409, 'This payment has already been reviewed.');
            $linkedMembership = $payment->MembershipID
                ? $db->table('Memberships')->where('MembershipID', $payment->MembershipID)
                    ->where('MemberID', $payment->MemberID)->lockForUpdate()->first()
                : null;
            abort_if($payment->MembershipID && !$linkedMembership, 409, 'The linked membership was not found.');
            // New upgrade receipts link to the current active membership to
            // support databases where Payments.MembershipID is NOT NULL.
            // Treat those like unlinked manual payments so plan changes still
            // happen only inside this admin approval transaction.
            if (!$linkedMembership || $linkedMembership->Status === 'Active') {
                if ($decision === 'reject') {
                    $db->table('Payments')->where('PaymentID', $id)->update(['Status' => 'Failed']);
                    return;
                }
                $plans = $db->table('Plans')->where('Price', $payment->Amount)->limit(2)->get();
                abort_unless($plans->count() === 1 && (int) $plans->first()->DurationMonths > 0, 422, 'Cannot identify a unique plan for this payment. Review it manually.');
                $plan = $plans->first();
                $months = (int) $plan->DurationMonths;
                $membership = $db->table('Memberships')->where('MemberID', $payment->MemberID)
                    ->where('Status', 'Active')->orderByDesc('MembershipID')->lockForUpdate()->first();
                if ($membership && (int) $membership->PlanID === (int) $plan->PlanID) {
                    $renewal = $membership->NextRenewalDate ? \Carbon\Carbon::parse($membership->NextRenewalDate) : now();
                    if ($renewal->isPast()) $renewal = now();
                    $db->table('Memberships')->where('MembershipID', $membership->MembershipID)
                        ->update([
                            'NextRenewalDate' => $renewal->addMonthsNoOverflow($months)->toDateString(),
                            'SessionCredits' => (int) $membership->SessionCredits + $months * 30,
                        ]);
                    $membershipId = $membership->MembershipID;
                } else {
                    $remainingCredits = $membership
                        ? max(0, (int) $membership->SessionCredits - (int) $membership->SessionsUsed)
                        : 0;
                    if ($membership) {
                        $db->table('Memberships')->where('MemberID', $payment->MemberID)
                            ->where('Status', 'Active')->update(['Status' => 'Expired']);
                    }
                    $membershipId = $db->table('Memberships')->insertGetId([
                        'MemberID' => $payment->MemberID, 'PlanID' => $plan->PlanID,
                        'StartDate' => now()->toDateString(), 'NextRenewalDate' => now()->addMonthsNoOverflow($months)->toDateString(),
                        'Status' => 'Active', 'SessionCredits' => $remainingCredits + ($months * 30), 'SessionsUsed' => 0,
                    ]);
                }
                $db->table('Payments')->where('PaymentID', $id)->update(['Status' => 'Paid', 'MembershipID' => $membershipId]);
                return;
            }
            $membership = $linkedMembership;
            $paid = $db->table('Payments')->where('MembershipID', $membership->MembershipID)->where('Status', 'Paid')->exists();
            if ($decision === 'reject') {
                if (!$paid) $db->table('Memberships')->where('MembershipID', $membership->MembershipID)->update(['Status' => 'Cancelled']);
            } else {
                if (!$paid) {
                    $plan = $db->table('Plans')->where('PlanID', $membership->PlanID)->first();
                    abort_unless($plan && (int) $plan->DurationMonths > 0, 422, 'The selected plan is unavailable.');
                    $months = (int) $plan->DurationMonths;
                    $db->table('Memberships')->where('MembershipID', $membership->MembershipID)->update([
                        'Status' => 'Active', 'StartDate' => now()->toDateString(),
                        'NextRenewalDate' => now()->addMonthsNoOverflow($months)->toDateString(),
                        'SessionCredits' => $months * 30, 'SessionsUsed' => 0,
                    ]);
                }
            }
            $db->table('Payments')->where('PaymentID', $id)->update(['Status' => $decision === 'approve' ? 'Paid' : 'Failed']);
        });
        $emailSent = null;
        if ($decision === 'reject') {
            $details = $db->table('Payments as py')
                ->join('Members as m', 'm.MemberID', '=', 'py.MemberID')
                ->where('py.PaymentID', $id)
                ->first(['m.Email', 'm.FirstName', 'py.Amount']);
            $plan = $details
                ? $db->table('Plans')->where('Price', $details->Amount)->value('DurationLabel')
                : null;
            $emailSent = $details ? $this->sendRejectionNotice(
                (string) $details->Email,
                (string) $details->FirstName,
                $plan ? (string) $plan : null,
                (string) $request->input('reason', ''),
            ) : false;
        }
        return response()->json([
            'success' => true,
            'email_sent' => $emailSent,
            'message' => $decision === 'reject'
                ? ($emailSent
                    ? 'Payment rejected and email sent.'
                    : 'Payment rejected, but the notification email was not delivered. Check the mail settings.')
                : 'Payment reviewed.',
        ]);
    }
}
