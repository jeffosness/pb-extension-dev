<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for pb_address_fields() — maps a provider-normalized address to
 * PhoneBurner's /dialsession contact keys.
 *
 * The rule these lock in is all-or-nothing: PB applies each address key on a
 * matched record as `$params[key] ?? existing`, so sending an empty key
 * CLEARS that field and omitting it KEEPS it. Any address data → all seven
 * keys; no address data → no keys (never wipe an address entered in PB).
 */
final class PbAddressFieldsTest extends TestCase
{
    #[Test]
    public function full_address_maps_to_all_pb_keys(): void
    {
        $out = pb_address_fields([
            'address1' => '123 Main St', 'address2' => 'Suite 4', 'city' => 'Denver',
            'state' => 'CO', 'zip' => '80202', 'country' => 'US',
        ]);
        $this->assertSame([
            'address1' => '123 Main St', 'address2' => 'Suite 4', 'city' => 'Denver',
            'state' => 'CO', 'state_other' => '', 'zip' => '80202', 'country' => 'US',
        ], $out);
    }

    #[Test]
    public function partial_address_sends_every_key_with_blanks(): void
    {
        $out = pb_address_fields(['city' => 'Boise']);
        $this->assertCount(7, $out);
        $this->assertSame('Boise', $out['city']);
        $this->assertSame('', $out['address1']);
        $this->assertSame('', $out['zip']);
    }

    #[Test]
    public function no_address_data_sends_nothing(): void
    {
        $this->assertSame([], pb_address_fields([]));
        $this->assertSame([], pb_address_fields(['city' => '   ', 'zip' => '']));
        $this->assertSame([], pb_address_fields(null));
        $this->assertSame([], pb_address_fields('123 Main St'));
    }

    #[Test]
    public function full_us_state_name_becomes_code(): void
    {
        $this->assertSame('CA', pb_address_fields(['state' => 'California'])['state']);
        $this->assertSame('NY', pb_address_fields(['state' => 'new york'])['state']);
        $this->assertSame('TX', pb_address_fields(['state' => 'tx'])['state']);
    }

    #[Test]
    public function non_us_state_passes_through(): void
    {
        $this->assertSame('Ontario', pb_address_fields(['state' => 'Ontario'])['state']);
        $this->assertSame('ON', pb_address_fields(['state' => 'ON'])['state']);
    }

    #[Test]
    public function us_country_spellings_normalize(): void
    {
        foreach (['USA', 'United States', 'u.s.a.', 'United States of America', 'us'] as $c) {
            $this->assertSame('US', pb_address_fields(['country' => $c])['country'], $c);
        }
        $this->assertSame('Canada', pb_address_fields(['country' => 'Canada'])['country']);
    }

    #[Test]
    public function values_are_trimmed_collapsed_and_capped(): void
    {
        $out = pb_address_fields(['address1' => "  123   Main\nSt ", 'city' => str_repeat('x', 300)]);
        $this->assertSame('123 Main St', $out['address1']);
        $this->assertSame(255, strlen($out['city']));
    }

    #[Test]
    public function non_scalar_values_are_ignored(): void
    {
        $out = pb_address_fields(['address1' => ['nested'], 'city' => 'Austin']);
        $this->assertSame('', $out['address1']);
        $this->assertSame('Austin', $out['city']);
    }

    #[Test]
    public function address_keys_are_redacted_in_logs(): void
    {
        $out = redact_pii_recursive([
            'address'  => ['city' => 'Denver'],
            'address1' => '123 Main St',
            'city'     => 'Denver',
            'zip'      => '80202',
            'state_other' => 'Ontario',
            'state'    => 'oauth-state-ok',
        ]);
        foreach (['address', 'address1', 'city', 'zip', 'state_other'] as $k) {
            $this->assertSame('[REDACTED]', $out[$k], $k);
        }
        $this->assertSame('oauth-state-ok', $out['state']);
    }
}
