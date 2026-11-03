<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ActivityLogsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:60', 'regex:/\A[a-zA-Z0-9._-]+\z/D'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $search = trim($input['q'] ?? '');
        $action = $input['action'] ?? '';
        $from = $input['from'] ?? '';
        $to = $input['to'] ?? '';

        $query = AuditLog::query()->with('actor:id,name,email');

        if ($action !== '') {
            $query->where('action', $action);
        }
        if ($from !== '') {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Pacific/Fiji')->startOfDay()->utc());
        }
        if ($to !== '') {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Pacific/Fiji')->addDay()->startOfDay()->utc());
        }
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('action', 'like', '%'.$search.'%')
                    ->orWhereHas('actor', function ($actor) use ($search) {
                        $actor->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });

                if (ctype_digit($search)) {
                    $query->orWhere('target_id', (int) $search)
                        ->orWhere('actor_user_id', (int) $search);
                }
            });
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $actions = DB::table('audit_logs')->select('action')->distinct()
            ->orderBy('action')->pluck('action');

        return view('admin.activity-logs', compact('logs', 'actions', 'search', 'action', 'from', 'to'));
    }
}
