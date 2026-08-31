<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MemberAccountService
{
    /**
     * Kunin ang detalye ng member (kasama ang aktibong membership plan)
     * mula sa memberaccount_db, gamit ang MemberID.
     */
    public static function getMemberDetails(int $memberId): ?array
    {
        $member = DB::connection('memberaccount')
            ->table('members')
            ->where('MemberID', $memberId)
            ->first();

        if (!$member) {
            return null;
        }

        $membership = DB::connection('memberaccount')
            ->table('memberships')
            ->where('MemberID', $memberId)
            ->where('Status', 'Active')
            ->orderByDesc('StartDate')
            ->first();

        $planName = 'No Active Plan';
        $expiryDate = null;
        $status = 'expired';

        if ($membership) {
            $plan = DB::connection('memberaccount')
                ->table('plans')
                ->where('PlanID', $membership->PlanID)
                ->first();

            $planName = $plan->DurationLabel ?? 'Unknown Plan';
            $expiryDate = $membership->NextRenewalDate;
            $status = 'active';

            if ($expiryDate && now()->greaterThan($expiryDate)) {
                $status = 'expired';
            } elseif ($expiryDate && now()->diffInDays($expiryDate, false) <= 7) {
                $status = 'expiring_soon';
            }
        }

        return [
            'memberId' => $member->MemberID,
            'firstName' => $member->FirstName,
            'lastName' => $member->LastName,
            'email' => $member->Email,
            'plan' => $planName,
            'subscriptionStatus' => $status,
            'expiryDate' => $expiryDate,
        ];
    }

    /**
     * Kunin ang listahan ng LAHAT ng members + kanilang aktibong membership,
     * direkta mula sa memberaccount_db. Tinatawag ito sa bawat request
     * (hindi naka-cache), kaya laging real-time ang datos.
     */
    public static function getAllMembersLive(): array
    {
        $members = DB::connection('memberaccount')->table('members')->get();

        $memberships = DB::connection('memberaccount')
            ->table('memberships')
            ->where('Status', 'Active')
            ->orderByDesc('StartDate')
            ->get()
            ->groupBy('MemberID');

        $plans = DB::connection('memberaccount')->table('plans')->get()->keyBy('PlanID');

        return $members->map(function ($member) use ($memberships, $plans) {
            $membership = optional($memberships->get($member->MemberID))->first();
            $plan = $membership ? ($plans->get($membership->PlanID)) : null;

            $status = 'expired';
            if ($membership) {
                $status = 'active';
                if ($membership->NextRenewalDate && now()->greaterThan($membership->NextRenewalDate)) {
                    $status = 'expired';
                } elseif ($membership->NextRenewalDate && now()->diffInDays($membership->NextRenewalDate, false) <= 7) {
                    $status = 'expiring_soon';
                }
            }

            return [
                'id' => 'MA-' . $member->MemberID,
                'name' => trim($member->FirstName . ' ' . $member->LastName),
                'email' => $member->Email,
                'plan' => $plan->DurationLabel ?? 'No Active Plan',
                'subscriptionStatus' => $status,
                'expiryDate' => $membership->NextRenewalDate ?? null,
            ];
        })->values()->toArray();
    }
    /**
     * Ideduct ang 1 session credit ng member sa memberaccount_db --
     * ito ang totoong "source of truth" ng SessionCredits/SessionsUsed,
     * kaya dito rin dapat mangyari ang totoong deduction (hindi lang sa
     * lokal na primefit_db).
     *
     * Ginagaya nito ang parehong logic ng checkin_api.php sa Member app:
     * mag-i-insert ng AttendanceLogs row, mag-i-increment ng SessionsUsed,
     * at mag-a-update/gagawa ng MonthlyGoals row.
     */
    public static function deductSessionCredit(int $memberId): array
    {
        $connection = DB::connection('memberaccount');

        $membership = $connection->table('memberships')
            ->where('MemberID', $memberId)
            ->where('Status', 'Active')
            ->orderByDesc('StartDate')
            ->first();

        if (!$membership) {
            return ['success' => false, 'message' => 'No active membership found for this member.'];
        }

        $sessionCredits = (int) $membership->SessionCredits;
        $sessionsUsed   = (int) $membership->SessionsUsed;

        if ($sessionsUsed >= $sessionCredits) {
            return ['success' => false, 'message' => 'No session credits left this period.'];
        }

        $connection->beginTransaction();
        try {
            $connection->table('AttendanceLogs')->insert([
                'MemberID'          => $memberId,
                'Date'               => now()->toDateString(),
                'CheckInTime'        => now()->toTimeString(),
                'SessionCreditUsed'  => 1,
            ]);

            $connection->table('memberships')
                ->where('MembershipID', $membership->MembershipID)
                ->update(['SessionsUsed' => $sessionsUsed + 1]);

            $currentMonth = now()->format('Y-m');
            $existingGoal = $connection->table('MonthlyGoals')
                ->where('MemberID', $memberId)
                ->where('Month', $currentMonth)
                ->first();

            if ($existingGoal) {
                $connection->table('MonthlyGoals')
                    ->where('GoalID', $existingGoal->GoalID)
                    ->update(['CompletedSessions' => $existingGoal->CompletedSessions + 1]);
            } else {
                $connection->table('MonthlyGoals')->insert([
                    'MemberID'          => $memberId,
                    'Month'              => $currentMonth,
                    'TargetSessions'     => 20,
                    'CompletedSessions'  => 1,
                ]);
            }

            $connection->commit();

            $newSessionsUsed = $sessionsUsed + 1;

            return [
                'success'        => true,
                'sessions_used'  => $newSessionsUsed,
                'credits_total'  => $sessionCredits,
                'credits_left'   => $sessionCredits - $newSessionsUsed,
            ];
        } catch (\Exception $e) {
            $connection->rollBack();
            return ['success' => false, 'message' => 'Error deducting session credit: ' . $e->getMessage()];
        }
    }
}