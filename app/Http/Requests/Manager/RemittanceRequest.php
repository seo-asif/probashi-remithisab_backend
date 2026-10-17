<?php

namespace App\Http\Requests\Manager;

use App\Rules\PlainText;
use App\Support\Manager\ManagerOptions;
use App\Support\Validation\Reject;
use Illuminate\Foundation\Http\FormRequest;

/** Manager bank remittance. Cleans the input; RemittanceService checks the final values. */
class RemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $d = $this->all();

        if (isset($d['method'])) {
            $d['method'] = ManagerOptions::canonical($d['method'], ManagerOptions::REMIT_METHODS);
        }
        if (isset($d['currency']) && is_string($d['currency'])) {
            $d['currency'] = strtoupper(trim($d['currency']));
        }
        if (isset($d['charge_type']) && is_string($d['charge_type'])) {
            $d['charge_type'] = strtolower(trim($d['charge_type']));
        }
        foreach (['amount', 'rate', 'charge', 'incentive_pct', 'remit_bdt', 'occurred_at', 'notes', 'timezone'] as $k) {
            if (isset($d[$k]) && is_string($d[$k])) {
                $d[$k] = trim($d[$k]);
                if ($d[$k] === '' && !in_array($k, ['occurred_at'], true)) {
                    $d[$k] = null;
                }
            }
        }

        $this->replace($d);
    }

    public function rules(): array
    {
        return [
            'id'              => ['sometimes', 'nullable', 'uuid'],
            'method'          => ['sometimes', 'nullable', 'string'],
            'currency'        => ['sometimes', 'nullable', 'string', 'max:3'],
            'amount'          => ['sometimes'],
            'rate'            => ['sometimes'],
            'charge'          => ['sometimes'],
            'charge_type'     => ['sometimes', 'nullable', 'string'],
            'incentive'       => ['sometimes', 'boolean'],
            'incentive_pct'   => ['sometimes'],
            'remit_bdt'       => ['sometimes'],
            'occurred_at'     => ['sometimes', 'nullable', 'string'],
            'timezone'        => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes'           => ['sometimes', 'nullable', 'string', 'max:100', new PlainText()],
            'base_updated_at' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'id.uuid'           => Reject::msg('invalid_id', 'The record id must be a UUID made by the app.'),
            'incentive.boolean' => Reject::msg('incentive_invalid', 'Incentive must be on or off.'),
            'notes.max'         => Reject::msg('notes_too_long', 'Notes must be 100 characters or fewer.'),
        ];
    }
}
