<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Read-only operations dashboard. Counts represent database records, never transferred funds. */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $input = $request->validate([
            'period' => ['sometimes', Rule::in(['7', '30', '90'])],
        ]);
        $period = (int) ($input['period'] ?? 30);
        $today = now('UTC')->startOfDay();
        $since = $today->copy()->subDays($period - 1);
        $previousSince = $since->copy()->subDays($period);

        $count = static function (string $table): int {
            return Schema::hasTable($table) ? DB::table($table)->count() : 0;
        };
        $datedCount = static function (string $table, Carbon $from, ?Carbon $until = null): int {
            if (! Schema::hasTable($table)) {
                return 0;
            }
            return DB::table($table)->where('created_at', '>=', $from)
                ->when($until, fn ($q) => $q->where('created_at', '<', $until))->count();
        };
        $paid = static function (string $table): int {
            return Schema::hasTable($table)
                ? DB::table($table)->where('tier', '!=', 'free')->count()
                : 0;
        };

        $userTotal = $count('users');
        $businessTotal = $count('businesses');
        $newUsers = $datedCount('users', $since);
        $previousUsers = $datedCount('users', $previousSince, $since);
        $newBusinesses = $datedCount('businesses', $since);
        $senderPlus = $paid('sender_subscriptions');
        $managerPro = $paid('manager_subscriptions');

        $paymentBase = DB::table('plan_payment_requests');
        $paymentExists = Schema::hasTable('plan_payment_requests');
        $paymentCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        $pendingOlderThan48h = 0;
        $recentPayments = collect();
        $recentDecisions = collect();
        if ($paymentExists) {
            $paymentCounts['pending'] = (clone $paymentBase)->where('status', 'pending')->count();
            $paymentCounts['approved'] = (clone $paymentBase)->where('status', 'approved')
                ->where('decided_at', '>=', $since)->count();
            $paymentCounts['rejected'] = (clone $paymentBase)->where('status', 'rejected')
                ->where('decided_at', '>=', $since)->count();
            $pendingOlderThan48h = (clone $paymentBase)->where('status', 'pending')
                ->where('created_at', '<', now('UTC')->subHours(48))->count();
            $recentPayments = (clone $paymentBase)
                ->select(['id', 'status', 'product_type', 'created_at'])
                ->where('status', 'pending')->orderBy('created_at')->limit(5)->get();
            $recentDecisions = (clone $paymentBase)
                ->select(['id', 'status', 'decided_at'])
                ->whereIn('status', ['approved', 'rejected'])
                ->where('decided_at', '>=', $since)
                ->orderByDesc('decided_at')->limit(4)->get();
        }

        $remittances = Schema::hasTable('sender_remittances')
            ? DB::table('sender_remittances')->whereNull('deleted_at')
                ->where('created_at', '>=', $since)->count()
            : 0;
        $likes = Schema::hasTable('daily_message_likes')
            ? DB::table('daily_message_likes')->where('created_at', '>=', $since)->count()
            : 0;
        $publishedMessages = Schema::hasTable('daily_messages')
            ? DB::table('daily_messages')->where('status', 'published')->count()
            : 0;

        // Group by UTC date in PHP to avoid relying on database-specific DATE() SQL.
        $registrationRows = Schema::hasTable('users')
            ? DB::table('users')->where('created_at', '>=', $since)
                ->select('created_at')->orderBy('created_at')->get()
            : collect();
        $registrationDays = array_fill_keys(
            array_map(fn ($offset) => $since->copy()->addDays($offset)->toDateString(), range(0, $period - 1)),
            0
        );
        foreach ($registrationRows as $row) {
            $day = Carbon::parse($row->created_at, 'UTC')->toDateString();
            if (array_key_exists($day, $registrationDays)) {
                $registrationDays[$day]++;
            }
        }
        // Aggregate at most 7 visible bars to keep the overview easy to read.
        $chunkSize = (int) ceil($period / 7);
        $registrationTrend = [];
        foreach (array_chunk($registrationDays, $chunkSize, true) as $days) {
            $registrationTrend[] = [
                'label' => Carbon::parse(array_key_last($days))->format('d M'),
                'count' => array_sum($days),
            ];
        }
        $trendMax = max(1, ...array_column($registrationTrend, 'count'));

        $recentUsers = Schema::hasTable('users')
            ? DB::table('users')->select('id', 'name', 'created_at')
                ->where('created_at', '>=', $since)->orderByDesc('id')->limit(4)->get()
            : collect();

        // Events are derived only from records already stored on the server.
        $activityEvents = collect();
        if ($paymentExists) {
            $payments = (clone $paymentBase)->select(['id', 'status', 'created_at', 'decided_at'])
                ->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(12)->get();
            foreach ($payments as $payment) {
                $activityEvents->push((object) [
                    'type' => 'payment', 'title' => 'Payment request #'.$payment->id.' submitted',
                    'detail' => 'Request status: '.ucfirst($payment->status),
                    'occurred_at' => $payment->created_at, 'id' => $payment->id,
                    'link' => route('admin.payments.show', $payment->id),
                ]);
            }
            foreach ($recentDecisions as $decision) {
                $activityEvents->push((object) [
                    'type' => 'payment', 'title' => 'Payment request #'.$decision->id.' '. $decision->status,
                    'detail' => 'Payment decision recorded',
                    'occurred_at' => $decision->decided_at, 'id' => $decision->id,
                    'link' => route('admin.payments.show', $decision->id),
                ]);
            }
        }
        foreach ($recentUsers as $user) {
            $activityEvents->push((object) [
                'type' => 'account', 'title' => 'New account: '.$user->name,
                'detail' => 'A user registered on the platform',
                'occurred_at' => $user->created_at, 'id' => $user->id,
                'link' => route('admin.users.show', $user->id),
            ]);
        }
        $activityEvents = $activityEvents->sortByDesc('occurred_at')->take(12)->values();

        return view('admin.dashboard', [
            'period' => (string) $period,
            'periodLabel' => "Last {$period} days",
            'since' => $since,
            'metrics' => [
                'users' => $userTotal,
                'businesses' => $businessTotal,
                'senderPlus' => $senderPlus,
                'managerPro' => $managerPro,
                'newUsers' => $newUsers,
                'previousUsers' => $previousUsers,
                'newBusinesses' => $newBusinesses,
                'remittances' => $remittances,
                'likes' => $likes,
                'publishedMessages' => $publishedMessages,
                'pendingPayments' => $paymentCounts['pending'],
                'approvedPayments' => $paymentCounts['approved'],
                'rejectedPayments' => $paymentCounts['rejected'],
                'pendingOlderThan48h' => $pendingOlderThan48h,
            ],
            'recentPayments' => $recentPayments,
            'recentDecisions' => $recentDecisions,
            'recentUsers' => $recentUsers,
            'activityEvents' => $activityEvents,
            'registrationTrend' => $registrationTrend,
            'trendMax' => $trendMax,
        ]);
    }
}
