<?php

namespace App\Http\Requests\Account;

use App\Support\Money\CurrencyRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'language' => ['sometimes', 'nullable', Rule::in(['bn', 'en'])],
            'mode' => ['sometimes', 'nullable', Rule::in(['sender', 'manager'])],
            'default_currency' => ['sometimes', 'required', Rule::in(CurrencyRules::CURRENCIES)],
            'is_student' => ['sometimes', 'nullable', 'boolean'],
            'intro_seen' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'required', 'array:work,goals,remind,auto'],
            'features.work' => ['sometimes', 'boolean'],
            'features.goals' => ['sometimes', 'boolean'],
            'features.remind' => ['sometimes', 'boolean'],
            'features.auto' => ['sometimes', 'boolean'],
            'reminders' => ['sometimes', 'required', 'array:time,confirm,pay,payType,payDay,payWd,payLast,loan,loanDay,mgr'],
            'reminders.time' => ['sometimes', 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D'],
            'reminders.confirm' => ['sometimes', 'boolean'],
            'reminders.pay' => ['sometimes', 'boolean'],
            'reminders.payType' => ['sometimes', Rule::in(['monthly', 'weekly', 'biweekly'])],
            'reminders.payDay' => ['sometimes', 'integer', 'between:1,31'],
            'reminders.payWd' => ['sometimes', 'integer', 'between:0,6'],
            'reminders.payLast' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reminders.loan' => ['sometimes', 'boolean'],
            'reminders.loanDay' => ['sometimes', 'integer', 'between:1,31'],
            'reminders.mgr' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['language', 'mode', 'default_currency', 'is_student', 'intro_seen', 'features', 'reminders'];
            foreach (array_keys($this->all()) as $key) {
                if (!in_array($key, $allowed, true)) {
                    $validator->errors()->add($key, 'Unknown app setting.');
                }
            }
        });
    }
}
