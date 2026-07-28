<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for describe_api_failure() — the shared descriptor that extracts a
 * provider's error text from an HTTP response for structured logging + popup
 * surfacing. See utils.php and LESSONS.md 2026-07-27.
 *
 * This is a pure function (no side effects) so it lends itself to cheap unit
 * coverage. Regressions to the message-extraction cascade (which shape wins,
 * how empty falls through) or to the token-scrubbing regex would silently
 * degrade our diagnostic story or, worse, leak an OAuth token to Loggly.
 */
final class DescribeApiFailureTest extends TestCase
{
    #[Test]
    public function pb_error_message_shape(): void
    {
        // PhoneBurner returns {"error":{"message":"..."}}
        $result = describe_api_failure(
            ['http_code' => 400, 'raw_body' => ''],
            ['error' => ['message' => 'You have an active dial session']]
        );
        $this->assertSame(400, $result['status']);
        $this->assertSame('You have an active dial session', $result['message']);
        $this->assertSame('', $result['body_snippet']);
    }

    #[Test]
    public function oauth_error_description_shape(): void
    {
        // OAuth 2.0 spec — HubSpot/Close/Apollo token endpoints use this.
        $result = describe_api_failure(
            ['http_code' => 400, 'raw_body' => ''],
            ['error' => 'invalid_grant', 'error_description' => 'refresh_token is expired']
        );
        // error_description wins over the string 'error' when both present.
        $this->assertSame('refresh_token is expired', $result['message']);
    }

    #[Test]
    public function hubspot_message_shape(): void
    {
        // HubSpot general — {"message":"..."}
        $result = describe_api_failure(
            ['http_code' => 401, 'raw_body' => ''],
            ['message' => 'Authorization header is malformed']
        );
        $this->assertSame('Authorization header is malformed', $result['message']);
    }

    #[Test]
    public function bare_string_error_shape(): void
    {
        // Close-style {"error":"some string"} without a wrapping object.
        $result = describe_api_failure(
            ['http_code' => 401, 'raw_body' => ''],
            ['error' => 'invalid_token']
        );
        $this->assertSame('invalid_token', $result['message']);
    }

    #[Test]
    public function unknown_shape_yields_empty_message(): void
    {
        // Novel shape we haven't mapped — message empty, response still logged.
        $result = describe_api_failure(
            ['http_code' => 400, 'raw_body' => ''],
            ['errors' => [['code' => 'BAD_REQUEST']]]  // plural, array — not mapped
        );
        $this->assertSame('', $result['message']);
        // The full decoded body still lands in 'response' for post-hoc triage.
        $this->assertSame(['errors' => [['code' => 'BAD_REQUEST']]], $result['response']);
    }

    #[Test]
    public function json_decode_failure_populates_body_snippet(): void
    {
        // Provider returned non-JSON — raw body is our only clue.
        $result = describe_api_failure(
            ['http_code' => 502, 'raw_body' => '<html><body>Bad Gateway</body></html>'],
            null
        );
        $this->assertNull($result['response']);
        $this->assertStringContainsString('Bad Gateway', $result['body_snippet']);
    }

    #[Test]
    public function body_snippet_scrubs_json_access_token(): void
    {
        // If a 200-OK OAuth response fails to decode (e.g. BOM prefix, HTML
        // wrapper, mid-stream truncation), the raw body could contain a real
        // access token. Snippet must scrub it before landing in Loggly.
        $raw = '{"access_token":"sk_live_realtokenABCXYZ123","refresh_token":"rt_realvalue987","expires_in":3600}';
        $result = describe_api_failure(
            ['http_code' => 200, 'raw_body' => $raw],
            null  // decode failed
        );
        $this->assertStringNotContainsString('sk_live_realtokenABCXYZ123', $result['body_snippet']);
        $this->assertStringNotContainsString('rt_realvalue987', $result['body_snippet']);
        $this->assertStringContainsString('[REDACTED]', $result['body_snippet']);
    }

    #[Test]
    public function body_snippet_scrubs_bearer_and_query_string_tokens(): void
    {
        // Some providers echo request tokens back in error messages.
        $raw = 'Authorization: Bearer sk_test_ThisIsTwentyOrMoreCharsHere123 was rejected. Try access_token=abcdefghijklmnopqrstuvwxyz.';
        $result = describe_api_failure(
            ['http_code' => 401, 'raw_body' => $raw],
            null
        );
        $this->assertStringNotContainsString('sk_test_ThisIsTwentyOrMoreCharsHere123', $result['body_snippet']);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $result['body_snippet']);
        $this->assertStringContainsString('[REDACTED_TOKEN]', $result['body_snippet']);
    }

    #[Test]
    public function body_snippet_truncated_to_500_chars(): void
    {
        // Long HTML error pages don't drag the log line to megabytes.
        $raw = str_repeat('A', 5000);
        $result = describe_api_failure(
            ['http_code' => 502, 'raw_body' => $raw],
            null
        );
        $this->assertSame(500, strlen($result['body_snippet']));
    }

    #[Test]
    public function message_truncated_and_control_chars_stripped(): void
    {
        // Provider-controlled text is untrusted; we clean it before surfacing.
        $decoded = ['message' => "line1\nline2\twith\r\ntab and control " . str_repeat('x', 300)];
        $result = describe_api_failure(['http_code' => 400, 'raw_body' => ''], $decoded);
        // 200 chars + "…" (UTF-8 ellipsis = 3 bytes). strlen returns bytes.
        $this->assertLessThanOrEqual(203, strlen($result['message']));
        $this->assertStringNotContainsString("\n", $result['message']);
        $this->assertStringNotContainsString("\t", $result['message']);
        $this->assertStringNotContainsString("\r", $result['message']);
    }

    #[Test]
    public function empty_info_and_null_decoded(): void
    {
        // Belt-and-suspenders: null-safe defaults.
        $result = describe_api_failure(null, null);
        $this->assertSame(0, $result['status']);
        $this->assertSame('', $result['message']);
        $this->assertNull($result['response']);
        $this->assertSame('', $result['body_snippet']);
        $this->assertSame('', $result['curl_error']);
    }

    #[Test]
    public function curl_error_surfaced(): void
    {
        // Network failure — pb_api_call / http_post_form_info write curl_error
        // into $info for the descriptor to pick up.
        $result = describe_api_failure(
            ['http_code' => 0, 'curl_error' => 'Could not resolve host: api.example.com', 'raw_body' => ''],
            null
        );
        $this->assertSame('Could not resolve host: api.example.com', $result['curl_error']);
        $this->assertSame(0, $result['status']);
    }

    #[Test]
    public function response_field_uses_redact_pii_when_available(): void
    {
        // redact_pii_recursive lives in api/core/bootstrap.php. tests/bootstrap.php
        // does NOT load bootstrap.php, so redact_pii_recursive is missing here —
        // the graceful fallback keeps the decoded body as-is. Real endpoints
        // (which DO load bootstrap.php) get the scrubbed shape. Confirming both
        // branches are safe: no fatal, no null on the fallback.
        $decoded = ['error' => 'nope', 'email' => 'someone@example.com'];
        $result = describe_api_failure(['http_code' => 400, 'raw_body' => ''], $decoded);
        // In this test context we expect the un-redacted array (no bootstrap loaded).
        $this->assertIsArray($result['response']);
        $this->assertArrayHasKey('error', $result['response']);
    }
}
