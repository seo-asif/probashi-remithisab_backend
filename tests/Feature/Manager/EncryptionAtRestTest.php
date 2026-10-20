<?php

namespace Tests\Feature\Manager;

use App\Models\Customer;
use App\Models\ManagerTransaction;
use App\Models\SendingNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class EncryptionAtRestTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_customer_sensitive_identifiers_are_encrypted_at_rest(): void
    {
        $this->actAs($this->makeBusiness());

        $customer = $this->customer([
            'phone'          => '+679 912 3456',
            'bkash_numbers'  => ['01712345678'],
            'nagad_numbers'  => ['01812345678'],
            'rocket_numbers' => ['019123456789'],
        ]);

        $raw = DB::table('customers')
            ->where('uuid', $customer['id'])
            ->first();

        $this->assertNotNull($raw);

        // Raw database values must not contain their plaintext forms.
        $this->assertNotSame('+6799123456', $raw->phone);
        $this->assertStringNotContainsString('+6799123456', $raw->phone);

        $this->assertStringNotContainsString(
            '01712345678',
            $raw->bkash_numbers
        );

        $this->assertStringNotContainsString(
            '01812345678',
            $raw->nagad_numbers
        );

        $this->assertStringNotContainsString(
            '019123456789',
            $raw->rocket_numbers
        );

        $this->assertStringNotContainsString(
            '1234567890',
            $raw->banks
        );

        // Eloquent must transparently decrypt the same database row.
        $model = Customer::withoutGlobalScopes()
            ->where('uuid', $customer['id'])
            ->firstOrFail();

        $this->assertSame('+6799123456', $model->phone);
        $this->assertSame(['01712345678'], $model->bkash_numbers);
        $this->assertSame(['01812345678'], $model->nagad_numbers);
        $this->assertSame(['019123456789'], $model->rocket_numbers);
        $this->assertSame(
            '1234567890',
            $model->banks[0]['account_number']
        );

       // Legacy plaintext phone_key storage must no longer exist.
$this->assertFalse(
    \Illuminate\Support\Facades\Schema::hasColumn('customers', 'phone_key')
);

        // Searchable HMAC lookup must still exist.
        $this->assertNotNull($raw->phone_hash);
        $this->assertSame(64, strlen($raw->phone_hash));
    }

    public function test_sending_number_is_encrypted_at_rest(): void
    {
        $this->actAs($this->makeBusiness());

        $number = $this->postJson('/api/v1/sending-numbers', [
            'number' => '017 1111 1111',
            'label'  => 'Shop bKash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.number', '01711111111')
            ->json('data');

        $raw = DB::table('sending_numbers')
            ->where('uuid', $number['id'])
            ->first();

        $this->assertNotNull($raw);
        $this->assertNotSame('01711111111', $raw->number);
        $this->assertStringNotContainsString('01711111111', $raw->number);

        $model = SendingNumber::withoutGlobalScopes()
            ->where('uuid', $number['id'])
            ->firstOrFail();

        $this->assertSame('01711111111', $model->number);

        $this->assertNotNull($raw->number_hash);
        $this->assertSame(64, strlen($raw->number_hash));
    }

    public function test_transaction_destination_snapshot_is_encrypted_at_rest(): void
    {
        $this->actAs($this->makeBusiness());

        $customer = $this->customer();

        /*
         * Bank transaction:
         * pay_to and bank snapshot must be encrypted.
         * sending_number is intentionally null for non-wallet methods.
         */
        $bankTransaction = $this->transaction($customer['id'], [
            'receive_method' => 'bank',
            'pay_to'         => '1234-567890',
        ]);

        $rawBank = DB::table('manager_transactions')
            ->where('uuid', $bankTransaction['id'])
            ->first();

        $this->assertNotNull($rawBank);
        $this->assertNotNull($rawBank->pay_to);
        $this->assertNotNull($rawBank->bank);
        $this->assertNull($rawBank->sending_number);

        // Raw database values must not expose the bank account.
        $this->assertStringNotContainsString(
            '1234567890',
            $rawBank->pay_to
        );

        $this->assertStringNotContainsString(
            '1234567890',
            $rawBank->bank
        );

        $bankModel = ManagerTransaction::withoutGlobalScopes()
            ->where('uuid', $bankTransaction['id'])
            ->firstOrFail();

        // Eloquent transparently decrypts the bank snapshot.
        $this->assertSame(
            '1234567890',
            $bankModel->bank['account_number']
        );

        $this->assertNotNull($rawBank->destination_hash);
        $this->assertSame(64, strlen($rawBank->destination_hash));

        /*
         * Wallet transaction:
         * sending_number must be encrypted separately because the application
         * intentionally does not store it for bank transactions.
         */
        $walletTransaction = $this->transaction($customer['id'], [
            'receive_method' => 'Bkash',
            'pay_to'         => '01712345678',
            'sending_number' => '01711111111',
        ]);

        $rawWallet = DB::table('manager_transactions')
            ->where('uuid', $walletTransaction['id'])
            ->first();

        $this->assertNotNull($rawWallet);
        $this->assertNotNull($rawWallet->pay_to);
        $this->assertNotNull($rawWallet->sending_number);

        // Neither wallet destination may appear as plaintext at rest.
        $this->assertStringNotContainsString(
            '01712345678',
            $rawWallet->pay_to
        );

        $this->assertStringNotContainsString(
            '01711111111',
            $rawWallet->sending_number
        );

        $walletModel = ManagerTransaction::withoutGlobalScopes()
            ->where('uuid', $walletTransaction['id'])
            ->firstOrFail();

        // Eloquent transparently decrypts both values.
        $this->assertSame(
            '01712345678',
            $walletModel->pay_to
        );

        $this->assertSame(
            '01711111111',
            $walletModel->sending_number
        );

        $this->assertNotNull($rawWallet->destination_hash);
        $this->assertSame(64, strlen($rawWallet->destination_hash));
    }
}
