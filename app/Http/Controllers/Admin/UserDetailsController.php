<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SenderSubscription;
use App\Models\User;
use Illuminate\Contracts\View\View;

final class UserDetailsController extends Controller
{
    public function __invoke(User $user): View
    {
        $user->load(['memberships' => fn ($query) => $query->with('business:id,name')->orderBy('id')]);
        $senderSubscription = SenderSubscription::query()->where('user_id', $user->id)->first();
        return view('admin.user-details', ['profile' => $user, 'senderSubscription' => $senderSubscription]);
    }
}