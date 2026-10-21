<?php

namespace App\Support\Plans;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PlanPaymentRequest;
use App\Models\User;
use App\Support\Security\IdentifierHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PaymentRequestService
{
    public const MAX_PENDING = 2;

    private const TRX_HASH_DOMAIN = 'plan_payment_trx';

    public function __construct(
        private IdentifierHasher $hasher,
    ) {
    }

    public function createUnlimited(
        Business $business,
        User $submittedBy,
        int $months,
        string $trxId,
        string $payerNumber,
    ): PlanPaymentRequest {
        if (!PlanPackages::isValidMonths($months)) {
            throw new ApiException(
                'PLAN_PACKAGE_INVALID',
                'Choose a valid Unlimited package.',
                422,
            );
        }

        $trxId = $this->normalizeTrxId($trxId);

        if (!preg_match('/\A[A-Z0-9]{10}\z/D', $trxId)) {
            throw new ApiException(
                'PAYMENT_TRX_INVALID',
                'TrxID must contain exactly 10 letters and numbers.',
                422,
            );
        }

        $payerNumber = $this->normalizePayerNumber(
            $payerNumber,
        );

        if (!preg_match('/\A01[3-9]\d{8}\z/D', $payerNumber)) {
            throw new ApiException(
                'PAYMENT_PAYER_NUMBER_INVALID',
                'Enter the 11-digit Bangladesh mobile number used to make the payment.',
                422,
            );
        }

        $trxHash = $this->hasher->hash(
            self::TRX_HASH_DOMAIN,
            $trxId,
        );

        /*
         * Fast failure before taking the business lock.
         *
         * The database UNIQUE constraint remains the final protection
         * against two different businesses submitting the same TrxID
         * concurrently.
         */
        $this->assertTrxUnused($trxHash);

        try {
            return DB::transaction(function () use (
                $business,
                $submittedBy,
                $months,
                $trxId,
                $trxHash,
                $payerNumber,
            ): PlanPaymentRequest {
                /*
                 * Stable per-business mutex.
                 *
                 * This serializes the "maximum two pending requests"
                 * check so two devices cannot both pass it at once.
                 */
                $lockedBusiness = Business::query()
                    ->whereKey($business->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertCanSubmit(
                    $lockedBusiness,
                    $submittedBy,
                );

                $this->assertPendingSlotAvailable(
                    $lockedBusiness,
                );

                /*
                 * Re-check after taking the lock.
                 *
                 * Global database uniqueness still protects races
                 * involving different businesses.
                 */
                $this->assertTrxUnused($trxHash);

                return PlanPaymentRequest::create([
                    'business_id' => $lockedBusiness->id,
                    'submitted_by_user_id' => $submittedBy->id,
                    'beneficiary_user_id' => null,

                    'product_type' => PlanPaymentProduct::Unlimited,
                    'package_months' => $months,

                    /*
                     * Never accept this amount from Flutter.
                     */
                    'expected_amount_bdt' => PlanPackages::priceBdt(
                        $months,
                    ),

                    /*
                     * Model casts encrypt both sensitive plaintext
                     * values before they reach the database.
                     */
                    'trx_id' => $trxId,
                    'trx_id_hash' => $trxHash,
                    'payer_number' => $payerNumber,
                ])->refresh();
            });
        } catch (QueryException $e) {
            /*
             * The global trx_id_hash UNIQUE constraint handles the
             * cross-business concurrency race.
             */
            if (
                PlanPaymentRequest::query()
                    ->where('trx_id_hash', $trxHash)
                    ->exists()
            ) {
                throw $this->duplicateTrx();
            }

            throw $e;
        }
    }

    public function normalizeTrxId(string $value): string
    {
        return preg_replace(
            '/[^A-Z0-9]/',
            '',
            strtoupper($value),
        ) ?? '';
    }

    public function normalizePayerNumber(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        /*
         * Prototype behavior:
         * 8801712345678 -> 01712345678
         */
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        /*
         * 1712345678 -> 01712345678
         */
        if (
            strlen($digits) === 10
            && str_starts_with($digits, '1')
        ) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    private function assertCanSubmit(
        Business $business,
        User $user,
    ): void {
        $allowed = BusinessMembership::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', AccountStatus::Active->value)
            ->whereIn('role', [
                BusinessRole::Owner->value,
                BusinessRole::Administrator->value,
            ])
            ->exists();

        if (!$allowed) {
            throw new ApiException(
                'PLAN_PAYMENT_FORBIDDEN',
                'Only the account owner or administrator can submit a plan payment.',
                403,
            );
        }
    }

    private function assertPendingSlotAvailable(
        Business $business,
    ): void {
        $pending = PlanPaymentRequest::query()
            ->where('business_id', $business->id)
            ->where('status', PlanPaymentStatus::Pending->value)
            ->count();

        if ($pending >= self::MAX_PENDING) {
            throw new ApiException(
                'PLAN_PAYMENT_PENDING_LIMIT',
                'You already have 2 payments waiting for checking.',
                409,
                [
                    'limit' => self::MAX_PENDING,
                    'pending' => $pending,
                ],
            );
        }
    }

    private function assertTrxUnused(string $trxHash): void
    {
        if (
            PlanPaymentRequest::query()
                ->where('trx_id_hash', $trxHash)
                ->exists()
        ) {
            throw $this->duplicateTrx();
        }
    }

    private function duplicateTrx(): ApiException
    {
        return new ApiException(
            'PAYMENT_TRX_ALREADY_USED',
            'This TrxID was already submitted.',
            409,
        );
    }
}