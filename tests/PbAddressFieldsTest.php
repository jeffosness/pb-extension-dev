<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for pb_address_fields() — maps a provider-normalized address to
 * PhoneBurner's /dialsession contact keys.
 *
 * PB applies each address key on a matched record as `$params[key] ?? existing`,
 * so sending an empty key CLEARS that field and omitting it KEEPS it. The rules
 * locked in here:
 *   - a part the CRM returned (even blank) is sent → the CRM wins;
 *   - a part the CRM didn't return is omitted → never wipe what PB alone holds;
 *   - no non-empty part at all → nothing sent;
 *   - state_other is never sent.
 */
final class PbAddressFieldsTest extends TestCase
{
    #[Test]
    public function full_address_maps_to_pb_keys(): void
    {
        $out = pb_address_fields([
            'address1' => '123 Main St', 'address2' => 'Suite 4', 'city' => 'Denver',
            'state' => 'CO', 'zip' => '80202', 'country' => 'US',
        ]);
        $this->assertSame([
            'address1' => '123 Main St', 'address2' => 'Suite 4', 'city' => 'Denver',
            'state' => 'CO', 'zip' => '80202', 'country' => 'US',
        ], $out);
    }

    #[Test]
    public function returned_blank_parts_are_sent_to_clear_pb(): void
    {
        $out = pb_address_fields(['address1' => null, 'city' => 'Boise', 'zip' => '']);
        $this->assertSame(['address1' => '', 'city' => 'Boise', 'zip' => ''], $out);
    }

    #[Test]
    public function parts_the_crm_did_not_return_are_omitted(): void
    {
        $out = pb_address_fields(['city' => 'Boise']);
        $this->assertSame(['city' => 'Boise'], $out);
        $this->assertArrayNotHasKey('address2', $out);
        $this->assertArrayNotHasKey('state_other', $out);
    }

    #[Test]
    public function no_address_data_sends_nothing(): void
    {
        $this->assertSame([], pb_address_fields([]));
        $this->assertSame([], pb_address_fields(['city' => '   ', 'zip' => '', 'address1' => null]));
        $this->assertSame([], pb_address_fields(null));
        $this->assertSame([], pb_address_fields('123 Main St'));
    }

    #[Test]
    public function full_us_state_name_becomes_code(): void
    {
        $this->assertSame('CA', pb_address_fields(['state' => 'California'])['state']);
        $this->assertSame('NY', pb_address_fields(['state' => 'new york', 'country' => 'USA'])['state']);
        $this->assertSame('TX', pb_address_fields(['state' => 'tx'])['state']);
    }

    #[Test]
    public function state_is_not_normalized_for_non_us_country(): void
    {
        $this->assertSame('wa', pb_address_fields(['state' => 'wa', 'country' => 'Australia'])['state']);
        $this->assertSame('Ontario', pb_address_fields(['state' => 'Ontario'])['state']);
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
    public function non_scalar_values_become_blank(): void
    {
        $out = pb_address_fields(['address1' => ['nested'], 'city' => 'Austin']);
        $this->assertSame('', $out['address1']);
        $this->assertSame('Austin', $out['city']);
    }

    #[Test]
    public function counts_contacts_carrying_an_address(): void
    {
        $n = pb_count_contacts_with_addr([
            ['first_name' => 'A', 'city' => 'Denver'],
            ['first_name' => 'B'],
            ['first_name' => 'C', 'address1' => ''],
        ]);
        $this->assertSame(2, $n);
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
            'addr_sent' => 3,
            'lead_addr_fetch' => ['ok' => 1],
        ]);
        foreach (['address', 'address1', 'city', 'zip', 'state_other'] as $k) {
            $this->assertSame('[REDACTED]', $out[$k], $k);
        }
        $this->assertSame('oauth-state-ok', $out['state']);
        $this->assertSame(3, $out['addr_sent'], 'diagnostic counter must survive redaction');
        $this->assertSame(['ok' => 1], $out['lead_addr_fetch']);
    }
}
