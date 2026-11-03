<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ManagerSubscription;
use Illuminate\Contracts\View\View;

final class BusinessDetailsController extends Controller
{
    public function __invoke(Business $business): View
    {
        $business->load(['memberships' => fn ($query) => $query
            ->with('user:id,name,email')
            ->orderBy('id')]);

        return view('admin.business-details', [
            'business' => $business,
            'subscription' => ManagerSubscription::query()
                ->where('business_id', $business->id)->first(),
        ]);
    }
}