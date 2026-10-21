<?php

namespace App\Http\Controllers\Api\V1\Plan;

use App\Enums\BusinessRole;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Enums\ReferralStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BusinessReferral;
use App\Models\ReferralReward;
use App\Support\Plans\ReferralRules;
use App\Support\Plans\ReferralService;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

final class ReferralController extends Controller
{
    public function show(
        Request $request,
        CurrentBusiness $current,
        ReferralService $referrals,
    ): JsonResponse {
        $business = $current->get();
        $membership = $current->membership();
        $user = $request->user();

        if (
            $business === null
            || $membership === null
            || $user === null
        ) {
            throw new LogicException(
                'Referral information requires an active business context.'
            );
        }

        $this->assertCanManageReferrals(
            $membership->role,
        );

        /*
         * profileFor() is server-owned and idempotent.
         * The client never generates its own invite code.
         */
        $profile = $referrals->profileFor(
            $business,
        );

        $claimedCount = BusinessReferral::query()
            ->where(
                'inviter_business_id',
                $business->id,
            )
            ->count();

        $qualifiedCount = BusinessReferral::query()
            ->where(
                'inviter_business_id',
                $business->id,
            )
            ->where(
                'status',
                ReferralStatus::Qualified->value,
            )
            ->count();

        $unlimitedRewardQuery = ReferralReward::query()
            ->where(
                'beneficiary_business_id',
                $business->id,
            )
            ->where(
                'reward_type',
                ReferralRewardType::UnlimitedDays->value,
            );

        $unlimitedRewardsEarned =
            (clone $unlimitedRewardQuery)->count();

        $unlimitedDaysEarned =
            (int) (clone $unlimitedRewardQuery)
                ->sum('unlimited_days');

        $discountQuery = ReferralReward::query()
            ->where(
                'beneficiary_business_id',
                $business->id,
            )
            ->where(
                'reward_type',
                ReferralRewardType::RenewalDiscountBdt->value,
            );

        $discountRewardsEarned =
            (clone $discountQuery)->count();

        $discountAvailable =
            (clone $discountQuery)
                ->where(
                    'status',
                    ReferralRewardStatus::Available->value,
                )
                ->count();

        $discountReserved =
            (clone $discountQuery)
                ->where(
                    'status',
                    ReferralRewardStatus::Reserved->value,
                )
                ->count();

        $discountApplied =
            (clone $discountQuery)
                ->where(
                    'status',
                    ReferralRewardStatus::Applied->value,
                )
                ->count();

        /*
         * A business may itself have been referred by another
         * business. Do not expose the inviter's internal database ID.
         */
        $claimedReferral = BusinessReferral::query()
            ->where(
                'referred_business_id',
                $business->id,
            )
            ->first();

        return ApiResponse::success([
            'invite_code' =>
                $profile->invite_code,

            'referrals' => [
                'claimed_count' =>
                    $claimedCount,

                'qualified_count' =>
                    $qualifiedCount,

                'maximum_qualifying' =>
                    ReferralRules::MAX_QUALIFYING_REFERRALS,
            ],

            'rewards' => [
                'unlimited_rewards_earned' =>
                    $unlimitedRewardsEarned,

                'unlimited_days_earned' =>
                    $unlimitedDaysEarned,

                'renewal_discount_bdt_each' =>
                    ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,

                'renewal_discount_rewards_earned' =>
                    $discountRewardsEarned,

                'renewal_discount_available' =>
                    $discountAvailable,

                'renewal_discount_reserved' =>
                    $discountReserved,

                'renewal_discount_applied' =>
                    $discountApplied,
            ],

            'claimed_referral' =>
                $claimedReferral === null
                    ? null
                    : $this->referralData(
                        $claimedReferral,
                    ),
        ]);
    }

    public function claim(
        Request $request,
        CurrentBusiness $current,
        ReferralService $referrals,
    ): JsonResponse {
        $business = $current->get();
        $membership = $current->membership();
        $user = $request->user();

        if (
            $business === null
            || $membership === null
            || $user === null
        ) {
            throw new LogicException(
                'Referral claim requires an active business context.'
            );
        }

        $this->assertCanManageReferrals(
            $membership->role,
        );

        $data = $request->validate([
            'invite_code' => [
                'required',
                'string',
                'max:32',
            ],
        ]);

        /*
         * ReferralService remains the authority for:
         *
         * - normalization;
         * - exact code validation;
         * - self-referral protection;
         * - one-inviter protection;
         * - idempotency;
         * - trial eligibility.
         *
         * This endpoint cannot qualify a referral or create a reward.
         */
        $referral = $referrals->claim(
            $business,
            $user,
            $data['invite_code'],
        );

        return ApiResponse::success(
            [
                'referral' =>
                    $this->referralData(
                        $referral,
                    ),
            ],
            'Invite code applied.',
        );
    }

    private function referralData(
        BusinessReferral $referral,
    ): array {
        return [
            'id' =>
                $referral->uuid,

            'status' =>
                $referral->status->value,

            'claimed_at' =>
                $referral->claimed_at?->toISOString(),

            'trial_granted_at' =>
                $referral
                    ->referred_trial_granted_at
                    ?->toISOString(),

            'qualified_at' =>
                $referral->qualified_at?->toISOString(),
        ];
    }

    private function assertCanManageReferrals(
        BusinessRole $role,
    ): void {
        if (!in_array(
            $role,
            [
                BusinessRole::Owner,
                BusinessRole::Administrator,
            ],
            true,
        )) {
            throw new ApiException(
                'REFERRAL_FORBIDDEN',
                'Only the business owner or administrator can manage referrals.',
                403,
            );
        }
    }
}