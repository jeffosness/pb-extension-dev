<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the CTC intent bridge helpers (ctc_intent_write / _consume /
 * _prune_stale / _sweep_stale_files / _normalize_phone).
 *
 * These are the bridge between the extension-side CLICK_TO_CALL event
 * (which knows client_id + task_id) and the softphone_call_done webhook
 * (which knows pb_user_id + phone). Two invariants they MUST enforce:
 *
 *   1. FIFO — consuming returns intents in the order they were written.
 *      PhoneBurner's softphone is single-call-per-agent, so calls
 *      disposition in dial order; the consumer must match that.
 *   2. Cleanup without cron — every write prunes stale entries from the
 *      same-key file, and the consume path deletes the file when the
 *      queue empties. Sweep-on-write handles orphans across other keys.
 *
 * Intent files live under TOKENS_DIR/ctc_intents/. We tear down every
 * file we touch to keep test runs isolated.
 */
final class CtcIntentTest extends TestCase
{
    /** @var array<int, string> */
    private array $touchedKeys = [];

    protected function tearDown(): void
    {
        // Clean up any (pb_user_id, phone) files we touched, even if a
        // test aborted mid-run.
        foreach ($this->touchedKeys as $key) {
            [$pbUserId, $phone] = explode('|', $key, 2);
            $path = ctc_intent_file_path($pbUserId, $phone);
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
        $this->touchedKeys = [];
    }

    /** Register a key for cleanup, then return its file path. */
    private function track(string $pbUserId, string $phone): string
    {
        $this->touchedKeys[] = $pbUserId . '|' . $phone;
        return ctc_intent_file_path($pbUserId, $phone);
    }

    #[Test]
    public function normalize_phone_strips_formatting(): void
    {
        $this->assertSame('19012954326', ctc_normalize_phone('+1 (901) 295-4326'));
        $this->assertSame('9012954326',  ctc_normalize_phone('9012954326'));
        $this->assertSame('',            ctc_normalize_phone(''));
        $this->assertSame('',            ctc_normalize_phone('--'));
    }

    #[Test]
    public function file_path_uses_hashed_pb_user_id_not_raw(): void
    {
        $path = ctc_intent_file_path('21791', '+1 (901) 295-4326');
        $this->assertNotNull($path);
        // Filename must NOT contain the raw pb_user_id.
        $this->assertStringNotContainsString('21791', basename($path));
        // Filename MUST contain the normalized phone (public key material).
        $this->assertStringContainsString('19012954326', basename($path));
    }

    #[Test]
    public function file_path_returns_null_for_empty_inputs(): void
    {
        $this->assertNull(ctc_intent_file_path('', '5551234567'));
        $this->assertNull(ctc_intent_file_path('21791', ''));
        $this->assertNull(ctc_intent_file_path('21791', '--')); // normalizes to ''
    }

    #[Test]
    public function write_returns_false_on_empty_client_id_or_task_id(): void
    {
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $phone    = '5551234567';
        $this->track($pbUserId, $phone);

        $this->assertFalse(ctc_intent_write($pbUserId, $phone, '', 'task_1'));
        $this->assertFalse(ctc_intent_write($pbUserId, $phone, 'cid_1', ''));
    }

    #[Test]
    public function write_then_consume_round_trips_intent(): void
    {
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $phone    = '5551234567';
        $this->track($pbUserId, $phone);

        $this->assertTrue(ctc_intent_write($pbUserId, $phone, 'cid_A', 'task_A'));

        $popped = ctc_intent_consume($pbUserId, $phone);
        $this->assertIsArray($popped);
        $this->assertSame('cid_A',  $popped['client_id']);
        $this->assertSame('task_A', $popped['task_id']);
    }

    #[Test]
    public function consume_returns_null_for_unknown_key(): void
    {
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $phone    = '9999999999';
        // Nothing written under this key.
        $this->assertNull(ctc_intent_consume($pbUserId, $phone));
    }

    #[Test]
    public function consume_deletes_file_when_queue_empties(): void
    {
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $phone    = '5551234567';
        $path     = $this->track($pbUserId, $phone);

        ctc_intent_write($pbUserId, $phone, 'cid_1', 'task_1');
        $this->assertFileExists($path);

        ctc_intent_consume($pbUserId, $phone);
        $this->assertFileDoesNotExist($path, 'file must be removed after last entry is consumed');
    }

    #[Test]
    public function fifo_order_across_same_key(): void
    {
        // The invariant that makes the "same phone across two back-to-
        // back CTC clicks" scenario correct — the older intent must be
        // consumed first so the older task closes when its call
        // dispositions first.
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $phone    = '5551234567';
        $this->track($pbUserId, $phone);

        ctc_intent_write($pbUserId, $phone, 'cid_A', 'task_A');
        ctc_intent_write($pbUserId, $phone, 'cid_B', 'task_B');
        ctc_intent_write($pbUserId, $phone, 'cid_C', 'task_C');

        $this->assertSame('task_A', ctc_intent_consume($pbUserId, $phone)['task_id']);
        $this->assertSame('task_B', ctc_intent_consume($pbUserId, $phone)['task_id']);
        $this->assertSame('task_C', ctc_intent_consume($pbUserId, $phone)['task_id']);
        $this->assertNull(ctc_intent_consume($pbUserId, $phone));
    }

    #[Test]
    public function distinct_keys_do_not_collide(): void
    {
        // Different phones under the same pb_user_id must not share a queue.
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $this->track($pbUserId, '5551111111');
        $this->track($pbUserId, '5552222222');

        ctc_intent_write($pbUserId, '5551111111', 'cid_1', 'task_1');
        ctc_intent_write($pbUserId, '5552222222', 'cid_2', 'task_2');

        $this->assertSame('task_1', ctc_intent_consume($pbUserId, '5551111111')['task_id']);
        $this->assertSame('task_2', ctc_intent_consume($pbUserId, '5552222222')['task_id']);
    }

    #[Test]
    public function different_phone_formats_collapse_to_same_key(): void
    {
        // A dial started as +1 (555) 123-4567 and a webhook that lands as
        // 15551234567 must match — normalize_phone strips formatting on both
        // sides, so keying is on digits only.
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $this->track($pbUserId, '5551234567');

        ctc_intent_write($pbUserId, '+1 (555) 123-4567', 'cid_X', 'task_X');
        $popped = ctc_intent_consume($pbUserId, '15551234567');
        $this->assertIsArray($popped);
        $this->assertSame('task_X', $popped['task_id']);
    }

    #[Test]
    public function stale_entries_are_pruned_on_write(): void
    {
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $phone    = '5551234567';
        $path     = $this->track($pbUserId, $phone);

        // Manually write a stale intent (minted_at way in the past). We can't
        // reach into ctc_intent_write because it stamps the current time.
        $stale = [[
            'client_id' => 'cid_STALE',
            'task_id'   => 'task_STALE',
            'minted_at' => time() - (25 * 3600), // 25h ago — past 24h TTL
        ]];
        atomic_write_json($path, $stale);

        // A fresh write should prune the stale entry AND append the new one.
        ctc_intent_write($pbUserId, $phone, 'cid_FRESH', 'task_FRESH');

        $popped = ctc_intent_consume($pbUserId, $phone);
        $this->assertIsArray($popped);
        $this->assertSame('task_FRESH', $popped['task_id']);
        // Nothing else should remain — the stale one was pruned.
        $this->assertNull(ctc_intent_consume($pbUserId, $phone));
    }

    #[Test]
    public function stale_entries_are_pruned_on_consume(): void
    {
        // If a file only contains stale entries, consume returns null and
        // removes the file. This is the "customer clicked CTC 3 days ago
        // and never dialed" recovery path.
        $pbUserId = 'u_' . bin2hex(random_bytes(4));
        $phone    = '5551234567';
        $path     = $this->track($pbUserId, $phone);

        $stale = [[
            'client_id' => 'cid_STALE',
            'task_id'   => 'task_STALE',
            'minted_at' => time() - (25 * 3600),
        ]];
        atomic_write_json($path, $stale);
        $this->assertFileExists($path);

        $this->assertNull(ctc_intent_consume($pbUserId, $phone));
        $this->assertFileDoesNotExist($path);
    }
}
