<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Models\SavingsGoal;
use App\Services\Account\SenderBackupOutput;
use App\Services\Auth\AuditLogger;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SenderBackupExportController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit, CurrentBusiness $current, SenderBackupOutput $output): JsonResponse
    {
        // These models enforce both the current business and the authenticated owner
        // through global scopes. Never remove those scopes for a backup.
        $recipients = SenderRecipient::withTrashed()->orderBy('id')->get()
            ->map(fn ($row) => $this->only($row, [
                'uuid', 'name', 'relationship', 'phone', 'notes', 'archived', 'archived_at',
                'deleted_at', 'created_at', 'updated_at',
            ]))->values()->all();

        $goals = SavingsGoal::withTrashed()->orderBy('id')->get()
            ->map(fn ($row) => $this->only($row, [
                'uuid', 'name', 'target_bdt', 'target_date', 'deleted_at', 'created_at', 'updated_at',
            ]))->values()->all();

        $recipientIds = SenderRecipient::withTrashed()->pluck('uuid', 'id');
        $goalIds = SavingsGoal::withTrashed()->pluck('uuid', 'id');

        $remittances = SenderRemittance::withTrashed()->orderBy('id')->get()
            ->map(function ($row) use ($recipientIds, $goalIds) {
                return [
                    ...$this->only($row, [
                        'uuid', 'recipient_type', 'recipient_name', 'currency', 'amount', 'rate',
                        'charge', 'charge_type', 'incentive_pct', 'method', 'principal',
                        'incentive_bdt', 'family_bdt', 'total_cost', 'received', 'received_at',
                        'purpose', 'notes', 'occurred_at', 'timezone',
                        'deleted_at', 'created_at', 'updated_at',
                    ]),
                    'recipient_uuid' => $row->recipient_id ? $recipientIds->get($row->recipient_id) : null,
                    'goal_uuid' => $row->goal_id ? $goalIds->get($row->goal_id) : null,
                ];
            })->values()->all();

        $response = $output->response([
            'format' => 'probashi-remithisab-sender',
            'schema_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'datasets' => [
                'recipients' => $recipients,
                'goals' => $goals,
                'remittances' => $remittances,
            ],
        ]);

        // Record only successful exports, after the size check passes.
        $audit->record('account.sender_backup_exported', $request->user(), $current->id(), $request->user());

        return $response;
    }

    private function only($model, array $fields): array
    {
        return array_intersect_key($model->getAttributes(), array_flip($fields));
    }
}
