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
    private array $claimedIds = [];

    protected function tearDown(): void
    {
        foreach ($this->mintedCallIds as $callId) {
            transcript_target_delete($callId);
        }
        // Clean up summary markers + pending retries written by these tests.
        foreach ($this->claimedIds as $id) {
            $safe = preg_replace('/[^a-zA-Z0-9-]/', '', (string)$id);
            $dir  = __DIR__ . '/../server/public/transcript_targets/';
            foreach (array_merge([$dir . 'seen_' . $safe . '.json'], glob($dir . 'retry_' . $safe . '_*.json') ?: []) as $p) {
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }
        $this->mintedCallIds = [];
        $this->claimedIds = [];
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
    public function first_delivery_applies_and_identical_repeat_is_skipped(): void
    {
        $tid = 'tid-' . bin2hex(random_bytes(6));
        $this->claimedIds[] = $tid;

        $first = transcript_should_apply($tid, 'Wants pricing.');
        $this->assertTrue($first['apply']);
        $this->assertFalse($first['is_update']);

        $again = transcript_should_apply($tid, 'Wants pricing.');
        $this->assertFalse($again['apply'], 'same transcript_id + same summary must not write twice');
    }

    #[Test]
    public function repeat_with_new_summary_is_an_update(): void
    {
        // New Dialer Window: first delivery may carry summary:null, then PB
        // re-sends the same transcript_id once the summary is ready.
        $tid = 'tid-' . bin2hex(random_bytes(6));
        $this->claimedIds[] = $tid;

        $this->assertTrue(transcript_should_apply($tid, null)['apply'], 'summary-less first delivery still writes the transcript');

        $update = transcript_should_apply($tid, 'Call back Tuesday.');
        $this->assertTrue($update['apply']);
        $this->assertTrue($update['is_update']);

        $changed = transcript_should_apply($tid, 'Call back Wednesday instead.');
        $this->assertTrue($changed['apply'], 'an edited summary is another update');
        $this->assertTrue($changed['is_update']);
    }

    #[Test]
    public function repeat_without_summary_is_skipped(): void
    {
        $tid = 'tid-' . bin2hex(random_bytes(6));
        $this->claimedIds[] = $tid;

        transcript_should_apply($tid, 'Has a summary.');
        $this->assertFalse(transcript_should_apply($tid, '')['apply'], 'a repeat with nothing new must not write');
    }

    #[Test]
    public function unusable_transcript_id_still_applies_once(): void
    {
        // Can't track a missing id — must not block a legitimate delivery.
        $this->assertTrue(transcript_should_apply('', 'x')['apply']);
        $this->assertTrue(transcript_should_apply([], 'x')['apply']);
    }

    #[Test]
    public function retry_reschedules_then_gives_up(): void
    {
        $callId = 'test-' . bin2hex(random_bytes(6));
        $tid    = 'tid-' . bin2hex(random_bytes(6));
        $this->mintedCallIds[] = $callId;
        $this->claimedIds[]    = $tid;

        // An unknown provider makes transcript_apply() fail every time.
        transcript_target_store($callId, ['provider' => 'nope']);
        $this->assertTrue(transcript_retry_store('dial', $callId, ['transcript_id' => $tid, 'summary' => 's'], false));

        $now = time();
        $this->assertSame(0, array_sum(transcript_retry_run($now)), 'not due before the first backoff');

        $t = $now;
        $gaveUp = 0;
        foreach (TRANSCRIPT_RETRY_BACKOFF as $wait) {
            $t += $wait + 1;
            $gaveUp += transcript_retry_run($t)['gave_up'];
        }
        $this->assertSame(1, $gaveUp, 'gives up after the last backoff step');
        $this->assertSame([], glob(__DIR__ . '/../server/public/transcript_targets/retry_' . $tid . '_*.json'));
    }

    #[Test]
    public function retry_is_dropped_when_target_is_gone(): void
    {
        $tid = 'tid-' . bin2hex(random_bytes(6));
        $this->claimedIds[] = $tid;

        transcript_retry_store('dial', 'gone-' . bin2hex(random_bytes(6)), ['transcript_id' => $tid], false);
        transcript_retry_run(time() + 100000);
        $this->assertSame([], glob(__DIR__ . '/../server/public/transcript_targets/retry_' . $tid . '_*.json'));
    }

    #[Test]
    public function softphone_signature_accepts_only_a_matching_hmac(): void
    {
        $body = '{"event":"transcript.available"}';
        $sig  = hash_hmac('sha256', $body, 'secret');

        $this->assertTrue(pb_softphone_signature_valid($body, $sig, 'secret'));
        $this->assertTrue(pb_softphone_signature_valid($body, 'sha256=' . $sig, 'secret'));
        $this->assertFalse(pb_softphone_signature_valid($body . ' ', $sig, 'secret'), 'tampered body');
        $this->assertFalse(pb_softphone_signature_valid($body, $sig, 'other'), 'wrong secret');
        $this->assertFalse(pb_softphone_signature_valid($body, '', 'secret'), 'unsigned');
        $this->assertFalse(pb_softphone_signature_valid($body, $sig, ''), 'no secret configured');
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
