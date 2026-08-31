<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\AttendanceLog;
use App\Models\WalkIn;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class AiController extends Controller
{
    // ================= CORE STATS =================

    private function getStats(): array
    {
        $stats = [];

        $stats['active_subscriptions'] = Membership::where('status', 'active')->count();

        $stats['active_members'] = Membership::where('status', 'active')
            ->distinct('customer_id')
            ->count('customer_id');

        // Yearly revenue (Jan 1 - Dec 31 of current year), membership + walk-in combined.
        // This is what now powers the "Total Revenue" dashboard card.
        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();

        $membershipRevenueYear = Payment::where('status', 'Paid')
            ->whereBetween('date', [$yearStart, $yearEnd])
            ->sum('amount');

        $walkInRevenueYear = WalkIn::where('status', 'Paid')
            ->whereBetween('date', [$yearStart, $yearEnd])
            ->sum('amount');

        $stats['total_revenue_year'] = $membershipRevenueYear + $walkInRevenueYear;

        // Kept for AI context only (not shown as its own dashboard card anymore).
        $membershipRevenueMonth = Payment::where('status', 'Paid')
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('amount');

        $walkInRevenueMonth = WalkIn::where('status', 'Paid')
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('amount');

        $stats['total_revenue_month'] = $membershipRevenueMonth + $walkInRevenueMonth;

        $activeMembers = max($stats['active_members'], 1);
        $checkedInToday = AttendanceLog::whereDate('date', now()->toDateString())
            ->distinct('customer_id')
            ->count('customer_id');
        $stats['checked_in_today'] = $checkedInToday;
        $stats['attendance_rate_today'] = round(($checkedInToday / $activeMembers) * 100);

        $stats['failed_payments_7d'] = Payment::where('status', 'Failed')
            ->where('date', '>=', now()->subDays(7))
            ->count();

        $stats['pending_payments'] = Payment::where('status', 'Pending')->count()
            + WalkIn::where('status', 'Pending')->count();

        $churn = $this->computeChurnRisk();
        $stats['churn_risk_high'] = collect($churn)->where('risk_level', 'High')->count();
        $stats['churn_risk_medium'] = collect($churn)->where('risk_level', 'Medium')->count();
        $stats['churn_risk_total'] = count($churn);

        return $stats;
    }

    private function callGroq(array $messages, int $maxTokens = 500): string
    {
        $apiKey = config('services.groq.key');

        $response = Http::withToken($apiKey)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => 'openai/gpt-oss-120b',
                'messages' => $messages,
                'temperature' => 0.4,
                'max_tokens' => $maxTokens,
            ]);

        if ($response->failed()) {
            abort(502, 'Groq request failed: ' . $response->body());
        }

        return $response->json('choices.0.message.content') ?? 'No response generated.';
    }

    public function dashboardSummary()
    {
        $stats = $this->getStats();

        $prompt = "You are a senior gym business analyst preparing a written briefing for the owner. "
            . "Using ONLY the data provided (do not invent numbers), write a detailed, professional "
            . "analysis in 4-6 sentences of flowing prose (no headers, no bullet points, no markdown). "
            . "Cover: (1) overall business health this year, citing specific figures, (2) attendance "
            . "and engagement trends today, (3) revenue performance and any payment issues (failed or "
            . "pending), and (4) churn/retention risk exposure. Close with one concrete, actionable "
            . "recommendation for the owner. Use a confident, analytical tone, as if this were an "
            . "executive summary in a business report. Be direct and specific, no fluff. All monetary "
            . "figures are in Philippine Pesos — always use the ₱ symbol (never $ or USD).\n\n"
            . 'Data: ' . json_encode($stats);

        $summary = $this->callGroq([
            ['role' => 'user', 'content' => $prompt],
        ], 400);

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'stats' => $stats,
        ]);
    }

    public function chat(Request $request)
    {
        $history = $request->input('history', []);
        $stats = $this->getStats();

        $systemPrompt = "You are PrimeFit's AI assistant for the gym owner/staff. "
            . 'You have access to the gym\'s current stats: ' . json_encode($stats) . '. '
            . 'Answer questions about members, revenue, attendance, churn risk, and subscriptions using this data. '
            . 'All monetary figures are in Philippine Pesos — always use the ₱ symbol (never $ or USD). '
            . 'Be concise and practical.';

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history
        );

        $reply = $this->callGroq($messages);

        return response()->json([
            'success' => true,
            'reply' => $reply,
        ]);
    }

    public function dashboardStats()
    {
        $stats = $this->getStats();

        return response()->json([
            'success' => true,
            'activeSubscriptions' => $stats['active_subscriptions'],
            'activeMembers' => $stats['active_members'],
            'totalRevenue' => (int) $stats['total_revenue_year'],
            'churnRiskCount' => $stats['churn_risk_total'],
        ]);
    }

    // ================= CHURN RISK (prototype, rule-based) =================
    //
    // NOTE: This is a rule-based prototype, NOT a trained ML model yet. It
    // flags active members as churn risks using two simple signals:
    //   1. Days since their last gym visit (attendance_logs)
    //   2. Days until their membership expires (memberships.end_date)
    // Swap the scoring logic in `scoreChurnRisk()` later for a real model —
    // the response shape (fields returned) can stay the same so the
    // Flutter side doesn't need to change.
    //
    // ASSUMPTION: Membership has `customer_id`, `end_date`, and a
    // `plan_name` (or `plan`) column, plus a `customer()` relation with a
    // `name` attribute. Adjust field names below to match your actual schema.

    private function customerFullName(?Customer $customer): string
    {
        if (!$customer) {
            return 'Unknown';
        }

        return trim("{$customer->first_name} {$customer->last_name}") ?: 'Unknown';
    }

    private function computeChurnRisk(): array
    {
        $activeMemberships = Membership::with(['customer', 'plan'])
            ->where('status', 'active')
            ->get();

        $results = [];

        foreach ($activeMemberships as $membership) {
            $customerId = $membership->customer_id;

            $lastVisit = AttendanceLog::where('customer_id', $customerId)
                ->orderByDesc('date')
                ->value('date');

            $daysSinceVisit = $lastVisit ? Carbon::parse($lastVisit)->diffInDays(now()) : null;

            // next_renewal_date doubles as "when this membership needs action" —
            // treat it the same way we'd treat an expiry date for churn scoring.
            $daysUntilExpiry = $membership->next_renewal_date
                ? now()->diffInDays(Carbon::parse($membership->next_renewal_date), false)
                : null;

            [$riskLevel, $reason] = $this->scoreChurnRisk($daysSinceVisit, $daysUntilExpiry);

            if ($riskLevel === 'Low') {
                continue; // only surface members actually worth the owner's attention
            }

            $results[] = [
                'customer_id' => $customerId,
                'name' => $this->customerFullName($membership->customer),
                'plan' => optional($membership->plan)->duration_label,
                'days_since_last_visit' => $daysSinceVisit,
                'days_until_expiry' => $daysUntilExpiry,
                'risk_level' => $riskLevel,
                'reason' => $reason,
            ];
        }

        // Highest risk first
        usort($results, fn ($a, $b) => $this->riskWeight($b['risk_level']) <=> $this->riskWeight($a['risk_level']));

        return $results;
    }

    private function scoreChurnRisk(?int $daysSinceVisit, ?int $daysUntilExpiry): array
    {
        // No attendance history yet — not enough signal, treat as low risk.
        if ($daysSinceVisit === null) {
            return ['Low', 'No attendance history yet'];
        }

        $expiringSoon = $daysUntilExpiry !== null && $daysUntilExpiry <= 7;

        if ($daysSinceVisit > 21) {
            return ['High', "No visit in {$daysSinceVisit} days"];
        }

        if ($daysSinceVisit > 14 && $expiringSoon) {
            return ['High', "Inactive {$daysSinceVisit} days and plan expires soon"];
        }

        if ($daysSinceVisit > 14) {
            return ['Medium', "No visit in {$daysSinceVisit} days"];
        }

        if ($expiringSoon) {
            return ['Medium', 'Plan expires within a week'];
        }

        if ($daysSinceVisit > 7) {
            return ['Low', "No visit in {$daysSinceVisit} days"];
        }

        return ['Low', 'Active and engaged'];
    }

    private function riskWeight(string $level): int
    {
        return match ($level) {
            'High' => 2,
            'Medium' => 1,
            default => 0,
        };
    }

    public function churnRiskList()
    {
        $list = $this->computeChurnRisk();

        return response()->json([
            'success' => true,
            'count' => count($list),
            'members' => $list,
        ]);
    }

    // ================= EXPIRING MEMBERSHIPS =================
    //
    // Dedicated list of members whose Active membership renews within
    // [withinDays] (default 7, matching the member-side "expiring soon"
    // threshold). Unlike churn risk, this is NOT mixed with attendance --
    // it's a straightforward renewal action list, so admin can proactively
    // reach out before a plan lapses. Also surfaces `renewal_count`, the
    // number of times this member has renewed/upgraded before, so admin
    // can distinguish habitual renewers from first-time members.

    private function computeExpiringMemberships(int $withinDays = 7): array
    {
        $cutoff = now()->addDays($withinDays);

        $memberships = Membership::with(['customer', 'plan'])
            ->where('status', 'active')
            ->whereNotNull('next_renewal_date')
            ->where('next_renewal_date', '<=', $cutoff)
            ->orderBy('next_renewal_date')
            ->get();

        $results = [];

        foreach ($memberships as $membership) {
            $customerId = $membership->customer_id;

            $daysUntilExpiry = now()->diffInDays(Carbon::parse($membership->next_renewal_date), false);

            // Every past renewal/upgrade created its own Membership row for
            // this customer (see membership_api.php's expire-then-create
            // logic), so total rows minus this current one = past renewals.
            $totalMembershipRows = Membership::where('customer_id', $customerId)->count();

            $results[] = [
                'customer_id' => $customerId,
                'name' => $this->customerFullName($membership->customer),
                'email' => optional($membership->customer)->email,
                'plan' => optional($membership->plan)->duration_label,
                'next_renewal_date' => $membership->next_renewal_date,
                'days_until_expiry' => $daysUntilExpiry,
                'renewal_count' => max($totalMembershipRows - 1, 0),
                'is_expired' => $daysUntilExpiry < 0,
            ];
        }

        return $results;
    }

    public function expiringMemberships(Request $request)
    {
        $days = (int) $request->query('days', 7);
        $list = $this->computeExpiringMemberships($days);

        return response()->json([
            'success' => true,
            'count' => count($list),
            'members' => $list,
        ]);
    }

    // AI-narrated churn forecast. Prototype: takes the rule-based churn list
    // above and asks the model to summarize it in plain language, same
    // pattern as dashboardSummary(). Once real churn prediction is ready,
    // swap computeChurnRisk() for actual predicted probabilities — this
    // narration endpoint keeps working as-is.
    public function churnForecast()
    {
        $churn = $this->computeChurnRisk();
        $high = collect($churn)->where('risk_level', 'High')->values();
        $medium = collect($churn)->where('risk_level', 'Medium')->values();

        $totalActive = Membership::where('status', 'active')->count();
        $riskRate = $totalActive > 0
            ? round((count($churn) / $totalActive) * 100)
            : 0;

        // No real risk to report - skip the AI call entirely instead of
        // letting the model guess/hallucinate numbers that don't exist.
        if ($high->isEmpty() && $medium->isEmpty()) {
            return response()->json([
                'success' => true,
                'forecast' => 'No members are currently at risk of churning. All active members '
                    . 'have visited recently and no memberships are expiring soon.',
                'risk_rate_percent' => 0,
                'high_risk_count' => 0,
                'medium_risk_count' => 0,
                'total_active' => $totalActive,
            ]);
        }

        $prompt = "You are a senior retention analyst delivering a professional forecast to the gym "
            . "owner. Based ONLY on this REAL churn-risk data (never invent numbers beyond what is "
            . "given), write a detailed 4-6 sentence analysis in flowing prose (no headers, no bullet "
            . "points, no markdown). Cover: (1) how many members are at risk and at what severity, "
            . "(2) the specific reasons driving that risk (attendance gaps, expiring plans), "
            . "(3) the likely revenue/business impact if no action is taken, and (4) two concrete, "
            . "prioritized retention actions the owner should take this week, referencing specific "
            . "members by name where useful. Use a confident, analytical tone appropriate for a "
            . "business report. Be direct and specific, no fluff. All monetary figures are in "
            . "Philippine Pesos — always use the ₱ symbol (never $ or USD).\n\n"
            . 'High risk (' . $high->count() . ' members): ' . json_encode($high)
            . ' Medium risk (' . $medium->count() . ' members): ' . json_encode($medium);

        $forecast = $this->callGroq([
            ['role' => 'user', 'content' => $prompt],
        ], 400);

        return response()->json([
            'success' => true,
            'forecast' => $forecast,
            'risk_rate_percent' => $riskRate,
            'high_risk_count' => $high->count(),
            'medium_risk_count' => $medium->count(),
            'total_active' => $totalActive,
        ]);
    }

    // ================= REVENUE ANALYTICS =================

    public function revenueAnalytics(Request $request)
    {
        $period = $request->query('period', 'monthly'); // weekly | monthly | yearly

        return response()->json([
            'success' => true,
            'period' => $period,
            'data' => match ($period) {
                'weekly' => $this->revenueWeekly(),
                'yearly' => $this->revenueYearly(),
                default => $this->revenueMonthly(),
            },
        ]);
    }

    private function paidRevenueBetween(Carbon $start, Carbon $end): float
    {
        $membership = Payment::where('status', 'Paid')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');

        $walkIn = WalkIn::where('status', 'Paid')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');

        return (float) ($membership + $walkIn);
    }

    private function revenueWeekly(): array
    {
        $start = now()->startOfWeek(Carbon::MONDAY);
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $values = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);
            $values[] = $this->paidRevenueBetween($day->copy()->startOfDay(), $day->copy()->endOfDay());
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function revenueMonthly(): array
    {
        $labels = [];
        $values = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->copy()->subMonths($i);
            $labels[] = $month->format('M');
            $values[] = $this->paidRevenueBetween($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function revenueYearly(): array
    {
        $labels = [];
        $values = [];

        for ($i = 4; $i >= 0; $i--) {
            $year = now()->copy()->subYears($i);
            $labels[] = $year->format('Y');
            $values[] = $this->paidRevenueBetween($year->copy()->startOfYear(), $year->copy()->endOfYear());
        }

        return ['labels' => $labels, 'values' => $values];
    }

    // ================= ATTENDANCE ANALYTICS =================
    // Real weekly foot-traffic: member check-ins (attendance_logs) + walk-ins,
    // grouped by day for the current Mon-Sun business week. This replaces the
    // hardcoded dummy values that used to sit in the Flutter dashboard.

    public function attendanceAnalytics()
    {
        $start = now()->startOfWeek(Carbon::MONDAY);
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $values = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);

            $memberCheckIns = AttendanceLog::whereDate('date', $day->toDateString())
                ->distinct('customer_id')
                ->count('customer_id');

            $walkIns = WalkIn::whereDate('date', $day->toDateString())->count();

            $values[] = $memberCheckIns + $walkIns;
        }

        return response()->json([
            'success' => true,
            'labels' => $labels,
            'values' => $values,
        ]);
    }

    // ================= RECENT NOTIFICATIONS =================
    // Real, database-driven activity feed for the Dashboard's
    // "Recent Notifications" card. Combines new member registrations
    // and payment events (paid/failed/pending), sorted by most recent
    // first. Replaces the previous static/hardcoded 3-item list.

    public function recentNotifications(Request $request)
    {
        $limit = (int) $request->query('limit', 8);

        $registrations = Customer::orderByDesc('created_at')
            ->take($limit)
            ->get()
            ->map(function ($c) {
                return [
                    'type' => 'registration',
                    'title' => 'New member registered',
                    'subtitle' => trim("{$c->first_name} {$c->last_name}") . ' joined PrimeFit',
                    'timestamp' => $c->created_at,
                ];
            });

        $payments = Payment::with('customer')
            ->orderByDesc('created_at')
            ->take($limit)
            ->get()
            ->map(function ($p) {
                $name = $p->customer ? trim("{$p->customer->first_name} {$p->customer->last_name}") : 'A member';
                $amount = '₱' . number_format($p->amount);

                if ($p->status === 'Paid') {
                    $title = 'Payment received';
                    $subtitle = "{$name} paid {$amount} via {$p->method}";
                } elseif ($p->status === 'Failed') {
                    $title = 'Payment failed';
                    $subtitle = "{$name}'s payment could not be processed";
                } else {
                    $title = 'Payment pending';
                    $subtitle = "{$name} has a pending payment of {$amount}";
                }

                return [
                    'type' => 'payment_' . strtolower($p->status),
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'timestamp' => $p->created_at,
                ];
            });

        $combined = $registrations->concat($payments)
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values();

        return response()->json([
            'success' => true,
            'notifications' => $combined,
        ]);
    }

    // ================= TODAY'S ACTIVITY (walk-ins + member attendance) =================

    /**
     * GET /api/dashboard/todays-walkins
     * Powers the "Today's Walk-in Attendance" dashboard card — real
     * walk-in check-ins for today, most recent first.
     */
    public function todaysWalkIns()
    {
        $walkIns = WalkIn::whereDate('date', now()->toDateString())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $entries = $walkIns->map(function ($w) {
            return [
                'name' => $w->name,
                'time' => $w->check_in ?? '--',
                'amount' => (float) $w->amount,
            ];
        });

        return response()->json([
            'success' => true,
            'entries' => $entries,
        ]);
    }

    /**
     * GET /api/dashboard/todays-member-attendance
     * Powers the "Today's Member Attendance" dashboard card — real member
     * QR check-ins for today, most recent first, with each member's
     * current plan label.
     */
    public function todaysMemberAttendance()
    {
        $logs = AttendanceLog::with('customer')
            ->whereDate('date', now()->toDateString())
            ->orderByDesc('check_in_time')
            ->limit(10)
            ->get();

        $entries = $logs->map(function ($log) {
            $customer = $log->customer;

            $membership = $customer
                ? Membership::where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->with('plan')
                    ->latest('start_date')
                    ->first()
                : null;

            return [
                'name' => $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Unknown',
                'time' => $this->formatDashboardTime($log->check_in_time),
                'plan' => optional($membership?->plan)->duration_label,
            ];
        });

        return response()->json([
            'success' => true,
            'entries' => $entries,
        ]);
    }

    // Same "HH:MM:SS" -> "h:mm AM/PM" conversion AttendanceController
    // uses, duplicated here so this controller doesn't depend on that
    // one's private method.
    private function formatDashboardTime(?string $time): string
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
}