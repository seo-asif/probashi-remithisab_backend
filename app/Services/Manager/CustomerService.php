<?php

namespace App\Services\Manager;

use App\Exceptions\ApiException;
use App\Http\Resources\Manager\CustomerResource;
use App\Models\Customer;
use App\Models\ManagerTransaction;
use App\Support\Records\RecordId;
use App\Support\Manager\ManagerOptions;
use App\Support\Manager\ManagerValidation;
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
    public function __construct(private CurrentBusiness $current)
    {
    }

    /** @return array{0: Customer, 1: bool} the customer, and true when it was newly created */
    public function create(array $data): array
    {
        if ($existing = RecordId::existing(Customer::class, $data['id'] ?? null)) {
            return [$existing, false];   // the same record sent again (offline retry)
        }

        $key = ManagerValidation::phoneKey($data['phone'] ?? null);

        if ($key !== null) {
            $this->assertNoActiveDuplicate($key);
            $this->assertNoRecentlyDeleted($key);
        }

        try {
            $customer = DB::transaction(fn () => Customer::create([
                ...$this->attributes($data),
                'uuid'    => RecordId::make($data['id'] ?? null),
                'user_id' => $this->current->userId(),
            ]));
        } catch (QueryException $e) {
            // another phone saved the same number at the same moment
            if ($key !== null) {
                $this->assertNoActiveDuplicate($key);
            }
            throw $e;
        }

        return [$customer->refresh(), true];
    }

    public function update(Customer $customer, array $data): Customer
    {
        if (array_key_exists('phone', $data)) {
            $key = ManagerValidation::phoneKey($data['phone']);

            if ($key !== null && $key !== $customer->phone_key) {
                $this->assertNoActiveDuplicate($key, $customer->id);
            }
        }

        $customer->fill($this->attributes($data, partial: true));

        if ($customer->isDirty()) {
            try {
                $customer->save();
            } catch (QueryException $e) {
                $this->assertNoActiveDuplicate((string) $customer->phone_key, $customer->id);
                throw $e;
            }
        }

        return $customer->refresh();
    }

    /** The app's rule: a customer with records cannot be deleted. Delete the records first. */
    public function delete(Customer $customer): void
    {
        $count = ManagerTransaction::query()->where('customer_id', $customer->id)->count();

        if ($count > 0) {
            throw new ApiException('CUSTOMER_HAS_RECORDS', "This customer has {$count} record" . ($count > 1 ? 's' : '') . '. Delete those records first.', 409, ['records' => $count]);
        }

        $customer->delete();
    }

    public function restore(Customer $customer): Customer
    {
        if (!$customer->trashed()) {
            return $customer;   // already active: restoring twice is harmless
        }

        if ($customer->phone_key !== null) {
            $this->assertNoActiveDuplicate($customer->phone_key, $customer->id);
        }

        DB::transaction(fn () => $customer->restore());

        return $customer->refresh();
    }

    private function assertNoActiveDuplicate(string $key, ?int $ignoreId = null): void
    {
        $match = Customer::query()
            ->where('phone_key', $key)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($match) {
            throw new ApiException('DUPLICATE_CUSTOMER', 'A customer with this contact number already exists: ' . $match->name, 409, [
                'customer' => CustomerResource::brief($match),
            ]);
        }
    }

    private function assertNoRecentlyDeleted(string $key): void
    {
        $match = Customer::onlyTrashed()
            ->where('phone_key', $key)
            ->where('deleted_at', '>=', now()->subDays(ManagerOptions::RESTORE_DAYS))
            ->orderByDesc('deleted_at')
            ->first();

        if ($match) {
            throw new ApiException('RESTORE_AVAILABLE', 'This customer was deleted recently. Would you like to restore the previous customer and their transaction history?', 409, [
                'customer' => CustomerResource::brief($match),
            ]);
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
            $out['phone']     = $data['phone'] ?? null;
            $out['phone_key'] = ManagerValidation::phoneKey($data['phone'] ?? null);
        }

        return $out;
    }
}
