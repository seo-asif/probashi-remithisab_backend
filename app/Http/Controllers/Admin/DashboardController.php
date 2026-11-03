<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $count = static function (string $table, ?string $status = null): int {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            $query = DB::table($table);
            if ($status !== null && Schema::hasColumn($table, 'status')) {
                $query->where('status', $status);
            }
            return $query->count();
        };

        $recentPayments = Schema::hasTable('plan_payment_requests')
            ? DB::table('plan_payment_requests')
                ->select(['id', 'status', 'created_at'])
                ->latest('id')
                ->limit(6)
                ->get()
            : collect();

        return view('admin.dashboard', [
            'metrics' => [
                'users' => $count('users'),
                'businesses' => $count('businesses'),
                'senderPlus' => Schema::hasTable('sender_subscriptions') && Schema::hasColumn('sender_subscriptions', 'tier')
                    ? DB::table('sender_subscriptions')->where('tier', '!=', 'free')->count() : 0,
                'managerPro' => Schema::hasTable('manager_subscriptions') && Schema::hasColumn('manager_subscriptions', 'tier')
                    ? DB::table('manager_subscriptions')->where('tier', '!=', 'free')->count() : 0,
                'pendingPayments' => $count('plan_payment_requests', 'pending'),
            ],
            'recentPayments' => $recentPayments,
        ]);
    }
}