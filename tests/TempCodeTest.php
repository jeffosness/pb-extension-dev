<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for temp_code_store() + temp_code_retrieve_and_delete().
 *
 * These are the SSE / softphone launch code helpers. Two invariants they
 * MUST enforce:
 *   1. Single-use — retrieving a code deletes it, so replay is impossible.
 *   2. Expiration — codes older than their TTL return null (even if the
 *      file still exists on disk from a previous run).
 *
 * These functions write into cfg()['CACHE_DIR'] (or server/public/cache/ by
 * default), so we clean up per test.
 */
final class TempCodeTest extends TestCase
{
    private array $mintedCodes = [];

    protected function tearDown(): void
    {
        // Clean up any codes we minted, even if a test aborted.
        foreach ($this->mintedCodes as $code) {
            $path = $this->codePath($code);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->mintedCodes = [];
    }

    #[Test]
    public function store_returns_a_hex_string_of_length_32(): void
    {
        $code = temp_code_store('session-token-here');
        $this->mintedCodes[] = $code;

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $code);
    }

    #[Test]
    public function retrieve_returns_the_stored_session_token(): void
    {
        $token = 'my-session-token-' . bin2hex(random_bytes(8));

        $code = temp_code_store($token);
        $this->mintedCodes[] = $code;

        $result = temp_code_retrieve_and_delete($code);
        $this->assertSame($token, $result);
    }

    #[Test]
    public function retrieve_deletes_the_code_after_use(): void
    {
        $code = temp_code_store('token');
        $this->mintedCodes[] = $code;

        // First retrieve — succeeds.
        $first = temp_code_retrieve_and_delete($code);
        $this->assertNotNull($first);

        // Second retrieve — same code — must fail (single-use).
        $second = temp_code_retrieve_and_delete($code);
        $this->assertNull($second, 'temp code must be single-use: second retrieve returns null');
    }

    #[Test]
    public function retrieve_returns_null_for_unknown_code(): void
    {
        // A well-formed but never-minted code.
        $result = temp_code_retrieve_and_delete(str_repeat('0', 32));

        $this->assertNull($result);
    }

    #[Test]
    public function retrieve_returns_null_for_expired_code(): void
    {
        // Mint a code with a 1-second TTL, then wait it out.
        $code = temp_code_store('token', 1);
        $this->mintedCodes[] = $code;

        // Verify it's retrievable within TTL.
        // (We DON'T retrieve here because that would delete it; we just
        //  check the file exists.)
        $this->assertFileExists($this->codePath($code));

        // Sleep past expiry.
        sleep(2);

        $result = temp_code_retrieve_and_delete($code);
        $this->assertNull($result, 'expired code must return null');

        // Also: retrieving an expired code should delete the file.
        $this->assertFileDoesNotExist($this->codePath($code), 'expired code file should be cleaned up');
    }

    #[Test]
    public function malicious_code_input_is_sanitized_and_does_not_traverse(): void
    {
        // temp_code_file_path() sanitizes the code before building the path,
        // so a malicious code like "../../etc/passwd" must NOT resolve to
        // anything readable outside cache/.
        $result = temp_code_retrieve_and_delete('../../../etc/passwd');

        $this->assertNull($result);
    }

    #[Test]
    public function each_call_to_store_returns_a_unique_code(): void
    {
        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $code = temp_code_store('token-' . $i);
            $this->mintedCodes[] = $code;
            $this->assertArrayNotHasKey($code, $seen, "codes must be unique across calls (dup at iteration $i)");
            $seen[$code] = true;
        }
    }

    /**
     * Mirror of temp_code_file_path() so tests can find/verify code files
     * without depending on that helper's internals.
     */
    private function codePath(string $code): string
    {
        $cacheDir = cfg()['CACHE_DIR'] ?? (__DIR__ . '/../server/public/cache');
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $code);
        return rtrim($cacheDir, '/\\') . '/temp_code_' . $safe . '.json';
    }
}
