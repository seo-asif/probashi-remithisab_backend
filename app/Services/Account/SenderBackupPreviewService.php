<?php

namespace App\Services\Account;

use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Models\SavingsGoal;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only Sender backup preview. All queries retain the business and owner
 * global scopes, including soft-deleted records owned by this user.
 *
 * This is NOT record-level import validation. Restore must validate each row,
 * resolve UUID collisions safely, and enforce plan/business constraints.
 */
final class SenderBackupPreviewService
{
    public function preview(array $backup): array
    {
        $models = [
            'recipients' => SenderRecipient::class,
            'goals' => SavingsGoal::class,
            'remittances' => SenderRemittance::class,
        ];

        $datasets = [];
        $total = 0;
        foreach ($models as $dataset => $model) {
            $uuids = array_column($backup['datasets'][$dataset], 'uuid');
            $existing = $this->existingCount($model, $uuids);
            $count = count($uuids);

            $datasets[$dataset] = [
                'total' => $count,
                'existing' => $existing,
                'new' => $count - $existing,
            ];
            $total += $count;
        }

        return [
            'format' => $backup['format'],
            'schema_version' => $backup['schema_version'],
            'exported_at' => $backup['exported_at'],
            'total_records' => $total,
            'datasets' => $datasets,
            'validation_level' => 'structure_only',
            'restore_ready' => false,
            'warning' => 'This preview checks structure and UUIDs in your own account only. Record-level validation, collisions outside your account, and restore rules are not checked. No records were changed.',
        ];
    }

    /**
     * @param class-string<Model> $model
     * @param list<string> $uuids
     */
    private function existingCount(string $model, array $uuids): int
    {
        if ($uuids === []) {
            return 0;
        }

        $existing = [];
        // Bound IN lists; do not fetch unneeded financial or personal fields.
        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach ($model::withTrashed()->whereIn('uuid', $chunk)->pluck('uuid') as $uuid) {
                $existing[strtolower((string) $uuid)] = true;
            }
        }

        // Handles case variations without miscounting the same database UUID.
        $count = 0;
        foreach ($uuids as $uuid) {
            if (isset($existing[strtolower($uuid)])) {
                ++$count;
            }
        }

        return $count;
    }
}
