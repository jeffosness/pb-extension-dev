<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the transcript-target store (utils.php): transcript_target_store() /
 * _load() / _delete() / _path() / transcript_targets_cleanup().
 *
 * These correlate the async api_transcript webhook (EXP-5135) back to the CRM
 * call activity we logged at call_done time, keyed by PB's call_id. Invariants:
 *   1. store → load round-trips the record and stamps created_at.
 *   2. delete removes it (so an at-least-once redelivery no-ops).
 *   3. the call_id is sanitized into the basename — no path traversal.
 *   4. cleanup removes only files older than the cutoff.
 *
 * Files land in server/public/transcript_targets/, so we clean up per test.
 */
final class TranscriptTargetTest extends TestCase
{
    private array $mintedCallIds = [];

    protected function tearDown(): void
    {
        foreach ($this->mintedCallIds as $callId) {
            transcript_target_delete($callId);
        }
        $this->mintedCallIds = [];
    }

    #[Test]
    public function store_then_load_round_trips_the_record(): void
    {
        $callId = 'test-' . bin2hex(random_bytes(6));
        $this->mintedCallIds[] = $callId;

        $ok = transcript_target_store($callId, [
            'provider'    => 'close',
            'client_id'   => 'client-abc',
            'activity_id' => 'acti_123',
            'note_text'   => 'Call via PhoneBurner: Interested',
        ]);
        $this->assertTrue($ok);

        $loaded = transcript_target_load($callId);
        $this->assertIsArray($loaded);
        $this->assertSame('close', $loaded['provider']);
        $this->assertSame('acti_123', $loaded['activity_id']);
        $this->assertArrayHasKey('created_at', $loaded, 'store must stamp created_at');
    }

    #[Test]
    public function delete_removes_the_target(): void
    {
        $callId = 'test-' . bin2hex(random_bytes(6));
        transcript_target_store($callId, ['provider' => 'close']);

        transcript_target_delete($callId);

        $this->assertNull(transcript_target_load($callId), 'deleted target must not load');
    }

    #[Test]
    public function load_returns_null_for_unknown_call_id(): void
    {
        $this->assertNull(transcript_target_load('never-stored-' . bin2hex(random_bytes(6))));
    }

    #[Test]
    public function empty_call_id_is_rejected(): void
    {
        $this->assertNull(transcript_target_path(''));
        $this->assertFalse(transcript_target_store('', ['provider' => 'close']));
    }

    #[Test]
    public function call_id_is_sanitized_into_the_basename_no_traversal(): void
    {
        // A malicious call_id must resolve inside transcript_targets/, never up.
        $path = transcript_target_path('../../../etc/passwd');
        $this->assertIsString($path);
        $this->assertStringContainsString('transcript_targets', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    #[Test]
    public function cleanup_removes_only_files_older_than_cutoff(): void
    {
        $oldId = 'old-' . bin2hex(random_bytes(6));
        $newId = 'new-' . bin2hex(random_bytes(6));
        $this->mintedCallIds[] = $oldId;
        $this->mintedCallIds[] = $newId;

        transcript_target_store($oldId, ['provider' => 'close']);
        transcript_target_store($newId, ['provider' => 'close']);

        // Backdate the "old" one well past the cutoff.
        @touch(transcript_target_path($oldId), time() - 100000);

        $removed = transcript_targets_cleanup(3600); // 1-hour cutoff
        $this->assertGreaterThanOrEqual(1, $removed);

        $this->assertNull(transcript_target_load($oldId), 'stale target should be swept');
        $this->assertIsArray(transcript_target_load($newId), 'fresh target should survive');
    }
}
