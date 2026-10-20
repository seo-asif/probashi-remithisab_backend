<?php

namespace App\Http\Requests\Sender;

use App\Support\Sender\SenderOptions;
use App\Support\Sender\SenderValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sender Mode "Record a Remittance". POST = new (all required fields), PATCH = change (every field optional).
 * Totals are never accepted from the app: total BDT is always computed by the server.
 * The business and the owner come from the logged-in user, never from the request.
 */
class SenderRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(SenderValidation::normalizeRemittance($this->all()));
    }

    public function rules(): array
    {
        $partial = !$this->isMethod('post');
        $required = $partial ? 'sometimes' : 'required';

        return [
            'id'             => ['sometimes', 'uuid'],

            'recipient_type' => [
                'sometimes',
                'string',
            ],
            'recipient_id'   => [
                'sometimes',
                'nullable',
                'uuid',
            ],

            'currency'       => [
                $required,
                'string',
            ],
            'amount'         => [
                $required,
            ],
            'rate'           => [
                $required,
            ],
            'method'         => [
                $required,
                'string',
            ],
            'charge_type'    => [
                $required,
                'string',
            ],
            'charge'         => [
                'sometimes',
                'nullable',
            ],
            'incentive_pct'  => [
                'sometimes',
                'nullable',
            ],

            'occurred_at'    => [
                $required,
                'string',
            ],
            'timezone'       => [
                'sometimes',
                'nullable',
                'string',
            ],
            'purpose'        => [
                'sometimes',
                'nullable',
                'string',
            ],
            'goal_id'        => [
                'sometimes',
                'nullable',
                'uuid',
            ],
            'family_bdt'     => [
                'sometimes',
                'nullable',
            ],
            'notes'          => [
                'sometimes',
                'nullable',
                'string',
                'max:' . SenderOptions::NOTES_MAX,
            ],
        ];
    }

    /** Field rules that need no database. Used by the tests and the sync endpoint. */
    public static function baseRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'client_uuid'  => ['sometimes', 'nullable', 'uuid'],
            'currency'     => [$required, Rule::in(SenderOptions::CURRENCIES)],
            'amount'       => [$required, 'numeric', 'min:' . SenderOptions::AMOUNT_MIN, 'max:' . SenderOptions::AMOUNT_MAX, 'decimal:0,2'],
            'rate'         => [$required, 'numeric', 'min:' . SenderOptions::RATE_MIN, 'max:' . SenderOptions::RATE_MAX, 'decimal:0,4'],
            'payment_mode' => [$required, Rule::in(SenderOptions::PAYMENT_MODES)],
            'charge'       => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:' . SenderOptions::CHARGE_MAX, 'decimal:0,2'],
            'remit_date'   => [$required, 'date_format:Y-m-d'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:' . SenderOptions::NOTES_MAX],
        ];
    }

    /** The recipient must be one of THIS user's own active family members. */
    public static function ruleSet(int $businessId, int $userId, bool $partial): array
    {
        return self::baseRules($partial) + [
            'family_member_id' => [$partial ? 'sometimes' : 'required', 'uuid',
                Rule::exists('family_members', 'uuid')
                    ->where('business_id', $businessId)
                    ->where('owner_user_id', $userId)
                    ->whereNull('deleted_at')],
        ];
    }

    public function withValidator($validator): void
    {
        //  $validator->after(function ($v) {
        //   if ($v->errors()->isNotEmpty()) {
        //        return;
        //     }

        //     $business = app(CurrentBusiness::class)->get();
        //     $clock = new BusinessClock($business->timezone);
//
        //     foreach (SenderValidation::crossErrors($this->all(), $clock, new \DateTimeImmutable('now')) as $field => $message) {
        //        $v->errors()->add($field, $message);
        //      }
        // });

    }

    public function messages(): array
    {
        return [
            'family_member_id.required' => 'Please select a family member.',
            'family_member_id.exists'   => 'Please select one of your family members.',
            'amount.min'                => 'Enter an amount sent greater than zero.',
            'rate.min'                  => 'Enter an exchange rate greater than zero.',
            'amount.decimal'            => 'The amount can have at most 2 decimal places.',
            'rate.decimal'              => 'The exchange rate can have at most 4 decimal places.',
            'currency.in'               => 'Choose a currency from the list.',
            'payment_mode.in'           => 'Choose a payment method from the list.',
            'remit_date.date_format'    => 'Enter the date as year-month-day, for example 2026-10-12.',
            'notes.max'                 => 'The note can be at most :max characters.',
        ];
    }
}
