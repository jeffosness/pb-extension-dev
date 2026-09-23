<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the $fields/$base collision guard in api_log / _pb_write_api_log
 * (#242, added 2026-09-23).
 *
 * Background: both loggers build the line via `$base + $fields` — PHP's array
 * UNION operator, which keeps LEFT values on key collision. Without the guard,
 * a caller passing ['path' => $filesystemPath] gets their value silently
 * dropped because $base['path'] (URI) always wins. Codex caught 3 real
 * instances of this in PR #241.
 *
 * These tests exercise utils.php's `_pb_write_api_log` fallback path (which
 * runs when bootstrap.php is not loaded — the tests/bootstrap.php test
 * harness deliberately doesn't load bootstrap.php, so this is the branch
 * we can test in-process). The bootstrap.php version has the same guard,
 * kept in sync via inline "KEEP IN SYNC" comment on both sites.
 *
 * If EITHER version regresses, the class of bug Codex caught on PR #241
 * comes back.
 */
final class FieldCollisionGuardTest extends TestCase
{
    private string $logDir;
    private string $logFile;

    protected function setUp(): void
    {
        // Fresh temp log dir per test so nothing leaks between them.
        $this->logDir = sys_get_temp_dir() . '/field_collision_test_' . bin2hex(random_bytes(4));
        mkdir($this->logDir, 0700, true);
        $this->logFile = $this->logDir . '/api.log';

        // Force _pb_write_api_log's fallback to write here (defined = constant
        // — set only for this test run, no other tests touch it).
        if (!defined('PB_LOG_DIR')) {
            define('PB_LOG_DIR', $this->logDir);
        }
        // If a prior test in this suite ran and defined PB_LOG_DIR to a
        // different path, we'd get cross-contamination. Guard against that
        // by writing to $this->logDir via the define path — first test
        // wins the constant. This test file should be the only user of
        // PB_LOG_DIR in the suite.
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        if (is_dir($this->logDir)) {
            @rmdir($this->logDir);
        }
    }

    /**
     * Read the last log entry written to the temp file and return it decoded.
     * Returns null if the file doesn't exist or has no entries.
     *
     * The log file may not be under $this->logDir if a prior test claimed
     * PB_LOG_DIR first — read from wherever PB_LOG_DIR points.
     */
    private function readLastEntry(): ?array
    {
        $file = rtrim(PB_LOG_DIR, '/\\') . '/api.log';
        if (!is_file($file)) return null;
        $lines = array_values(array_filter(
            explode(PHP_EOL, file_get_contents($file)),
            fn($l) => trim($l) !== ''
        ));
        if (empty($lines)) return null;
        return json_decode(end($lines), true);
    }

    #[Test]
    public function payload_with_no_collisions_has_no_marker(): void
    {
        _pb_write_api_log('test.no_collision', [
            'user_id' => 42,
            'crm'     => 'hubspot',
            'ok'      => true,
        ]);

        $entry = $this->readLastEntry();
        $this->assertNotNull($entry);
        $this->assertSame('test.no_collision', $entry['event']);
        $this->assertArrayNotHasKey('_field_collisions', $entry);
        $this->assertSame(42, $entry['user_id']);
        $this->assertSame('hubspot', $entry['crm']);
    }

    #[Test]
    public function single_path_collision_is_marked(): void
    {
        // This is the exact class of bug Codex caught on PR #241 —
        // caller uses 'path' for filesystem path, gets silently dropped.
        _pb_write_api_log('test.path_collision', [
            'path'       => '/opt/pb-extension/tokens/x.json',
            'safe_field' => 'preserved',
        ]);

        $entry = $this->readLastEntry();
        $this->assertNotNull($entry);
        $this->assertSame('test.path_collision', $entry['event']);
        $this->assertArrayHasKey('_field_collisions', $entry);
        $this->assertSame(['path'], $entry['_field_collisions']);

        // The caller's value IS silently discarded (that's what $base + $fields
        // does — we're not preventing it, we're just SIGNALING it happened).
        // $base['path'] wins the merge; in test context the URI is unset so
        // it stays null. Key exists in the output but value is null (verified
        // by assertArrayHasKey + assertNull rather than ??-null-coalescing
        // which treats null and missing the same).
        $this->assertArrayHasKey('path', $entry);
        $this->assertNull($entry['path']);
        // Critically, the caller's filesystem path did NOT survive:
        $this->assertNotSame('/opt/pb-extension/tokens/x.json', $entry['path']);

        // Non-colliding fields still land.
        $this->assertSame('preserved', $entry['safe_field']);
    }

    #[Test]
    public function multiple_collisions_all_listed_in_marker(): void
    {
        _pb_write_api_log('test.multi', [
            'path'       => 'A',
            'ip'         => 'B',
            'event'      => 'C',
            'safe_key'   => 'D',
        ]);

        $entry = $this->readLastEntry();
        $this->assertNotNull($entry);

        $this->assertArrayHasKey('_field_collisions', $entry);
        // Order should match array_intersect_key's — which preserves $fields' order.
        sort($entry['_field_collisions']);
        $this->assertSame(['event', 'ip', 'path'], $entry['_field_collisions']);

        // $base 'event' (which is the log's own event name) wins.
        $this->assertSame('test.multi', $entry['event']);

        // Non-colliding key passes through.
        $this->assertSame('D', $entry['safe_key']);
    }

    #[Test]
    public function every_reserved_base_key_is_detected(): void
    {
        // Regression guard: if the base shape gains a new reserved key,
        // this test needs to be updated. That's the intent — force
        // future contributors to notice.
        $reserved = ['ts', 'request_id', 'event', 'duration_ms', 'ip', 'method', 'path'];
        $fields = [];
        foreach ($reserved as $k) $fields[$k] = "caller_value_for_$k";

        _pb_write_api_log('test.every_reserved', $fields);

        $entry = $this->readLastEntry();
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('_field_collisions', $entry);

        $detected = $entry['_field_collisions'];
        sort($detected);
        $expected = $reserved;
        sort($expected);
        $this->assertSame($expected, $detected,
            'Every reserved $base key must be detected as a collision');
    }
}
