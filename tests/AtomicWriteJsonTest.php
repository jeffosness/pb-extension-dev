<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for atomic_write_json() — the function that writes every token file
 * and every session file. Two things it MUST get right:
 *   1. Restrictive permissions (0600 file, 0700 dir).
 *   2. Atomic replacement (write-to-temp then rename, no half-written files
 *      on disk if the process dies mid-write).
 *
 * Permission assertions are skipped on Windows because chmod semantics differ.
 * CI runs on Linux so the real prod-parity check happens there.
 */
final class AtomicWriteJsonTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/atomic_write_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            $this->rmrf($this->baseDir);
        }
    }

    #[Test]
    public function writes_json_to_the_target_path(): void
    {
        $path = $this->baseDir . '/token.json';
        atomic_write_json($path, ['pat' => 'redacted', 'saved_at' => '2026-07-02T00:00:00Z']);

        $this->assertFileExists($path);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertSame('redacted', $decoded['pat']);
        $this->assertSame('2026-07-02T00:00:00Z', $decoded['saved_at']);
    }

    #[Test]
    public function creates_missing_directories_with_secure_permissions(): void
    {
        // Nested directory path that doesn't exist yet.
        $path = $this->baseDir . '/nested/dir/token.json';

        atomic_write_json($path, ['x' => 1]);

        $this->assertDirectoryExists(dirname($path));

        if (DIRECTORY_SEPARATOR === '/') {
            // On POSIX, ensure_dir_secure should set 0700.
            $mode = fileperms(dirname($path)) & 0777;
            $this->assertSame(0700, $mode, 'directory should be 0700');
        } else {
            $this->markTestSkipped('POSIX permission check only meaningful on Linux/macOS');
        }
    }

    #[Test]
    public function final_file_has_owner_only_permissions(): void
    {
        $path = $this->baseDir . '/token.json';
        atomic_write_json($path, ['secret' => 'stays put']);

        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($path) & 0777;
            $this->assertSame(0600, $mode, 'atomic_write_json must produce 0600 files');
        } else {
            $this->markTestSkipped('POSIX permission check only meaningful on Linux/macOS');
        }
    }

    #[Test]
    public function overwriting_an_existing_file_keeps_permissions_tight(): void
    {
        $path = $this->baseDir . '/token.json';

        // First write.
        atomic_write_json($path, ['v' => 1]);
        // Second write, replacing content.
        atomic_write_json($path, ['v' => 2, 'new_field' => 'hello']);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertSame(2, $decoded['v']);
        $this->assertSame('hello', $decoded['new_field']);

        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($path) & 0777;
            $this->assertSame(0600, $mode, 'after overwrite, file must still be 0600');
        }
    }

    #[Test]
    public function does_not_leave_temp_files_behind(): void
    {
        // atomic_write_json uses tempnam($dir, 'tmp_') internally then renames.
        // A successful call must not leave the tmp_* file lying around.
        $path = $this->baseDir . '/token.json';
        atomic_write_json($path, ['ok' => true]);

        $leftover = glob(dirname($path) . '/tmp_*');
        $this->assertSame([], $leftover, 'no tmp_* leftover files after a successful write');
    }

    #[Test]
    public function json_output_is_valid_and_round_trips(): void
    {
        $path = $this->baseDir . '/complex.json';
        $data = [
            'nested' => ['a' => 1, 'b' => [2, 3, 4]],
            'strings' => 'unicode ✓ ⛰',
            'null_val' => null,
            'bool_val' => true,
        ];

        atomic_write_json($path, $data);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertSame($data, $decoded, 'round-trip must be lossless');
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
