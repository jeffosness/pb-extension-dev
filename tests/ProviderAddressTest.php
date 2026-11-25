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
    public function apollo_never_sends_street_or_zip(): void
    {
        // Apollo contacts have no street/zip field (API docs); never touch PB's.
        $c = apollo_normalize_contact(['id' => 'a2', 'city' => 'Austin', 'state' => null, 'country' => 'United States']);
        $this->assertSame(['city' => 'Austin', 'state' => '', 'country' => 'US'], pb_address_fields($c['address']));
    }

    #[Test]
    public function forth_documented_nested_address_maps_to_pb(): void
    {
        // Shape from Forth's Get Contact example (SetForth API docs, pp. 48-49).
        $a = forth_extract_address(['first_name' => 'John', 'address' => [
            'address1' => '200 Test St.', 'address2' => 'Apt. 4008', 'address3' => '',
            'city' => 'Chicago', 'state' => 'IL', 'zip' => '60001',
        ]]);
        $this->assertSame([
            'address1' => '200 Test St.', 'address2' => 'Apt. 4008',
            'city' => 'Chicago', 'state' => 'IL', 'zip' => '60001',
        ], pb_address_fields($a));
    }

    #[Test]
    public function forth_without_address_object_sends_nothing(): void
    {
        $this->assertSame([], pb_address_fields(forth_extract_address(['city' => 'Mesa', 'zip' => '85201'])));
        $this->assertSame([], pb_address_fields(forth_extract_address(['address' => '9 Elm'])));
    }

    #[Test]
    public function forth_numeric_state_is_dropped(): void
    {
        $a = forth_extract_address(['address' => ['city' => 'Mesa', 'state' => '12']]);
        $this->assertArrayNotHasKey('state', $a);
        $this->assertSame('Mesa', $a['city']);
    }

    #[Test]
    public function every_l3_launch_path_merges_address_and_logs_it(): void
    {
        // Regression guard: a refactor that drops the merge line from one
        // launch file would otherwise stay green (only addr_sent=0 would show).
        $files = glob(__DIR__ . '/../server/public/api/crm/*/pb_dialsession_*.php');
        $this->assertCount(7, $files, 'new launch path? add the address merge + addr_sent to it');
        foreach ($files as $f) {
            $src = file_get_contents($f);
            $this->assertStringContainsString("\$pbContact += pb_address_fields(\$c['address'] ?? null);", $src, basename(dirname($f)) . '/' . basename($f));
            $this->assertStringContainsString("'addr_sent'", $src, basename(dirname($f)) . '/' . basename($f));
        }
    }

    #[Test]
    public function hubspot_contacts_do_not_request_address2(): void
    {
        $src = file_get_contents(__DIR__ . '/../server/public/api/crm/hubspot/hs_helpers.php');
        $this->assertStringContainsString(
            '$baseProps = [\'firstname\', \'lastname\', \'email\', \'address\', \'city\', \'state\', \'zip\', \'country\'];',
            $src,
            'HubSpot contacts have no standard address2; requesting it would let blanks clear PB address2'
        );
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
