<?php

namespace Tests\Feature\Account;

use App\Exceptions\ApiException;
use App\Services\Account\SenderBackupInput;
use App\Services\Account\SenderBackupOutput;
use Tests\TestCase;

final class SenderBackupOutputTest extends TestCase
{
    private function backup(string $notes = ''): array
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
            'test_notes' => $notes,
        ];
    }

    public function test_export_json_is_private_and_small_enough_to_restore(): void
    {
        $response = app(SenderBackupOutput::class)->response($this->backup());
        $this->assertSame(200, $response->status());
        $this->assertLessThanOrEqual(SenderBackupInput::MAX_BYTES, strlen((string) $response->getContent()));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
    }

    public function test_export_rejects_json_over_restore_size_limit(): void
    {
        try {
            app(SenderBackupOutput::class)->response(
                $this->backup(str_repeat('x', SenderBackupInput::MAX_BYTES))
            );
            $this->fail('Expected the export size limit to reject the response.');
        } catch (ApiException $e) {
            $this->assertSame(413, $e->status);
            $this->assertSame('BACKUP_TOO_LARGE', $e->errorCode);
        }
    }

    public function test_export_limit_uses_serialized_utf8_bytes(): void
    {
        try {
            app(SenderBackupOutput::class)->response(
                $this->backup(str_repeat('আ', 1800000))
            );
            $this->fail('Expected oversized UTF-8 backup to be rejected.');
        } catch (ApiException $e) {
            $this->assertSame(413, $e->status);
        }
    }
}
