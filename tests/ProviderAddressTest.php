<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/../server/public/api/crm/hubspot/hs_helpers.php';
require_once __DIR__ . '/../server/public/api/crm/apollo/apollo_helpers.php';
require_once __DIR__ . '/../server/public/api/crm/forth/forth_helpers.php';
require_once __DIR__ . '/../server/public/api/crm/close/close_helpers.php';

/**
 * Each L3 provider maps its own address fields into the normalized shape
 * pb_address_fields() consumes, passing on ONLY the parts present in the
 * payload. These lock in the per-provider field names, the presence rule,
 * and the Close lead-address step's budget / stop / fan-out behaviour.
 */
final class ProviderAddressTest extends TestCase
{
    #[Test]
    public function hubspot_standard_properties_map_to_pb(): void
    {
        $pb = pb_address_fields(hs_address_from_props([
            'address' => '1 Market St', 'city' => 'San Francisco',
            'state' => 'California', 'zip' => '94105', 'country' => 'United States',
        ]));
        $this->assertSame('1 Market St', $pb['address1']);
        $this->assertSame('CA', $pb['state']);
        $this->assertSame('94105', $pb['zip']);
        $this->assertSame('US', $pb['country']);
    }

    #[Test]
    public function hubspot_contact_never_clears_address2(): void
    {
        // Contacts don't request address2, so it's absent from props.
        $pb = pb_address_fields(hs_address_from_props([
            'address' => null, 'city' => 'Denver', 'state' => null, 'zip' => null, 'country' => null,
        ]));
        $this->assertArrayNotHasKey('address2', $pb);
        $this->assertSame('', $pb['address1'], 'blank CRM street clears PB street');
    }

    #[Test]
    public function hubspot_record_without_address_sends_nothing(): void
    {
        $this->assertSame([], pb_address_fields(hs_address_from_props([
            'firstname' => 'Ann', 'address' => null, 'city' => null, 'state' => null, 'zip' => null, 'country' => null,
        ])));
    }

    #[Test]
    public function apollo_contact_maps_present_fields_only(): void
    {
        $c = apollo_normalize_contact([
            'id' => 'a1', 'first_name' => 'Ann', 'city' => 'Austin',
            'state' => 'Texas', 'country' => 'United States',
            'present_raw_address' => 'Austin, Texas, United States',
        ]);
        $pb = pb_address_fields($c['address']);
        $this->assertArrayNotHasKey('address1', $pb, 'absent street must not clear PB street');
        $this->assertArrayNotHasKey('zip', $pb);
        $this->assertSame('Austin', $pb['city']);
        $this->assertSame('TX', $pb['state']);
    }

    #[Test]
    public function apollo_street_and_postal_code_map_when_present(): void
    {
        $c = apollo_normalize_contact(['id' => 'a2', 'street_address' => '5 Elm St', 'postal_code' => '73301', 'city' => 'Austin']);
        $pb = pb_address_fields($c['address']);
        $this->assertSame('5 Elm St', $pb['address1']);
        $this->assertSame('73301', $pb['zip']);
    }

    #[Test]
    public function forth_accepts_field_name_variants(): void
    {
        $a = forth_extract_address(['address' => '9 Elm', 'city' => 'Mesa', 'state' => 'AZ', 'zip_code' => '85201']);
        $this->assertSame('9 Elm', $a['address1']);
        $this->assertSame('85201', $a['zip']);

        $b = forth_extract_address(['address1' => '10 Oak', 'postal_code' => '85202']);
        $this->assertSame('10 Oak', $b['address1']);
        $this->assertSame('85202', $b['zip']);
        $this->assertArrayNotHasKey('city', $b);
    }

    #[Test]
    public function forth_nested_or_unknown_shape_sends_nothing(): void
    {
        $a = forth_extract_address(['address' => ['street' => '1 Pine', 'city' => 'Mesa']]);
        $this->assertSame([], pb_address_fields($a));
    }

    #[Test]
    public function forth_numeric_state_is_dropped(): void
    {
        $a = forth_extract_address(['city' => 'Mesa', 'state' => '12']);
        $this->assertArrayNotHasKey('state', $a);
        $this->assertSame('Mesa', $a['city']);
    }

    // ----- Close: lead-address step -----

    private function closeContacts(): array
    {
        return [
            ['close_id' => 'cont_1', 'lead_id' => 'lead_A', 'phone' => '+15550001'],
            ['close_id' => 'cont_2', 'lead_id' => 'lead_A', 'phone' => '+15550002'],
            ['close_id' => 'cont_3', 'lead_id' => 'lead_B', 'phone' => '+15550003'],
            ['close_id' => 'cont_4', 'lead_id' => 'lead_C', 'phone' => ''], // not dialable
        ];
    }

    private function leadResponse(string $city): array
    {
        return [200, ['id' => 'x', 'addresses' => [
            ['address_1' => '', 'city' => '', 'state' => '', 'zipcode' => '', 'country' => ''],
            ['address_1' => '1 Main', 'address_2' => '', 'city' => $city, 'state' => 'CO', 'zipcode' => '80202', 'country' => 'US'],
        ]], ''];
    }

    #[Test]
    public function close_fetches_once_per_dialable_lead_and_fans_out(): void
    {
        $calls = [];
        $fetch = function ($tok, $url, $timeout) use (&$calls) {
            $calls[] = $url;
            $this->assertLessThanOrEqual(5, $timeout);
            return $this->leadResponse(str_contains($url, 'lead_A') ? 'Denver' : 'Boulder');
        };
        $diag = [];
        $out = close_attach_lead_addresses('tok', $this->closeContacts(), $diag, 15.0, $fetch);

        $this->assertCount(2, $calls, 'lead_A once, lead_B once, lead_C skipped (no phone)');
        $this->assertSame('Denver', $out[0]['address']['city']);
        $this->assertSame('Denver', $out[1]['address']['city']);
        $this->assertSame('Boulder', $out[2]['address']['city']);
        $this->assertArrayNotHasKey('address', $out[3]);
        $this->assertSame('1 Main', $out[0]['address']['address1'], 'first NON-EMPTY address wins');
        $this->assertSame(2, $diag['lead_addr_fetch']['with_addr']);
    }

    #[Test]
    public function close_stops_on_rate_limit(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return [429, ['error' => 'rate limited'], '']; };
        $diag = [];
        $out = close_attach_lead_addresses('tok', $this->closeContacts(), $diag, 15.0, $fetch);

        $this->assertSame(1, $calls, 'no further calls after a 429');
        $this->assertSame(429, $diag['lead_addr_fetch']['stopped_on']);
        $this->assertSame(1, $diag['lead_addr_fetch']['skipped']);
        foreach ($out as $c) $this->assertArrayNotHasKey('address', $c);
    }

    #[Test]
    public function close_skips_everything_when_budget_is_spent(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return [200, ['addresses' => []], '']; };
        $diag = [];
        close_attach_lead_addresses('tok', $this->closeContacts(), $diag, 0.5, $fetch);
        $this->assertSame(0, $calls);
        $this->assertSame(2, $diag['lead_addr_fetch']['skipped']);
    }

    #[Test]
    public function close_lead_without_addresses_sends_nothing(): void
    {
        $fetch = fn () => [200, ['id' => 'x', 'addresses' => []], ''];
        $diag = [];
        $out = close_attach_lead_addresses('tok', $this->closeContacts(), $diag, 15.0, $fetch);
        $this->assertArrayNotHasKey('address', $out[0]);
        $this->assertSame([], pb_address_fields($out[0]['address'] ?? null));
    }
}
