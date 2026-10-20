<?php

namespace App\Services\Manager;

use App\Models\Customer;
use App\Support\Security\IdentifierHasher;
use App\Support\Security\IdentifierNormalizer;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CustomerIdentifierLookupService
{
    public function __construct(
        private IdentifierHasher $hasher,
        private CurrentBusiness $currentBusiness,
    ) {

    }

    public function phoneHash(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return $this->hasher->hash(
            'customer_phone',
            IdentifierNormalizer::customerPhone($phone)
        );
    }

    /**
     * Synchronize wallet and bank account lookup hashes.
     *
     * Call inside the same database transaction that saves
     * the customer.
     */
    public function sync(Customer $customer): void
    {
        if (!$customer->exists ||
            $customer->id === null ||
            $customer->business_id === null) {
            throw new LogicException(
                'Customer must be persisted before syncing identifiers.'
            );
        }

        if (
            !$this->currentBusiness->isUnscoped()
            && (
                $this->currentBusiness->id() === null
                || (int) $customer->business_id !== (int) $this->currentBusiness->id()
            )
        ) {
            throw new LogicException(
                'Customer does not belong to the current business.'
            );
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Identifier synchronization requires a database transaction.'
            );
        }

        $rows = [];
        $businessId = $customer->business_id;
        $customerId = $customer->getKey();

        $walletFields = [
            'bkash_numbers'  => 'wallet_bkash',
            'nagad_numbers'  => 'wallet_nagad',
            'rocket_numbers' => 'wallet_rocket',
        ];

        foreach ($walletFields as $field => $domain) {
            foreach ($customer->{$field} ?? [] as $number) {
                $normalized = IdentifierNormalizer::wallet($number);
                $hash = $this->hasher->hash($domain, $normalized);

                $key = $domain . ':' . $hash;

                $rows[$key] = [
                    'business_id'     => $businessId,
                    'customer_id'     => $customerId,
                    'type'            => $domain,
                    'identifier_hash' => $hash,
                    'created_at'      => now(),
                ];
            }
        }

        foreach ($customer->banks ?? [] as $bank) {
            $number = $bank['account_number'] ?? null;

            if (!is_string($number) || $number === '') {
                continue;
            }

            $normalized = IdentifierNormalizer::bankAccount($number);
            $hash = $this->hasher->hash('bank_account', $normalized);

            $rows['bank_account:' . $hash] = [
                'business_id'     => $businessId,
                'customer_id'     => $customerId,
                'type'            => 'bank_account',
                'identifier_hash' => $hash,
                'created_at'      => now(),
            ];
        }

        DB::table('customer_identifier_lookups')
            ->where('business_id', $businessId)
            ->where('customer_id', $customerId)
            ->delete();

        if ($rows !== []) {
            DB::table('customer_identifier_lookups')
                ->insert(array_values($rows));
        }
    }
}
