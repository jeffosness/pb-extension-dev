<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the call-transcript store in utils.php (PB EXP-5135):
 *
 *   transcript_dial_target_key()           — session-bound dial-session keys
 *   transcript_target_store/_load/_delete  — correlation records
 *   transcript_should_apply / _marker_*    — "a repeated transcript_id is an update"
 *   transcript_retry_store / _retry_run    — our own retries (PB never retries)
 *   transcript_targets_cleanup             — 7-day retention sweep
 *   pb_softphone_signature_valid           — softphone webhook HMAC
 *
 * Files land in transcripts_dir() (TOKENS_DIR/../transcripts — a per-run temp
 * dir under CI); each test removes what it wrote.
 */
final class TranscriptTargetTest extends TestCase
{
    /** @var string[] target keys to remove in tearDown */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $key) {
            transcript_target_delete($key);
        }
        foreach (array_merge(
            glob(transcripts_dir() . '/seen_*.json') ?: [],
            glob(transcripts_dir() . '/retry_*.json') ?: [],
            glob(transcripts_dir() . '/lock_*.json') ?: []
        ) as $f) {
            @unlink($f);
        }
        $this->written = [];
    }

    private function key(): string
    {
        $k = transcript_dial_target_key(random_int(3000000000, 3999999999), bin2hex(random_bytes(16)));
        $this->written[] = $k;
        return $k;
    }

    private function retryFiles(): array
    {
        return glob(transcripts_dir() . '/retry_*.json') ?: [];
    }

    // --- keys + store -------------------------------------------------------

    #[Test]
    public function dial_key_is_bound_to_the_session(): void
    {
        $a = transcript_dial_target_key(3133482107, 'session-a');
        $b = transcript_dial_target_key(3133482107, 'session-b');
        $this->assertNotSame($a, $b, 'a forged call_done on another session must not address the same target');
        $this->assertSame($a, transcript_dial_target_key('3133482107', 'session-a'), 'int and string call_id agree');
        $this->assertNull(transcript_dial_target_key(3133482107, ''));
        $this->assertNull(transcript_dial_target_key(null, 'session-a'));
        $this->assertNull(transcript_dial_target_key([1], 'session-a'));
    }

    #[Test]
    public function store_then_load_round_trips_the_record(): void
    {
        $key = $this->key();
        $this->assertTrue(transcript_target_store($key, ['provider' => 'close', 'activity_id' => 'acti_123']));

        $loaded = transcript_target_load($key);
        $this->assertSame('acti_123', $loaded['activity_id']);
        $this->assertArrayHasKey('created_at', $loaded, 'store must stamp created_at');

        transcript_target_delete($key);
        $this->assertNull(transcript_target_load($key));
    }

    #[Test]
    public function store_lives_outside_the_webroot(): void
    {
        $dir = str_replace('\\', '/', transcripts_dir());
        $this->assertStringNotContainsString('server/public', $dir);
    }

    #[Test]
    public function key_is_sanitized_into_the_basename_no_traversal(): void
    {
        $path = transcript_target_path('../../../etc/passwd');
        $this->assertIsString($path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertNull(transcript_target_path(''));
        $this->assertFalse(transcript_target_store('', ['provider' => 'close']));
    }

    // --- update decisions -------------------------------------------------

    #[Test]
    public function first_delivery_applies_and_identical_repeat_is_skipped(): void
    {
        $key = $this->key();
        $first = transcript_should_apply($key, 'tid-1', 'Wants pricing.');
        $this->assertTrue($first['apply']);
        $this->assertFalse($first['is_update']);
        $this->assertNull($first['prev_fp']);

        $this->assertFalse(transcript_should_apply($key, 'tid-1', 'Wants pricing.')['apply']);
    }

    #[Test]
    public function repeat_with_new_summary_is_an_update(): void
    {
        // New Dialer Window: summary:null first, then the same transcript_id again.
        $key = $this->key();
        $this->assertTrue(transcript_should_apply($key, 'tid-2', null)['apply'], 'summary-less first delivery still writes the transcript');

        $update = transcript_should_apply($key, 'tid-2', 'Call back Tuesday.');
        $this->assertTrue($update['apply']);
        $this->assertTrue($update['is_update']);

        $this->assertTrue(transcript_should_apply($key, 'tid-2', 'Call back Wednesday.')['apply'], 'an edited summary is another update');
        $this->assertFalse(transcript_should_apply($key, 'tid-2', '')['apply'], 'a repeat with no summary has nothing new');
    }

    #[Test]
    public function markers_are_per_target(): void
    {
        // Another tenant re-using a transcript_id can't pre-claim ours.
        $mine  = $this->key();
        $other = $this->key();
        transcript_should_apply($other, 'tid-shared', 'x');
        $this->assertTrue(transcript_should_apply($mine, 'tid-shared', 'x')['apply']);
    }

    #[Test]
    public function marker_restore_undoes_a_failed_write(): void
    {
        $key = $this->key();
        $d = transcript_should_apply($key, 'tid-3', 'A');
        transcript_marker_restore($key, 'tid-3', $d['prev_fp']);
        $this->assertNull(transcript_marker_fp($key, 'tid-3'), 'no prior marker → removed');
        $this->assertTrue(transcript_should_apply($key, 'tid-3', 'A')['apply'], 'the same delivery can land again');

        $u = transcript_should_apply($key, 'tid-3', 'B');
        transcript_marker_restore($key, 'tid-3', $u['prev_fp']);
        $this->assertSame(transcript_summary_fp('A'), transcript_marker_fp($key, 'tid-3'), 'restored to the previous summary');
    }

    #[Test]
    public function transcript_lock_is_exclusive_until_released(): void
    {
        $key = $this->key();
        $fh  = transcript_lock($key, 'tid-lock');
        $this->assertIsResource($fh);

        // A second handle on the same lock file can't take it while held.
        $files = glob(transcripts_dir() . '/lock_*.json');
        $other = fopen($files[0], 'c');
        $this->assertFalse(flock($other, LOCK_EX | LOCK_NB), 'held lock must block a concurrent delivery');

        transcript_unlock($fh);
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB), 'released lock is free again');
        flock($other, LOCK_UN);
        fclose($other);

        $this->assertNull(transcript_lock($key, ''), 'unusable id → no lock, proceed unlocked');
    }

    #[Test]
    public function unusable_transcript_id_still_applies_once(): void
    {
        $key = $this->key();
        $this->assertTrue(transcript_should_apply($key, '', 'x')['apply']);
        $this->assertTrue(transcript_should_apply($key, [], 'x')['apply']);
    }

    // --- retries ----------------------------------------------------------

    #[Test]
    public function retry_reschedules_then_gives_up(): void
    {
        $key = $this->key();
        // An unknown provider makes transcript_apply() fail every time.
        transcript_target_store($key, ['provider' => 'nope']);
        transcript_should_apply($key, 'tid-4', 's');
        $this->assertTrue(transcript_retry_store('dial', $key, 1, ['transcript_id' => 'tid-4', 'summary' => 's'], false));

        $now = time();
        $this->assertSame(0, array_sum(transcript_retry_run($now)), 'not due before the first backoff');

        $t = $now;
        $gaveUp = 0;
        foreach (TRANSCRIPT_RETRY_BACKOFF as $wait) {
            $t += $wait + 1;
            $gaveUp += transcript_retry_run($t)['gave_up'];
        }
        $this->assertSame(1, $gaveUp, 'gives up after the last backoff step');
        $this->assertSame([], $this->retryFiles());
    }

    #[Test]
    public function stale_close_retry_is_dropped_when_a_newer_summary_landed(): void
    {
        // Original (summary null) failed and was queued; the summary update then
        // succeeded. Re-applying the original would wipe the summary off the note.
        $key = $this->key();
        transcript_target_store($key, ['provider' => 'close']);
        transcript_should_apply($key, 'tid-5', null);
        transcript_retry_store('dial', $key, 1, ['transcript_id' => 'tid-5', 'summary' => null], false);
        transcript_should_apply($key, 'tid-5', 'Newer summary.');

        $stats = transcript_retry_run(time() + 100000);
        $this->assertSame(1, $stats['dropped']);
        $this->assertSame(0, $stats['ok'] + $stats['failed'] + $stats['gave_up'], 'never applied');
        $this->assertSame([], $this->retryFiles());
    }

    #[Test]
    public function forth_original_retry_survives_a_newer_summary(): void
    {
        // Forth updates are summary-only notes, so the original's transcript note
        // must still be retried even after an update landed.
        $key = $this->key();
        transcript_target_store($key, ['provider' => 'forth']); // no client_id → apply fails, not dropped
        transcript_should_apply($key, 'tid-6', null);
        transcript_retry_store('dial', $key, 1, ['transcript_id' => 'tid-6', 'summary' => null], false);
        transcript_should_apply($key, 'tid-6', 'Newer summary.');

        $stats = transcript_retry_run(time() + 100000);
        $this->assertSame(0, $stats['dropped'], 'not treated as superseded');
        $this->assertSame(1, $stats['failed'], 'attempted, then rescheduled');
    }

    #[Test]
    public function retry_is_dropped_when_target_is_gone(): void
    {
        transcript_retry_store('dial', 'd' . str_repeat('0', 40), 1, ['transcript_id' => 'tid-7'], false);
        $this->assertSame(1, transcript_retry_run(time() + 100000)['dropped']);
        $this->assertSame([], $this->retryFiles());
    }

    #[Test]
    public function oversized_retry_is_refused(): void
    {
        $key = $this->key();
        $big = ['transcript_id' => 'tid-8', 'transcript' => [['text' => str_repeat('x', TRANSCRIPT_RETRY_MAX_BYTES)]]];
        $this->assertFalse(transcript_retry_store('dial', $key, 1, $big, false));
        $this->assertSame([], $this->retryFiles());
    }

    // --- retention + signature -------------------------------------------

    #[Test]
    public function cleanup_removes_only_files_older_than_cutoff(): void
    {
        $old = $this->key();
        $new = $this->key();
        transcript_target_store($old, ['provider' => 'close']);
        transcript_target_store($new, ['provider' => 'close']);
        @touch(transcript_target_path($old), time() - 100000);

        $this->assertGreaterThanOrEqual(1, transcript_targets_cleanup(3600));
        $this->assertNull(transcript_target_load($old), 'stale target should be swept');
        $this->assertIsArray(transcript_target_load($new), 'fresh target should survive');
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
}
