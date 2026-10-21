<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\PlanPaymentProvider;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PlatformAdmin;
use App\Models\PlatformPaymentProviderSetting;
use App\Support\Plans\BangladeshMobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class PaymentProviderSettingController extends Controller
{
    /**
     * Return the current manual bKash configuration.
     *
     * This endpoint is protected by:
     * - auth:sanctum
     * - platform.admin
     *
     * Tenant Owner / Administrator roles do not grant access.
     */
    public function showManualBkash(
        Request $request,
    ): JsonResponse {
        $this->platformAdmin($request);

        $setting = $this->manualBkashSetting();

        return ApiResponse::success(
            $this->manualBkashPayload($setting),
        );
    }

    /**
     * Update the manual bKash receiving account.
     *
     * Only a real Platform Admin may call this endpoint.
     *
     * Important:
     * - The receiving number is normalized server-side.
     * - Enabling requires a valid configured bKash number.
     * - Disabling does not erase an existing number unless the
     *   administrator explicitly submits null/empty bkash_number.
     * - The provider itself cannot be changed by request data.
     */
    public function updateManualBkash(
        Request $request,
    ): JsonResponse {
        $admin = $this->platformAdmin($request);

        $data = $request->validate([
            'is_enabled'   => [
                'required',
                'boolean',
            ],

            'bkash_number' => [
                'nullable',
                'string',
                'max:32',
            ],
        ]);

        /*
         * We must distinguish:
         *
         * 1. bkash_number omitted
         *    -> preserve the currently saved number.
         *
         * 2. bkash_number explicitly null/empty
         *    -> clear the currently saved number.
         *
         * 3. bkash_number supplied
         *    -> normalize and validate it.
         */
        $numberProvided = array_key_exists(
            'bkash_number',
            $data,
        );

        $number = null;

        if ($numberProvided) {
            $rawNumber = trim(
                (string) ($data['bkash_number'] ?? ''),
            );

            if ($rawNumber !== '') {
                $number = BangladeshMobileNumber::normalize(
                    $rawNumber,
                );

                if (
                    !BangladeshMobileNumber::isValid(
                        $number,
                    )
                ) {
                    throw ValidationException::withMessages([
                        'bkash_number' => [
                            'Enter a valid 11-digit Bangladesh bKash number.',
                        ],
                    ]);
                }
            }
        }

        $setting = DB::transaction(
            function () use (
                $admin,
                $data,
                $number,
                $numberProvided,
            ): PlatformPaymentProviderSetting {
                /*
                 * Lock the one authoritative manual-bKash row.
                 *
                 * This prevents two Platform Admin updates from
                 * racing against each other.
                 */
                $setting =
                PlatformPaymentProviderSetting::query()
                    ->where(
                        'provider',
                        PlanPaymentProvider::ManualBkash->value,
                    )
                    ->lockForUpdate()
                    ->first();

                if ($setting === null) {
                    throw new LogicException(
                        'Manual bKash payment provider setting is missing.'
                    );
                }

                /*
                 * If the request omitted bkash_number, preserve the
                 * existing receiving number.
                 *
                 * If it explicitly supplied null/empty, $number is
                 * null and the saved number will be cleared.
                 */
                $effectiveNumber = $numberProvided
                ? $number
                : $setting->public_account_identifier;

                /*
                 * Manual bKash must never be enabled without a valid
                 * receiving number.
                 */
                if (
                    (bool) $data['is_enabled']
                    && $effectiveNumber === null
                ) {
                    throw ValidationException::withMessages([
                        'bkash_number' => [
                            'A bKash receiving number is required when manual bKash payments are enabled.',
                        ],
                    ]);
                }

                $setting->forceFill([
                    'is_enabled'                   =>
                    (bool) $data['is_enabled'],

                    'public_account_identifier'    =>
                    $effectiveNumber,

                    'updated_by_platform_admin_id' =>
                    $admin->id,
                ])->save();

                return $setting->refresh();
            },
        );

        return ApiResponse::success(
            $this->manualBkashPayload($setting),
            'Manual bKash payment settings updated.',
        );
    }

    /**
     * Load the authoritative manual-bKash provider row.
     *
     * The migration creates this row. Missing data therefore means
     * the platform configuration is inconsistent and should not be
     * silently recreated by an HTTP request.
     */
    private function manualBkashSetting():
    PlatformPaymentProviderSetting {
        $setting =
        PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                PlanPaymentProvider::ManualBkash->value,
            )
            ->first();

        if ($setting === null) {
            throw new LogicException(
                'Manual bKash payment provider setting is missing.'
            );
        }

        return $setting;
    }

    /**
     * Build the public Platform Admin representation.
     *
     * No PIN, OTP, password, API key or gateway credential belongs
     * in this response.
     */
    private function manualBkashPayload(
        PlatformPaymentProviderSetting $setting,
    ): array {
        $number =
        $setting->public_account_identifier;

        return [
            'provider'     =>
            PlanPaymentProvider::ManualBkash->value,

            'is_enabled'   =>
            (bool) $setting->is_enabled,

            /*
             * "configured" means the provider is currently usable,
             * not merely that a receiving number is stored.
             */
            'configured'   =>
            (bool) $setting->is_enabled
            && $number !== null,

            'bkash_number' =>
            $number,

            'updated_at'   =>
            $setting->updated_at?->toISOString(),
        ];
    }

    /**
     * Retrieve the Platform Admin attached by platform.admin
     * middleware.
     *
     * Reaching this controller without that context indicates a
     * route/middleware configuration error.
     */
    private function platformAdmin(
        Request $request,
    ): PlatformAdmin {
        $admin = $request->attributes->get(
            'platform_admin',
        );

        if (!$admin instanceof PlatformAdmin) {
            throw new LogicException(
                'Platform administrator context is missing.'
            );
        }

        return $admin;
    }
}
