<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for safe_file_path() — the function that guards every file-path
 * construction on the server against directory traversal.
 *
 * If any of these tests regress, we've reintroduced a path-traversal bug.
 * See SECURITY.md for context on what this defends against.
 */
final class SafeFilePathTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        // Fresh temp dir for each test so nothing leaks between runs.
        $this->baseDir = sys_get_temp_dir() . '/safe_file_path_test_' . bin2hex(random_bytes(4));
        mkdir($this->baseDir, 0700, true);
    }

    protected function tearDown(): void
    {
        // Recursively remove the temp dir.
        if (is_dir($this->baseDir)) {
            $this->rmrf($this->baseDir);
        }
    }

    #[Test]
    public function accepts_a_valid_child_file_name(): void
    {
        $result = safe_file_path($this->baseDir, 'client-abc.json');

        $this->assertNotNull($result, 'valid child file should be accepted');
        $this->assertStringStartsWith(realpath($this->baseDir), $result);
        $this->assertStringEndsWith('client-abc.json', $result);
    }

    #[Test]
    public function accepts_a_valid_child_that_already_exists(): void
    {
        $existing = $this->baseDir . '/existing.json';
        file_put_contents($existing, '{}');

        $result = safe_file_path($this->baseDir, 'existing.json');

        $this->assertNotNull($result);
        $this->assertSame(realpath($existing), $result);
    }

    #[Test]
    public function rejects_parent_directory_traversal(): void
    {
        // The classic attack: try to escape the base directory using ../.
        $result = safe_file_path($this->baseDir, '../../../etc/passwd');

        $this->assertNull($result, 'path traversal via ../ must be rejected');
    }

    #[Test]
    public function rejects_traversal_that_ends_inside_base_dir_but_escapes_via_parent(): void
    {
        // Even if the attacker crafts a path that eventually resolves back
        // into base, if realpath ends up NOT inside base, we must reject.
        // Here /tmp/.../safefile_test/../other-dir shouldn't be treated as
        // inside baseDir.
        $siblingDir = dirname($this->baseDir) . '/other-dir-' . bin2hex(random_bytes(4));
        mkdir($siblingDir, 0700, true);
        try {
            $result = safe_file_path($this->baseDir, '../' . basename($siblingDir) . '/anything');
            $this->assertNull($result, 'escape via ../ to a sibling directory must be rejected');
        } finally {
            $this->rmrf($siblingDir);
        }
    }

    #[Test]
    public function returns_null_when_base_dir_does_not_exist(): void
    {
        $result = safe_file_path('/nonexistent-base-dir-' . bin2hex(random_bytes(4)), 'anything.json');

        $this->assertNull($result, 'nonexistent base dir must produce null (no leakage)');
    }

    #[Test]
    public function accepts_a_nested_path_within_base(): void
    {
        mkdir($this->baseDir . '/sub/deep', 0700, true);

        $result = safe_file_path($this->baseDir, 'sub/deep/file.json');

        $this->assertNotNull($result);
        $this->assertStringStartsWith(realpath($this->baseDir), $result);
    }

    #[Test]
    public function normalizes_slashes_across_platforms(): void
    {
        // safe_file_path() uses DIRECTORY_SEPARATOR internally, so mixed
        // slashes in the input should still resolve correctly.
        $result = safe_file_path($this->baseDir, 'sub\\file.json');

        // Should resolve to a path inside baseDir regardless of the input
        // slash style. On Linux CI the backslash gets treated as literal
        // in filenames — either way, the result must NOT escape baseDir.
        if ($result !== null) {
            $this->assertStringStartsWith(realpath($this->baseDir), $result);
        }
        // We don't assert success here — different platforms handle backslashes
        // differently. What we DO assert is: it never escapes baseDir.
        $this->assertTrue(true); // sentinel — real assertion is the conditional above
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
