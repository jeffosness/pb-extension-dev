<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Direct tests for _pb_scrub_tokens() — the shared token-redaction helper
 * used at every boundary where provider response text may reach a customer's
 * popup, a support ticket screenshot, or a log file.
 *
 * describe_api_failure exercises this helper indirectly, but as of 2026-08-03
 * (adversarial review round 6) it's called from 4 sites across 3 files. Direct
 * tests here so a regex regression doesn't slip through if a future refactor
 * happens to leave describe_api_failure's callers passing.
 */
final class PbScrubTokensTest extends TestCase
{
    #[Test]
    public function empty_string_returns_empty(): void
    {
        $this->assertSame('', _pb_scrub_tokens(''));
    }

    #[Test]
    public function passes_through_text_with_no_tokens(): void
    {
        $input = 'The customer sent an empty selection.';
        $this->assertSame($input, _pb_scrub_tokens($input));
    }

    #[Test]
    public function scrubs_bearer_header_shape(): void
    {
        $out = _pb_scrub_tokens('Authorization: Bearer pat-na1-XXXXX-YYYYY-ZZZZZ was rejected.');
        $this->assertStringNotContainsString('pat-na1-XXXXX-YYYYY-ZZZZZ', $out);
        $this->assertStringContainsString('[REDACTED_TOKEN]', $out);
    }

    #[Test]
    public function scrubs_query_string_access_token(): void
    {
        $out = _pb_scrub_tokens('Redirect URI included access_token=abc123def456ghi789jkl012 as a query param.');
        $this->assertStringNotContainsString('abc123def456ghi789jkl012', $out);
        $this->assertStringContainsString('[REDACTED_TOKEN]', $out);
    }

    #[Test]
    public function scrubs_query_string_refresh_token(): void
    {
        $out = _pb_scrub_tokens('refresh_token=rt_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx expired.');
        $this->assertStringNotContainsString('rt_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx', $out);
        $this->assertStringContainsString('[REDACTED_TOKEN]', $out);
    }

    #[Test]
    public function scrubs_query_string_api_key(): void
    {
        $out = _pb_scrub_tokens('The api_key=my_secret_apollo_key_abcdefghijkl was invalid.');
        $this->assertStringNotContainsString('my_secret_apollo_key_abcdefghijkl', $out);
        $this->assertStringContainsString('[REDACTED_TOKEN]', $out);
    }

    #[Test]
    public function scrubs_json_string_access_token(): void
    {
        $out = _pb_scrub_tokens('Response: {"access_token":"real_token_here_abc","expires_in":3600}');
        $this->assertStringNotContainsString('real_token_here_abc', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }

    #[Test]
    public function scrubs_json_string_refresh_token(): void
    {
        $out = _pb_scrub_tokens('Server returned: {"refresh_token":"rt_realvalue_xyz","token_type":"bearer"}');
        $this->assertStringNotContainsString('rt_realvalue_xyz', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }

    #[Test]
    public function scrubs_json_string_api_key(): void
    {
        $out = _pb_scrub_tokens('Config: {"api_key":"secret_key_xyz","enabled":true}');
        $this->assertStringNotContainsString('secret_key_xyz', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }

    #[Test]
    public function scrubs_json_string_client_secret(): void
    {
        $out = _pb_scrub_tokens('{"client_id":"foo","client_secret":"very_secret_value_here","grant_type":"refresh_token"}');
        $this->assertStringNotContainsString('very_secret_value_here', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }

    #[Test]
    public function scrubs_multiple_tokens_in_same_string(): void
    {
        $input = 'Failed with Bearer abcdefghijklmnopqrstuvwx and access_token=xyz123456789012345678 both invalid.';
        $out = _pb_scrub_tokens($input);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwx', $out);
        $this->assertStringNotContainsString('xyz123456789012345678', $out);
    }

    #[Test]
    public function does_not_scrub_short_bearer_tokens(): void
    {
        // Regex requires 20+ chars after prefix to avoid false positives on
        // short strings that happen to look like tokens. If a real token is
        // shorter than 20 chars (unusual), it survives. Documenting behavior.
        $out = _pb_scrub_tokens('Bearer short');
        $this->assertSame('Bearer short', $out);
    }
}
