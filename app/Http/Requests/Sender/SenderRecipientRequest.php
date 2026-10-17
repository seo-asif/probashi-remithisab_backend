<?php

namespace App\Http\Requests\Sender;

use App\Rules\PersonName;
use App\Rules\PlainText;
use App\Rules\UniqueFamilyPhone;
use App\Support\Sender\SenderOptions;
use App\Support\Sender\SenderValidation;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Validation\Reject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sender family member / recipient. POST = new (name required), PATCH = change. No payment fields.
 * The business and owner come from the logged-in user, never from the request.
 */
class SenderRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(SenderValidation::normalizeRecipient($this->all()));
    }

    public function rules(): array
    {
        $current = app(CurrentBusiness::class);
        $rules   = self::ruleSet(!$this->isMethod('post'));
        $rules['phone'][] = new UniqueFamilyPhone((int) $current->id(), (int) $current->userId(), $this->route('id') ? (string) $this->route('id') : null);

        return $rules;
    }

    /** Field rules that need no database. */
    public static function ruleSet(bool $partial): array
    {
        $rules = [
            'name'         => [$partial ? 'sometimes' : 'required', 'string', new PersonName()],
            'relationship' => ['sometimes', 'nullable', Rule::in(SenderOptions::RELATIONSHIPS)],
            'phone'        => ['sometimes', 'nullable', 'string', 'regex:' . SenderOptions::PHONE_REGEX],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:' . SenderOptions::NOTES_MAX, new PlainText()],
        ];

        if (!$partial) {
            $rules['id'] = ['sometimes', 'nullable', 'uuid'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required'   => Reject::msg('name_required', 'Family member name is required.'),
            'phone.regex'     => Reject::msg('phone_invalid', 'Use digits only, optionally starting with +, up to 18 digits.'),
            'notes.max'       => Reject::msg('notes_too_long', 'Notes must be 100 characters or fewer.'),
            'relationship.in' => Reject::msg('relationship_invalid', 'Choose a relationship from the list.'),
            'id.uuid'         => Reject::msg('invalid_id', 'The record id must be a UUID made by the app.'),
        ];
    }
}
