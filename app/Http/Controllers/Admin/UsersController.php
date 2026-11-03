<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class UsersController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        if (mb_strlen($search) > 100) {
            $search = mb_substr($search, 0, 100);
        }
        if (! in_array($status, ['all', 'active', 'suspended'], true)) {
            $status = 'all';
        }

        $users = User::query()
            ->select(['id', 'name', 'email', 'status', 'email_verified_at', 'created_at'])
            ->when($search !== '', function ($query) use ($search): void {
                $escaped = addcslashes($search, '%_\\');
                $query->where(function ($q) use ($escaped): void {
                    $q->where('name', 'like', '%'.$escaped.'%')
                      ->orWhere('email', 'like', '%'.$escaped.'%');
                });
            })
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users', compact('users', 'search', 'status'));
    }
}