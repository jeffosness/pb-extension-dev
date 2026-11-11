<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/../server/public/api/crm/hubspot/hs_helpers.php';
require_once __DIR__ . '/../server/public/api/crm/apollo/apollo_helpers.php';
require_once __DIR__ . '/../server/public/api/crm/forth/forth_helpers.php';

/**
 * Each L3 provider maps its own address fields into the normalized shape
 * pb_address_fields() consumes. These lock in the per-provider field names
 * and the end-to-end result PB receives.
 */
final class ProviderAddressTest extends TestCase
{
    #[Test]
    public function hubspot_standard_properties_map_to_pb(): void
    {
        $addr = hs_address_from_props([
            'address' => '1 Market St', 'city' => 'San Francisco',
            'state' => 'California', 'zip' => '94105', 'country' => 'United States',
        ]);
        $pb = pb_address_fields($addr);
        $this->assertSame('1 Market St', $pb['address1']);
        $this->assertSame('CA', $pb['state']);
        $this->assertSame('94105', $pb['zip']);
        $this->assertSame('US', $pb['country']);
    }

    #[Test]
    public function hubspot_record_without_address_sends_nothing(): void
    {
        $this->assertSame([], pb_address_fields(hs_address_from_props(['firstname' => 'Ann'])));
    }

    #[Test]
    public function apollo_contact_maps_city_state_country(): void
    {
        $c = apollo_normalize_contact([
            'id' => 'a1', 'first_name' => 'Ann', 'city' => 'Austin',
            'state' => 'Texas', 'country' => 'United States',
            'present_raw_address' => 'Austin, Texas, United States',
        ]);
        $pb = pb_address_fields($c['address']);
        $this->assertSame('', $pb['address1'], 'present_raw_address must not become address1');
        $this->assertSame('Austin', $pb['city']);
        $this->assertSame('TX', $pb['state']);
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
    }

    #[Test]
    public function forth_ignores_non_scalar_address_values(): void
    {
        $a = forth_extract_address(['address' => ['line' => 'x'], 'address1' => '11 Pine']);
        $this->assertSame('11 Pine', $a['address1']);
    }
}
