<?php

namespace App\Http\Requests\Sender;

use App\Support\Sender\SenderOptions;
use App\Support\Sender\SenderValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sender Mode family member. POST = new (name required), PATCH = change (every field optional).
 * No payment-method fields: unknown fields are ignored and never stored. No duplicate-phone check.
 * The business and the owner come from the logged-in user, never from the request.
 */
class FamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(SenderValidation::normalizeFamily($this->all()));
    }

    public function rules(): array
    {
        return self::ruleSet(!$this->isMethod('post'));
    }

    public static function ruleSet(bool $partial): array
    {
        $rules = [
            'name'         => [$partial ? 'sometimes' : 'required', 'string', 'max:' . SenderOptions::NAME_MAX],
            'relationship' => ['sometimes', 'nullable', Rule::in(SenderOptions::RELATIONSHIPS)],
            'phone'        => ['sometimes', 'nullable', 'string', 'regex:' . SenderOptions::PHONE_REGEX],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:' . SenderOptions::NOTES_MAX],
        ];

        if (!$partial) {
            $rules['client_uuid'] = ['sometimes', 'nullable', 'uuid'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'Please enter a name.',
            'name.max'          => 'The name can be at most :max characters.',
            'phone.regex'       => 'Enter a valid mobile number (digits only, up to 18, an optional + at the start).',
            'notes.max'         => 'The note can be at most :max characters.',
            'relationship.in'   => 'Choose a relationship from the list.',
        ];
    }
}
