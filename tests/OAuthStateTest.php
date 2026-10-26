<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for oauth_state_create() + oauth_state_consume() in utils.php.
 *
 * These guard the HubSpot / Close / Apollo connect flows (the state used to be
 * the extension client_id itself). The invariants:
 *   1. The state never contains the client_id.
 *   2. Single use: a second redeem fails.
 *   3. Expiry: past the TTL, redeem fails even if the file still exists.
 *   4. Provider-bound: a HubSpot state can't be redeemed by the Close finish.
 *   5. Legacy / garbage states (a raw client_id, path tricks) are rejected.
 *   6. SSE temp codes and OAuth states can't be redeemed as each other.
 *
 * Files land in the temp-code cache dir; tearDown removes anything left over.
 */
final class OAuthStateTest extends TestCase
{
    private array $codes = [];

    protected function tearDown(): void
    {
        foreach ($this->codes as $code) {
            $path = temp_code_file_path($code);
            if (is_file($path)) @unlink($path);
        }
        $this->codes = [];
    }

    private function mint(string $clientId = 'client-abc_123', string $provider = 'hubspot'): string
    {
        $code = oauth_state_create($clientId, $provider);
        $this->codes[] = $code;
        return $code;
    }

    #[Test]
    public function state_is_random_hex_and_does_not_contain_client_id(): void
    {
        $clientId = '3f2a9c1e-7b4d-4e8f-9a6b-1c2d3e4f5a6b';
        $state = $this->mint($clientId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $state);
        $this->assertStringNotContainsString($clientId, $state);
        $this->assertNotSame($state, $this->mint($clientId), 'two starts must give different states');
    }

    #[Test]
    public function round_trip_returns_the_issuing_client_id(): void
    {
        $state = $this->mint('client-abc_123', 'close');
        $this->assertSame('client-abc_123', oauth_state_consume($state, 'close'));
    }

    #[Test]
    public function state_is_single_use(): void
    {
        $state = $this->mint();
        $this->assertSame('client-abc_123', oauth_state_consume($state, 'hubspot'));
        $this->assertNull(oauth_state_consume($state, 'hubspot'), 'replay must fail');
        $this->assertFileDoesNotExist(temp_code_file_path($state));
    }

    #[Test]
    public function expired_state_is_rejected_and_removed(): void
    {
        $state = $this->mint();
        $path = temp_code_file_path($state);
        $data = json_decode((string)file_get_contents($path), true);
        $data['expires_at'] = time() - 1;
        file_put_contents($path, json_encode($data));

        $this->assertNull(oauth_state_consume($state, 'hubspot'));
        $this->assertFileDoesNotExist($path, 'an expired state is still consumed (cannot be retried)');
    }

    #[Test]
    public function state_is_bound_to_its_provider(): void
    {
        $state = $this->mint('client-abc_123', 'hubspot');
        $this->assertNull(oauth_state_consume($state, 'close'));
        // Wrong-provider redeem still burns it: a probe can't be followed by the right one.
        $this->assertNull(oauth_state_consume($state, 'hubspot'));
    }

    #[Test]
    public function legacy_client_id_state_and_garbage_are_rejected(): void
    {
        // Old-style state (the client_id itself) from a link generated before deploy.
        $this->assertNull(oauth_state_consume('3f2a9c1e-7b4d-4e8f-9a6b-1c2d3e4f5a6b', 'hubspot'));
        $this->assertNull(oauth_state_consume('', 'hubspot'));
        $this->assertNull(oauth_state_consume('../../tokens/hubspot/x', 'hubspot'));
        $this->assertNull(oauth_state_consume(str_repeat('A', 32), 'hubspot'), 'uppercase hex is not a code we issue');
        $this->assertNull(oauth_state_consume(bin2hex(random_bytes(16)), 'hubspot'), 'well-formed but unknown');
    }

    #[Test]
    public function sse_temp_codes_and_oauth_states_do_not_cross_redeem(): void
    {
        // An SSE launch code must not work as an OAuth state...
        $sse = temp_code_store('some-session-token');
        $this->codes[] = $sse;
        $this->assertNull(oauth_state_consume($sse, 'hubspot'));

        // ...and an OAuth state must not work as an SSE launch code.
        $state = $this->mint();
        $this->assertNull(temp_code_retrieve_and_delete($state));
    }

    #[Test]
    public function stored_client_id_is_re_sanitized_on_redeem(): void
    {
        $state = $this->mint('ok-id-1234');
        $path = temp_code_file_path($state);
        $data = json_decode((string)file_get_contents($path), true);
        $data['client_id'] = '../evil/../id';
        file_put_contents($path, json_encode($data));

        $this->assertSame('evilid', oauth_state_consume($state, 'hubspot'));
    }

    #[Test]
    public function oversized_or_short_client_id_is_refused_before_writing(): void
    {
        $before = count(glob(dirname(temp_code_file_path('x')) . '/temp_code_*.json') ?: []);
        foreach ([str_repeat('A', 129), 'short', ''] as $bad) {
            try {
                oauth_state_create($bad, 'hubspot');
                $this->fail('expected InvalidArgumentException for length ' . strlen($bad));
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $after = count(glob(dirname(temp_code_file_path('x')) . '/temp_code_*.json') ?: []);
        $this->assertSame($before, $after, 'nothing may be written for a rejected client_id');
    }

    #[Test]
    public function no_claim_file_is_left_behind_after_consume(): void
    {
        $state = $this->mint();
        oauth_state_consume($state, 'hubspot');
        $leftovers = glob(dirname(temp_code_file_path($state)) . '/temp_code_' . $state . '*') ?: [];
        $this->assertSame([], $leftovers);
    }

    #[Test]
    public function ttl_stays_within_cron_cleanup_window(): void
    {
        // scripts/cron/pb-extension.cron.tmpl deletes temp_code_*.json older than 10 min.
        $this->assertLessThanOrEqual(600, OAUTH_STATE_TTL);
    }
}
