<?php

namespace Tests\Feature\Account;

use App\Services\Account\SenderBackupValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SenderBackupValidatorTest extends TestCase
{
    private function payload(): array
    {
        return [
            'format' => 'probashi-remithisab-sender',
            'schema_version' => 1,
            'exported_at' => '2026-10-25T10:00:00+12:00',
            'datasets' => [
                'recipients' => [],
                'goals' => [],
                'remittances' => [],
            ],
        ];
    }

    public function test_valid_empty_backup_is_accepted(): void
    {
        $payload = $this->payload();

        $this->assertSame(
            $payload,
            app(SenderBackupValidator::class)->validate($payload)
        );
    }

    public function test_backup_with_linked_records_is_accepted(): void
    {
        $payload = $this->payload();
        $recipient = '11111111-1111-4111-8111-111111111111';
        $goal = '22222222-2222-4222-8222-222222222222';
        $payload['datasets']['recipients'][] = ['uuid' => $recipient];
        $payload['datasets']['goals'][] = ['uuid' => $goal];
        $payload['datasets']['remittances'][] = [
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'recipient_uuid' => $recipient,
            'goal_uuid' => $goal,
        ];

        $this->assertSame(
            $payload,
            app(SenderBackupValidator::class)->validate($payload)
        );
    }

    public function test_missing_dataset_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['datasets']['goals']);

        $this->expectException(ValidationException::class);
        app(SenderBackupValidator::class)->validate($payload);
    }

    public function test_unknown_format_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['format'] = 'different-backup-format';

        $this->expectException(ValidationException::class);
        app(SenderBackupValidator::class)->validate($payload);
    }

    public function test_duplicate_remittance_uuid_is_rejected(): void
    {
        $payload = $this->payload();
        $row = ['uuid' => '33333333-3333-4333-8333-333333333333'];
        $payload['datasets']['remittances'] = [$row, $row];

        $this->expectException(ValidationException::class);
        app(SenderBackupValidator::class)->validate($payload);
    }

    public function test_uuid_duplicates_with_different_case_are_rejected(): void
    {
        $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        foreach (['recipients', 'goals', 'remittances'] as $dataset) {
            $payload = $this->payload();
            $payload['datasets'][$dataset] = [
                ['uuid' => $uuid],
                ['uuid' => strtoupper($uuid)],
            ];

            try {
                app(SenderBackupValidator::class)->validate($payload);
                $this->fail("Expected duplicate $dataset UUID to be rejected.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("datasets.$dataset.1.uuid", $e->errors());
            }
        }
    }

    public function test_references_match_regardless_of_uuid_case(): void
    {
        $payload = $this->payload();
        $recipient = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $goal = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $payload['datasets']['recipients'][] = ['uuid' => $recipient];
        $payload['datasets']['goals'][] = ['uuid' => $goal];
        $payload['datasets']['remittances'][] = [
            'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'recipient_uuid' => strtoupper($recipient),
            'goal_uuid' => strtoupper($goal),
        ];

        $this->assertSame($payload, app(SenderBackupValidator::class)->validate($payload));
    }

    public function test_missing_referenced_recipient_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['datasets']['remittances'][] = [
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'recipient_uuid' => '11111111-1111-4111-8111-111111111111',
        ];

        $this->expectException(ValidationException::class);
        app(SenderBackupValidator::class)->validate($payload);
    }
}
