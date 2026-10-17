<?php

namespace App\Http\Requests\Manager;

use App\Rules\PlainText;
use App\Support\Manager\ManagerOptions;
use App\Support\Manager\ManagerValidation;
use App\Support\Validation\Reject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manager customer transaction (POST = new, PATCH = change). This class only cleans the input; every money
 * rule is checked by TransactionService on the FINAL values, so a new record and an edit are checked the same way.
 * Calculated values are never taken from the app (obligation_bdt may be sent only to be compared).
 */
class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $d = $this->all();

        if (isset($d['receive_method'])) {
            $d['receive_method'] = ManagerOptions::canonical($d['receive_method'], ManagerOptions::RECEIVE_METHODS);
        }
        if (isset($d['status'])) {
            $d['status'] = ManagerOptions::canonical($d['status'], ManagerOptions::STATUSES);
        }
        if (isset($d['currency']) && is_string($d['currency'])) {
            $d['currency'] = strtoupper(trim($d['currency']));
        }
        foreach (['amount', 'rate', 'commission', 'withdrawal_charge', 'obligation_bdt', 'occurred_at', 'pay_to', 'timezone'] as $k) {
            if (isset($d[$k]) && is_string($d[$k])) {
                $d[$k] = trim($d[$k]);
                if ($d[$k] === '' && !in_array($k, ['amount', 'rate', 'occurred_at'], true)) {
                    $d[$k] = null;
                }
            }
        }
        if (array_key_exists('sending_number', $d)) {
            $d['sending_number'] = ManagerValidation::cleanWallet($d['sending_number']) ?: null;
        }

        $this->replace($d);
    }

    public function rules(): array
    {
        return [
            'id'                => ['sometimes', 'nullable', 'uuid'],
            'customer_id'       => ['sometimes', 'nullable', 'string', 'max:64'],
            'currency'          => ['sometimes', 'nullable', 'string', 'max:3'],
            'amount'            => ['sometimes'],
            'rate'              => ['sometimes'],
            'commission'        => ['sometimes'],
            'withdrawal_charge' => ['sometimes'],
            'obligation_bdt'    => ['sometimes'],
            'receive_method'    => ['sometimes', 'nullable', 'string'],
            'status'            => ['sometimes', 'nullable', 'string'],
            'pay_to'            => ['sometimes', 'nullable', 'string', 'max:40'],
            'sending_number'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'occurred_at'       => ['sometimes', 'nullable', 'string'],
            'timezone'          => ['sometimes', 'nullable', 'string', 'max:64'],
            'base_updated_at'   => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.uuid'        => Reject::msg('invalid_id', 'The record id must be a UUID made by the app.'),
            'pay_to.max'     => Reject::msg('pay_to_not_saved', 'Choose a saved number or bank account.'),
        ];
    }
}
