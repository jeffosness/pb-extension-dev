<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for redact_pii_recursive() — the function that scrubs sensitive
 * fields out of log payloads before they land in api.log.
 *
 * IMPORTANT: this exercises the utils.php FALLBACK definition, not the
 * canonical version in bootstrap.php (tests/bootstrap.php intentionally
 * does not load api/core/bootstrap.php — see the comment there). The two
 * definitions must stay in sync; sync-drift caused a real BLOCKER before
 * (LESSONS.md 2026-08-02). If either version's deny-list changes,
 * mirror it in both.
 *
 * The specific class of bug these tests lock in: array_walk_recursive()
 * only fires on non-array leaves, so any deny-list wrapper key pointing
 * at a nested array was silently passing through unredacted before the
 * 2026-09-23 rewrite (Codex caught it on PR #244). Every "wrapper" test
 * below is a regression guard for that.
 */
final class RedactPiiRecursiveTest extends TestCase
{
    #[Test]
    public function scalar_value_at_deny_key_is_redacted(): void
    {
        $out = redact_pii_recursive(['raw' => 'sensitive body', 'other' => 'ok']);
        $this->assertSame('[REDACTED]', $out['raw']);
        $this->assertSame('ok', $out['other']);
    }

    #[Test]
    public function pattern_match_on_scalar_is_redacted(): void
    {
        $out = redact_pii_recursive(['user_email' => 'a@b.com', 'phone_number' => '555-1234']);
        $this->assertSame('[REDACTED]', $out['user_email']);
        $this->assertSame('[REDACTED]', $out['phone_number']);
    }

    #[Test]
    public function nested_array_under_wrapper_key_is_fully_redacted(): void
    {
        // This is the exact class of leak Codex caught on 2026-09-23.
        // array_walk_recursive would have walked INTO the array and only
        // seen the leaf 'submitted' => 'Jane Doe' — not matching any deny
        // pattern — so the customer name would have landed in api.log raw.
        // Post-rewrite, the wrapper key `close_error` matches and the
        // whole subtree collapses.
        $out = redact_pii_recursive([
            'close_error' => ['submitted' => 'Jane Doe', 'field' => 'phone'],
        ]);
        $this->assertSame('[REDACTED]', $out['close_error']);
    }

    #[Test]
    public function all_new_provider_error_wrappers_redact(): void
    {
        foreach (['close_error', 'apollo_error', 'forth_error', 'hubspot_error'] as $wrapper) {
            $out = redact_pii_recursive([$wrapper => ['nested' => 'leak']]);
            $this->assertSame('[REDACTED]', $out[$wrapper], "wrapper key $wrapper should have redacted");
        }
    }

    #[Test]
    public function pre_existing_payload_wrapper_now_actually_redacts_arrays(): void
    {
        // Pre-rewrite this was broken: 'payload' was in $denyKeys but if
        // its value was an array, array_walk_recursive walked in and never
        // triggered the callback with $key === 'payload'.
        $out = redact_pii_recursive([
            'payload' => ['first_name' => 'X', 'phone' => 'Y', 'safe_field' => 'Z'],
        ]);
        $this->assertSame('[REDACTED]', $out['payload']);
    }

    #[Test]
    public function record_url_body_snippet_and_raw_are_all_deny_listed(): void
    {
        $out = redact_pii_recursive([
            'record_url'   => 'https://app.close.com/lead/lead_ABC123/',
            'body_snippet' => '<html>error page</html>',
            'raw'          => 'huge payload',
        ]);
        $this->assertSame('[REDACTED]', $out['record_url']);
        $this->assertSame('[REDACTED]', $out['body_snippet']);
        $this->assertSame('[REDACTED]', $out['raw']);
    }

    #[Test]
    public function provider_msg_is_not_redacted(): void
    {
        // Deliberate scope decision (see PR #244 body + LESSONS.md):
        // provider_msg is the human-readable error from describe_api_failure,
        // already token-scrubbed by _pb_scrub_tokens. Full-redacting would
        // destroy the diagnostic value that motivates the field's existence.
        $out = redact_pii_recursive(['provider_msg' => 'invalid_grant']);
        $this->assertSame('invalid_grant', $out['provider_msg']);
    }

    #[Test]
    public function unrelated_arrays_pass_through_unchanged(): void
    {
        $out = redact_pii_recursive([
            'crm_name'    => 'hubspot',
            'has_agent'   => true,
            'stats'       => ['completed' => 5, 'failed' => 0],
        ]);
        $this->assertSame('hubspot', $out['crm_name']);
        $this->assertTrue($out['has_agent']);
        $this->assertSame(5, $out['stats']['completed']);
        $this->assertSame(0, $out['stats']['failed']);
    }

    #[Test]
    public function deep_nested_sensitive_leaf_still_gets_scrubbed(): void
    {
        // Nested case where the WRAPPER key is safe but a deep leaf matches
        // a deny pattern. Should still redact via the recursive walk.
        $out = redact_pii_recursive([
            'session' => [
                'meta' => [
                    'access_token' => 'sk_xxx',
                    'label'        => 'ok',
                ],
            ],
        ]);
        $this->assertSame('[REDACTED]', $out['session']['meta']['access_token']);
        $this->assertSame('ok', $out['session']['meta']['label']);
    }

    #[Test]
    public function empty_and_scalar_top_level_are_safe(): void
    {
        $this->assertSame([], redact_pii_recursive([]));
    }
}
