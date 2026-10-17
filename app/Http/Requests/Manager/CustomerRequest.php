<?php

namespace App\Http\Requests\Manager;

use App\Rules\PlainText;
use App\Support\Manager\ManagerOptions;
use App\Support\Manager\ManagerValidation;
use App\Support\Validation\Reject;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manager customer (app v1.1.270 "Add customer"). POST = new (name required), PATCH = change (every field optional).
 * Duplicate contact numbers are checked by CustomerService (409 DUPLICATE_CUSTOMER / RESTORE_AVAILABLE).
 */
class CustomerRequest extends FormRequest
{
    public const WALLET_FIELDS = ['bkash_numbers' => 'Bkash', 'nagad_numbers' => 'Nagad', 'rocket_numbers' => 'Rocket'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach (['name', 'address', 'notes'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
                if ($data[$key] === '' && $key !== 'name') {
                    $data[$key] = null;
                }
            }
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = ManagerValidation::cleanContact($data['phone']);
        }

        foreach (array_keys(self::WALLET_FIELDS) as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = array_values(array_filter(
                    array_map(fn ($v) => ManagerValidation::cleanWallet($v), $data[$key]),
                    fn ($v) => $v !== '' && $v !== null,
                ));
            }
        }

        if (isset($data['banks']) && is_array($data['banks'])) {
            $data['banks'] = array_values(array_map(fn ($b) => ManagerValidation::cleanBank($b), $data['banks']));
        }

        $this->replace($data);
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        $rules = [
            'name'            => [$required, 'string', $this->check('name', fn ($v) => is_string($v) ? ManagerValidation::customerNameError($v) : 'Customer name is required.')],
            'phone'           => ['sometimes', 'nullable', 'string', 'regex:' . ManagerOptions::PHONE_REGEX],
            'address'         => ['sometimes', 'nullable', 'string', $this->check('address', fn ($v) => is_string($v) ? ManagerValidation::addressError($v) : null)],
            'notes'           => ['sometimes', 'nullable', 'string', 'max:' . ManagerOptions::NOTES_MAX, new PlainText()],
            'attention'       => ['sometimes', 'boolean'],
            'banks'           => ['sometimes', 'nullable', 'array', 'max:' . ManagerOptions::BANKS_MAX],
            'banks.*'         => ['array'],
            'base_updated_at' => ['sometimes', 'nullable', 'string'],
        ];

        if ($this->isMethod('post')) {
            $rules['id'] = ['sometimes', 'nullable', 'uuid'];
        }

        foreach (self::WALLET_FIELDS as $field => $method) {
            $rules[$field]        = ['sometimes', 'nullable', 'array', 'max:' . ManagerOptions::WALLETS_MAX];
            $rules[$field . '.*'] = ['string', 'distinct', $this->check('wallet_number', fn ($v) => is_string($v) ? ManagerValidation::walletError($v, $method) : 'Use numbers only.')];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $banks = $this->input('banks');

            if (!is_array($banks)) {
                return;
            }

            $seen = [];
            foreach ($banks as $i => $bank) {
                if (!is_array($bank)) {
                    continue;
                }

                foreach (ManagerValidation::bankErrors($bank) as $field => $message) {
                    $v->errors()->add("banks.{$i}.{$field}", Reject::msg("bank_{$field}_invalid", $message));
                }

                $number = $bank['account_number'] ?? null;
                if (is_string($number) && $number !== '') {
                    if (isset($seen[$number])) {
                        $v->errors()->add("banks.{$i}.account_number", Reject::msg('bank_account_duplicate', 'This account number is already added for this customer.'));
                    }
                    $seen[$number] = true;
                }
            }
        });
    }

    public function messages(): array
    {
        $dup = Reject::msg('wallet_number_duplicate', 'This number is already added for this customer.');

        return [
            'name.required'             => Reject::msg('name_required', 'Customer name is required.'),
            'phone.regex'               => Reject::msg('phone_invalid', 'Use numbers only, with an optional + at the beginning, up to 18 digits.'),
            'notes.max'                 => Reject::msg('notes_too_long', 'Notes must be 100 characters or fewer.'),
            'bkash_numbers.max'         => Reject::msg('too_many_numbers', 'Up to 5 bKash numbers can be added for one customer.'),
            'nagad_numbers.max'         => Reject::msg('too_many_numbers', 'Up to 5 Nagad numbers can be added for one customer.'),
            'rocket_numbers.max'        => Reject::msg('too_many_numbers', 'Up to 5 Rocket numbers can be added for one customer.'),
            'bkash_numbers.*.distinct'  => $dup,
            'nagad_numbers.*.distinct'  => $dup,
            'rocket_numbers.*.distinct' => $dup,
            'banks.max'                 => Reject::msg('too_many_banks', 'Up to 10 bank accounts can be added for one customer.'),
        ];
    }

    /** Wraps "returns an English error or null" as a rule; the code is {field}_required / _too_short / _too_long / _invalid. */
    private function check(string $field, callable $errorOf): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field, $errorOf) {
            if (($message = $errorOf($value)) === null) {
                return;
            }
            $kind = match (true) {
                str_contains($message, 'required')    => 'required',
                str_contains($message, 'at least')    => 'too_short',
                str_contains($message, 'or fewer')    => 'too_long',
                default                               => 'invalid',
            };
            $fail(Reject::msg("{$field}_{$kind}", $message));
        };
    }
}
