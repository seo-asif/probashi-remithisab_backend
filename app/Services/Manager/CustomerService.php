<?php

namespace App\Services\Manager;

use App\Exceptions\ApiException;
use App\Http\Resources\Manager\CustomerResource;
use App\Models\Customer;
use App\Models\ManagerTransaction;
use App\Support\Manager\ManagerOptions;
use App\Support\Plans\PlanQuotaService;
use App\Support\Records\RecordId;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Customer rules (spec 6.2 and 6.3):
 *  1. an ACTIVE customer with the same number  -> 409 DUPLICATE_CUSTOMER (the app opens that customer)
 *  2. a customer with the same number deleted in the last 30 days -> 409 RESTORE_AVAILABLE (the app asks
 *     "Restore the previous customer?"; Cancel creates nothing)
 *  3. otherwise the customer is created
 * Numbers match by key: digits only, a leading 880 becomes 0. Only the current business is ever searched.
 * Phase 4 adds the active-customer limit check inside the same database transaction (create and restore).
 */
final class CustomerService
{
    public function __construct(
        private CurrentBusiness $current,
        private CustomerIdentifierLookupService $identifierLookup,
        private PlanQuotaService $quota,
    ) {
    }

    /** @return array{0: Customer, 1: bool} the customer, and true when it was newly created */
    public function create(array $data): array
    {
        if ($existing = RecordId::existing(Customer::class, $data['id'] ?? null)) {
            return [$existing, false]; // the same record sent again (offline retry)
        }

        $phoneHash = $this->identifierLookup->phoneHash(
            $data['phone'] ?? null
        );

        if ($phoneHash !== null) {
            $this->assertNoActiveDuplicate($phoneHash);
            $this->assertNoRecentlyDeleted($phoneHash);
        }

        try {
            $customer = DB::transaction(function () use ($data) {
                $business = $this->current->get();

                if ($business === null) {
                    throw new \LogicException(
                        'Customer creation requires a current business.'
                    );
                }

                $this->quota->assertCustomerAvailable($business);

                $customer = Customer::create([
                     ...$this->attributes($data),
                    'uuid'    => RecordId::make($data['id'] ?? null),
                    'user_id' => $this->current->userId(),
                ]);

                $this->identifierLookup->sync($customer);

                return $customer;
            });
        } catch (QueryException $e) {
            // another phone saved the same number at the same moment
            if ($phoneHash !== null) {
                $this->assertNoActiveDuplicate($phoneHash);
            }
        }

        return [$customer->refresh(), true];
    }

    public function update(Customer $customer, array $data): Customer
    {
        if (array_key_exists('phone', $data)) {
            $phoneHash = $this->identifierLookup->phoneHash(
                $data['phone']
            );

            if (
                $phoneHash !== null
                && $phoneHash !== $customer->phone_hash
            ) {
                $this->assertNoActiveDuplicate(
                    $phoneHash,
                    $customer->id
                );
            }
        }

        try {
            DB::transaction(function () use ($customer, $data) {
                $customer->fill(
                    $this->attributes($data, partial: true)
                );

                if ($customer->isDirty()) {
                    $customer->save();
                }

                $this->identifierLookup->sync($customer);
            });
        } catch (QueryException $e) {
            if ($customer->phone_hash !== null) {
                $this->assertNoActiveDuplicate(
                    $customer->phone_hash,
                    $customer->id
                );
            }

            throw $e;
        }

        return $customer->refresh();
    }

    /**
     * Archive a customer without deleting linked transaction history.
     * Archiving never frees a Free customer-creation allowance.
     */
    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    public function restore(Customer $customer): Customer
    {
        if (!$customer->trashed()) {
            return $customer; // already active: restoring twice is harmless
        }

        if ($customer->phone_hash !== null) {
            $this->assertNoActiveDuplicate(
                $customer->phone_hash,
                $customer->id
            );
        }

        DB::transaction(function () use ($customer) {
            // Restoration is not creation: the profile occupied its original
            // lifetime allowance even while archived, including after downgrade.
            $customer->restore();
            $this->identifierLookup->sync($customer);
        });

        return $customer->refresh();
    }

    private function assertNoActiveDuplicate(
        string $phoneHash,
        ?int $ignoreId = null
    ): void {
        $match = Customer::query()
            ->where('phone_hash', $phoneHash)
            ->when(
                $ignoreId !== null,
                fn($q) => $q->whereKeyNot($ignoreId)
            )
            ->first();

        if ($match) {
            throw new ApiException(
                'DUPLICATE_CUSTOMER',
                'A customer with this contact number already exists: ' . $match->name,
                409,
                [
                    'customer' => CustomerResource::brief($match),
                ]
            );
        }
    }

    private function assertNoRecentlyDeleted(string $phoneHash): void
    {
        $match = Customer::onlyTrashed()
            ->where('phone_hash', $phoneHash)
            ->where('deleted_at', '>=', now()->subDays(ManagerOptions::RESTORE_DAYS))
            ->orderByDesc('deleted_at')
            ->first();

        if ($match) {
            throw new ApiException(
                'RESTORE_AVAILABLE',
                'This customer was deleted recently. Would you like to restore the previous customer and their transaction history?',
                409,
                [
                    'customer' => CustomerResource::brief($match),
                ]
            );
        }
    }

    private function attributes(array $data, bool $partial = false): array
    {
        $out = [];

        foreach (['name', 'address', 'notes', 'attention', 'bkash_numbers', 'nagad_numbers', 'rocket_numbers', 'banks'] as $field) {
            if (!$partial || array_key_exists($field, $data)) {
                $value = $data[$field] ?? null;
                $out[$field] = match ($field) {
                    'attention' => (bool) $value,
                    'bkash_numbers', 'nagad_numbers', 'rocket_numbers', 'banks' => array_values($value ?? []),
                    default     => $value,
                };
            }
        }

        if (!$partial || array_key_exists('phone', $data)) {
            $phone = $data['phone'] ?? null;

            $out['phone'] = $phone;
            $out['phone_hash'] = $this->identifierLookup->phoneHash($phone);
        }

        return $out;
    }
}
