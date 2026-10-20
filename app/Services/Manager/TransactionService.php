<?php

namespace App\Services\Manager;

use App\Exceptions\ApiException;
use App\Models\BdtPayment;
use App\Models\Customer;
use App\Models\ManagerTransaction;
use App\Support\Manager\ManagerCalculator;
use App\Support\Manager\ManagerOptions as O;
use App\Support\Manager\ManagerValidation;
use App\Support\Money\Dec;
use App\Support\Money\MoneyField;
use App\Support\Records\MoneyAudit;
use App\Support\Records\RecordId;
use App\Support\Records\RecordTime;
use App\Support\Security\IdentifierHasher;
use App\Support\Security\IdentifierNormalizer;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Validation\Reject;
use Illuminate\Support\Facades\DB;

/**
 * Manager customer transactions and their BDT payments (Money Rules 3.1 and 3.2).
 *
 * The status follows the money, it never overwrites it:
 *   currency_pending   paid must be 0 (moving here removes every payment)
 *   currency_received  0 <= paid < obligation
 *   bdt_sent           paid = obligation (choosing it by hand adds ONE automatic payment for the rest;
 *                      the payment that reaches the obligation switches to it by itself)
 * Moving back from bdt_sent removes every payment. Removed payments are soft-deleted and logged.
 * A wrong payment is corrected by a reversal entry (negative amount), never edited.
 * Every change runs in one database transaction with its audit row. Phase 4 adds plan limits here.
 */
final class TransactionService
{
    public function __construct(
        private CurrentBusiness $current,
        private IdentifierHasher $identifierHasher,
    ) {
    }

    // ------------------------------------------------------------------ transactions

    /** @return array{0: ManagerTransaction, 1: bool} */
    public function create(array $input): array
    {
        if ($existing = RecordId::existing(ManagerTransaction::class, $input['id'] ?? null)) {
            return [$existing, false];
        }

        $input['currency'] ??= $this->current->get()->default_currency ?? 'FJD';
        $input['status'] ??= O::STATUS_RECEIVED;
        $values = $this->checked($input, null);
        $status = $values['status'];

        $tx = DB::transaction(function () use ($input, $values, $status) {
            // "sent" at creation = received first, then one automatic payment for the full obligation
            $tx = ManagerTransaction::create([ ...$values, 'status' => $status === O::STATUS_SENT ? O::STATUS_RECEIVED : $status, 'paid_bdt' => 0, 'uuid' => RecordId::make($input['id'] ?? null), 'user_id' => $this->current->userId()]);
            MoneyAudit::record('created', $tx, [], MoneyAudit::values($tx));
            if ($status === O::STATUS_SENT) {
                $this->moveTo($tx, $status, explicit: true);
            }

            return $tx;
        });

        return [$tx->refresh(), true];
    }

    public function update(ManagerTransaction $tx, array $input): ManagerTransaction
    {
        $values = $this->checked($input, $tx);
        $explicit = array_key_exists('status', $input) && $input['status'] !== $tx->status;
        $paid = Dec::of($tx->paid_bdt);

        // M8: the new total can never be below what is already paid
        if (bccomp($values['obligation_bdt'], $paid, 2) < 0 && !in_array($values['status'], [O::STATUS_PENDING], true) && !($explicit && $tx->status === O::STATUS_SENT)) {
            Reject::fail(['amount' => ['below_paid', "{$paid} BDT has already been paid. The total cannot be less than that."]]);
        }

        return DB::transaction(function () use ($tx, $values, $explicit) {
            $old = MoneyAudit::values($tx);
            $target = $values['status'];
            unset($values['status']);

            $tx->fill($values)->save();
            MoneyAudit::record('updated', $tx, $old, MoneyAudit::values($tx));

            $explicit ? $this->moveTo($tx, $target, explicit : true): $this->followMoney($tx);

            return $tx->refresh();
        });
    }

    /** The quick status change on the card. */
    public function changeStatus(ManagerTransaction $tx, string $status): ManagerTransaction
    {
        if ($tx->status === $status) {
            return $tx;
        }

        return DB::transaction(function () use ($tx, $status) {
            $this->moveTo($tx, $status, explicit: true);

            return $tx->refresh();
        });
    }

    public function delete(ManagerTransaction $tx): void
    {
        DB::transaction(function () use ($tx) {
            $tx->delete(); // payments stay with it, so a restore brings everything back
            MoneyAudit::record('deleted', $tx);
        });
    }

    public function restore(ManagerTransaction $tx): ManagerTransaction
    {
        if (!$tx->trashed()) {
            return $tx;
        }

        if ($tx->customer === null || $tx->customer->trashed()) {
            throw new ApiException('CUSTOMER_DELETED', 'The customer of this transaction was deleted. Restore the customer first.', 409);
        }

        DB::transaction(function () use ($tx) {
            $tx->restore();
            MoneyAudit::record('restored', $tx);
        });

        return $tx->refresh();
    }

    // ------------------------------------------------------------------ payments

    /** "+ টাকা পাঠালাম": one payment entry. @return array{0: BdtPayment, 1: bool} */
    public function addPayment(ManagerTransaction $tx, array $input): array
    {
        if ($existing = RecordId::existing(BdtPayment::class, $input['id'] ?? null)) {
            return [$existing, false];
        }

        $owed = bcsub(Dec::of($tx->obligation_bdt), Dec::of($tx->paid_bdt), 2);

        if ($tx->status === O::STATUS_PENDING) {
            Reject::fail(['amount_bdt' => ['customer_not_paid', 'The customer has not paid yet. Mark the money as received first.']]);
        }
        if ($error = MoneyField::check($input['amount_bdt'] ?? null, 'amount_bdt', 'the taka paid', O::BDT_MAX)) {
            Reject::fail(['amount_bdt' => $error]);
        }
        if (bccomp(Dec::of($input['amount_bdt']), $owed, 2) > 0) {
            Reject::fail(['amount_bdt' => ['payment_too_much', "This is more than the {$owed} BDT still to pay."]]);
        }

        $paidOn = $input['paid_on'] ?? $this->today();
        if ($error = RecordTime::date($paidOn, $this->timezone())) {
            Reject::fail(['paid_on' => $error]);
        }

        $payment = DB::transaction(function () use ($tx, $input, $paidOn) {
            $payment = $this->insertPayment($tx, Dec::of($input['amount_bdt']), $paidOn, false, null, $input['note'] ?? null, $input['id'] ?? null);
            MoneyAudit::record('payment_added', $tx, [], [], ['payment' => $payment->uuid, 'amount_bdt' => $payment->amount_bdt]);
            $this->recalculatePaid($tx);
            $this->followMoney($tx);

            return $payment;
        });

        return [$payment->refresh(), true];
    }

    /** A wrong payment is corrected by a reversal entry with the negative amount. */
    public function reversePayment(ManagerTransaction $tx, BdtPayment $payment, array $input): BdtPayment
    {
        if ((int) $payment->transaction_id !== (int) $tx->id) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(BdtPayment::class);
        }
        if (bccomp(Dec::of($payment->amount_bdt), '0', 2) <= 0) {
            Reject::fail(['payment' => ['cannot_reverse_reversal', 'A reversal cannot be reversed.']]);
        }
        if (BdtPayment::query()->where('reverses_payment_id', $payment->id)->exists()) {
            Reject::fail(['payment' => ['already_reversed', 'This payment is already reversed.']]);
        }

        return DB::transaction(function () use ($tx, $payment, $input) {
            $reversal = $this->insertPayment($tx, bcmul(Dec::of($payment->amount_bdt), '-1', 2), $this->today(), false, $payment->id, $input['note'] ?? null, $input['id'] ?? null);
            MoneyAudit::record('payment_reversed', $tx, [], [], ['payment' => $payment->uuid, 'reversal' => $reversal->uuid, 'amount_bdt' => $reversal->amount_bdt]);
            $this->recalculatePaid($tx);
            $this->followMoney($tx);

            return $reversal->refresh();
        });
    }

    // ------------------------------------------------------------------ status rules

    /** A status chosen by the person. */
    private function moveTo(ManagerTransaction $tx, string $target, bool $explicit): void
    {
        $from = $tx->status;

        if ($target === O::STATUS_PENDING || ($target === O::STATUS_RECEIVED && $from === O::STATUS_SENT && $explicit)) {
            $this->removePayments($tx, "status {$from} -> {$target}");
        }

        if ($target === O::STATUS_SENT) {
            $owed = bcsub(Dec::of($tx->obligation_bdt), Dec::of($tx->paid_bdt), 2);
            if (bccomp($owed, '0', 2) > 0) {
                $this->insertPayment($tx, $owed, $this->localDate($tx), true, null, null, null);
                MoneyAudit::record('payment_added', $tx, [], [], ['auto' => true, 'amount_bdt' => $owed]);
                $this->recalculatePaid($tx);
            }
        }

        $this->setStatus($tx, $target);
    }

    /** After a payment or an edit: the status follows the paid total. Pending stays pending. */
    private function followMoney(ManagerTransaction $tx): void
    {
        if ($tx->status === O::STATUS_PENDING) {
            return;
        }

        $paid = Dec::of($tx->paid_bdt);
        $obligation = Dec::of($tx->obligation_bdt);
        $target = bccomp($paid, '0', 2) > 0 && bccomp($paid, $obligation, 2) === 0 ? O::STATUS_SENT : O::STATUS_RECEIVED;

        $this->setStatus($tx, $target);
    }

    private function setStatus(ManagerTransaction $tx, string $status): void
    {
        if ($tx->status !== $status) {
            $old = $tx->status;
            $tx->status = $status;
            $tx->save();
            MoneyAudit::record('status_changed', $tx, ['status' => $old], ['status' => $status]);
        }
    }

    private function removePayments(ManagerTransaction $tx, string $reason): void
    {
        $removed = BdtPayment::query()->where('transaction_id', $tx->id)->get();

        if ($removed->isEmpty()) {
            return;
        }

        BdtPayment::query()->whereKey($removed->modelKeys())->delete();
        MoneyAudit::record('payments_removed', $tx, [], [], ['reason' => $reason, 'payments' => $removed->map(fn($p) => ['id' => $p->uuid, 'amount_bdt' => $p->amount_bdt])->all()]);
        $this->recalculatePaid($tx);
    }

    private function insertPayment(ManagerTransaction $tx, string $amount, string $paidOn, bool $auto, ?int $reverses, ?string $note, ?string $id): BdtPayment
    {
        return BdtPayment::create([
            'uuid'                => RecordId::make($id),
            'user_id'             => $this->current->userId(),
            'transaction_id'      => $tx->id,
            'amount_bdt'          => $amount,
            'paid_on'             => $paidOn,
            'auto'                => $auto,
            'reverses_payment_id' => $reverses,
            'note'                => $note,
        ]);
    }

    private function recalculatePaid(ManagerTransaction $tx): void
    {
        $sum = BdtPayment::query()->where('transaction_id', $tx->id)->sum('amount_bdt');
        $tx->paid_bdt = Dec::of((string) $sum);
        $tx->save();
    }

    // ------------------------------------------------------------------ checks

    /** Validates the FINAL record and returns the columns to store, with the server's calculation. */
    private function checked(array $input, ?ManagerTransaction $stored): array
    {
        $pick = fn(string $k) => array_key_exists($k, $input) ? $input[$k] : $stored?->{ $k};
        $errors = [];

        // customer
        $customer = $stored?->customer;
        if (array_key_exists('customer_id', $input) || !$stored) {
            $customer = is_string($input['customer_id'] ?? null) ? Customer::query()->where('uuid', $input['customer_id'])->first() : null;
            if (!$customer) {
                $errors['customer_id'] = ['customer_required', 'Select a customer first.'];
            }
        }

        $method = $pick('receive_method');
        if (!in_array($method, O::RECEIVE_METHODS, true)) {
            $errors['receive_method'] = ['receive_method_invalid', 'Choose how the money is paid: Bkash, Nagad, Rocket, Bank, Cash or Other Payment Method.'];
        }
        $status = $pick('status');
        if (!in_array($status, O::STATUSES, true)) {
            $errors['status'] = ['status_invalid', 'Choose a status: customer has not paid, money received, or taka sent.'];
        }
        $currency = $pick('currency');
        if (!in_array($currency, \App\Support\Sender\SenderOptions::CURRENCIES, true)) {
            $errors['currency'] = ['currency_invalid', 'Choose a currency from the list.'];
        }

        foreach ([['amount', 'an amount', O::AMOUNT_MAX], ['rate', 'an exchange rate', O::RATE_MAX]] as [$f, $label, $max]) {
            if ($e = MoneyField::check($pick($f), $f, $label, $max)) {
                $errors[$f] = $e;
            }
        }
        foreach ([['commission', 'a commission'], ['withdrawal_charge', 'a withdrawal charge']] as [$f, $label]) {
            if ($e = MoneyField::check($pick($f), $f, $label, O::BDT_MAX, required: false, positive: false)) {
                $errors[$f] = $e;
            }
        }

        $wallet = is_string($method) && ManagerCalculator::isWallet($method);
        $withdrawal = $wallet ? $pick('withdrawal_charge') : 0;
        $sending = $wallet ? $pick('sending_number') : null;

        if (!isset($errors['amount']) && !isset($errors['commission'])) {
            $max = Dec::percentOf(Dec::of($pick('amount')), O::COMMISSION_MAX_PERCENT);
            if (bccomp(Dec::of($pick('commission')), $max, 8) > 0) {
                $errors['commission'] = ['commission_too_high', 'Commission cannot be more than 20% of the amount (' . Dec::round($max) . ' ' . $currency . ').'];
            }
        }

        $calc = null;
        if (!isset($errors['amount']) && !isset($errors['rate']) && !isset($errors['receive_method'])) {
            $calc = ManagerCalculator::transaction($pick('amount'), $pick('rate'), $method, isset($errors['withdrawal_charge']) ? 0 : $withdrawal, isset($errors['commission']) ? 0 : $pick('commission'));
            if ($wallet && !isset($errors['withdrawal_charge'])) {
                $max = Dec::percentOf($calc['base_bdt'], O::WITHDRAWAL_MAX_PERCENT);
                if (bccomp(Dec::of($withdrawal), $max, 8) > 0) {
                    $errors['withdrawal_charge'] = ['withdrawal_too_high', 'Withdrawal charge cannot be more than 20% of the converted taka (' . Dec::round($max) . ' BDT).'];
                }
            }
            if (bccomp($calc['obligation_bdt'], O::BDT_MAX, 2) > 0) {
                $errors['amount'] = ['result_out_of_range', 'The taka to send must be at most 1,000,000,000 BDT.'];
            }
            // the app's own calculation must match the server's
            if (isset($input['obligation_bdt']) && $input['obligation_bdt'] !== '' && bccomp(Dec::of($input['obligation_bdt']), $calc['obligation_bdt'], 2) !== 0) {
                $errors['obligation_bdt'] = ['calc_mismatch', "The taka to send should be {$calc['obligation_bdt']} BDT. Please update the app."];
            }
        }

        if ($sending !== null && $sending !== '' && ($m = ManagerValidation::sendingNumberError((string) $sending)) !== null) {
            $errors['sending_number'] = ['sending_number_invalid', $m];
        }

        [$payTo, $bank] = [null, null];
        if ($customer && is_string($method) && !isset($errors['receive_method'])) {
            [$payTo, $bank, $payError] = $this->payTarget($customer, $method, $input, $stored);
            if ($payError) {
                $errors['pay_to'] = $payError;
            }
        }

        $destinationHash = null;

        if (!isset($errors['pay_to']) && is_string($method)) {
            $destinationHash = match ($method) {
                'Bkash'  => is_string($payTo)
                ? $this->identifierHasher->hash(
                    'wallet_bkash',
                    IdentifierNormalizer::wallet($payTo)
                )
                : null,

                'Nagad'  => is_string($payTo)
                ? $this->identifierHasher->hash(
                    'wallet_nagad',
                    IdentifierNormalizer::wallet($payTo)
                )
                : null,

                'Rocket' => is_string($payTo)
                ? $this->identifierHasher->hash(
                    'wallet_rocket',
                    IdentifierNormalizer::wallet($payTo)
                )
                : null,

                'Bank'   => is_string($bank['account_number'] ?? null)
                ? $this->identifierHasher->hash(
                    'bank_account',
                    IdentifierNormalizer::bankAccount(
                        $bank['account_number']
                    )
                )
                : null,

                default  => null,
            };
        }

        // date and time: UTC plus the timezone it was entered in
        $when = $stored?->occurred_at;
        $zone = $stored?->timezone ?? $this->timezone();
        if (array_key_exists('occurred_at', $input) || !$stored) {
            [$when, $zone, $e] = RecordTime::parse($input['occurred_at'] ?? null, $input['timezone'] ?? null, $this->timezone());
            if ($e) {
                $errors[$e[0] === 'timezone_invalid' ? 'timezone' : 'occurred_at'] = $e;
            }
        }

        if ($errors) {
            Reject::fail($errors);
        }

        return [
            'customer_id'       => $customer->id,
            'customer_name'     => $customer->name,
            'currency'          => $currency,
            'amount'            => Dec::of($pick('amount')),
            'rate'              => Dec::of($pick('rate')),
            'commission'        => Dec::of($pick('commission')),
            'receive_method'    => $method,
            'pay_to'            => $payTo,
            'destination_hash'  => $destinationHash,
            'bank'              => $bank,
            'withdrawal_charge' => $calc['withdrawal_charge'],
            'sending_number'    => $sending ?: null,
            'status'            => $status,
            'base_bdt'          => $calc['base_bdt'],
            'obligation_bdt'    => $calc['obligation_bdt'],
            'occurred_at'       => $when,
            'timezone'          => $zone,
        ];
    }

    /** @return array{0: ?string, 1: ?array, 2: ?array} [pay_to, bank snapshot, error] — an unchanged target stays valid. */
    private function payTarget(Customer $customer, string $method, array $input, ?ManagerTransaction $stored): array
    {
        if (in_array($method, O::NO_TARGET_METHODS, true)) {
            return [null, null, null];
        }

        $same = $stored && $stored->receive_method === $method && (int) $stored->customer_id === (int) $customer->id;

        if ($same && !array_key_exists('pay_to', $input)) {
            return [$stored->pay_to, $stored->bank, null];
        }

        $given = $input['pay_to'] ?? null;

        if ($method === 'Bank') {
            $number = is_string($given) ? preg_replace('/[\s\-]+/', '', $given) : null;
            if ($same && $number === ($stored->bank['account_number'] ?? null)) {
                return [$stored->pay_to, $stored->bank, null];
            }
            foreach ($customer->banks ?? [] as $bank) {
                if ($number !== null && ($bank['account_number'] ?? null) === $number) {
                    return [ManagerValidation::bankLabel($bank), $bank, null];
                }
            }

            return [null, null, $customer->banks
                ? ['pay_to_not_saved', "Choose one of the customer's saved bank accounts."]
                : ['pay_to_none_saved', 'No bank account saved. Edit the customer and add a bank account.']];
        }

        $number = is_string($given) ? ManagerValidation::cleanWallet($given) : null;
        $label = $method === 'Bkash' ? 'bKash' : $method;
        if ($same && $number === $stored->pay_to) {
            return [$stored->pay_to, null, null];
        }
        $saved = $customer->walletNumbers($method);
        if (!$saved) {
            return [null, null, ['pay_to_none_saved', "No {$label} number saved. Edit the customer and add a {$label} number."]];
        }

        return in_array($number, $saved, true) ? [$number, null, null] : [null, null, ['pay_to_not_saved', "Choose one of the customer's saved {$label} numbers."]];
    }

    private function timezone(): string
    {
        return $this->current->get()->timezone;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone())))->format('Y-m-d');
    }

    /** The business-local date of the transaction (used for the automatic payment). */
    private function localDate(ManagerTransaction $tx): string
    {
        return $tx->occurred_at ? BusinessClock::for($this->current->get())->toLocal($tx->occurred_at)->format('Y-m-d') : $this->today();
    }
}
