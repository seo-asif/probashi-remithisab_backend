<?php

namespace App\Services\Account;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shared structural validation for Sender backup preview and restore.
 * Database constraints and record-level validation are handled by the importer.
 */
final class SenderBackupValidator
{
    public function validate(array $payload): array
    {
        $data = Validator::make($payload, [
            'format' => ['required', 'in:probashi-remithisab-sender'],
            'schema_version' => ['required', 'integer', 'in:1'],
            'exported_at' => ['required', 'date'],
            'datasets' => ['required', 'array:recipients,goals,remittances'],
            'datasets.recipients' => ['required', 'array', 'max:5000'],
            'datasets.goals' => ['required', 'array', 'max:5000'],
            'datasets.remittances' => ['required', 'array', 'max:50000'],
            'datasets.recipients.*' => ['required', 'array'],
            'datasets.goals.*' => ['required', 'array'],
            'datasets.remittances.*' => ['required', 'array'],
            'datasets.recipients.*.uuid' => ['required', 'uuid', 'distinct'],
            'datasets.goals.*.uuid' => ['required', 'uuid', 'distinct'],
            'datasets.remittances.*.uuid' => ['required', 'uuid', 'distinct'],
            'datasets.remittances.*.recipient_uuid' => ['nullable', 'uuid'],
            'datasets.remittances.*.goal_uuid' => ['nullable', 'uuid'],
        ])->validate();

        $recipientIds = array_fill_keys(array_column($data['datasets']['recipients'], 'uuid'), true);
        $goalIds = array_fill_keys(array_column($data['datasets']['goals'], 'uuid'), true);
        $errors = [];

        foreach ($data['datasets']['remittances'] as $index => $row) {
            foreach (['recipient_uuid' => $recipientIds, 'goal_uuid' => $goalIds] as $field => $known) {
                $uuid = $row[$field] ?? null;
                if ($uuid !== null && !isset($known[$uuid])) {
                    $errors["datasets.remittances.$index.$field"] = ['Referenced record is missing from this backup.'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
