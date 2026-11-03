<?php
namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class BusinessesController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $search = mb_substr($search, 0, 100);
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'suspended'], true)) {
            $status = 'all';
        }

        $query = Business::query()->withCount('memberships');
        if ($search !== '') {
            $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('admin.businesses', [
            'businesses' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $status,
        ]);
    }
}