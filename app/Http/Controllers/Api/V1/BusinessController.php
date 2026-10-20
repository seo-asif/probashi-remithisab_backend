<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Responses\ApiResponse;
use App\Support\Sender\SenderOptions;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PATCH /business: the business name, timezone and default currency. Owner or administrator only.
 * Changing the timezone changes how EVERY user of the business sees times and month boundaries.
 * Stored times are UTC instants, so nothing is rewritten: the same moment is simply shown in the new timezone.
 */
class BusinessController extends Controller
{
    public function update(Request $request, CurrentBusiness $current): JsonResponse
    {
        $role = $current->membership()?->role;

        if (!in_array($role, [BusinessRole::Owner, BusinessRole::Administrator], true)) {
            throw new ApiException('FORBIDDEN', 'Only the business owner can change these settings.', 403);
        }

        if ($request->has('default_currency') && is_string($request->input('default_currency'))) {
            $request->merge(['default_currency' => strtoupper(trim($request->input('default_currency')))]);
        }

        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'min:2', 'max:120', new \App\Rules\PlainText()],
            'timezone'         => ['sometimes', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'default_currency' => ['sometimes', Rule::in(SenderOptions::CURRENCIES)],
        ], [
            'timezone.in'         => 'Choose a valid timezone, for example Asia/Dhaka or Pacific/Fiji.',
            'default_currency.in' => 'Choose a currency from the list.',
        ]);

        $business = $current->get();
        $business->fill($data)->save();

        return ApiResponse::success(BusinessResource::make($business->refresh())->resolve($request), 'Business settings saved.');
    }
}
