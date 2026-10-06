<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the pure logic behind the click-to-call name lookup that
 * softphone.php uses to send first_name/last_name on pb-softphone:dial.
 *
 *   hs_ctc_name_object($crmName)          — which HubSpot object/props to fetch
 *   hs_ctc_name_from_props($crmName, $p)  — HubSpot props → PB first/last
 *   forth_extract_contact_name($c)        — Forth contact → [first, last]
 *   forth_try_mint_access_token(...)      — fail-open on missing creds (no network)
 *
 * The HTTP-fetching wrappers (hs_ctc_lookup_name, forth_ctc_lookup_name) are
 * exercised end-to-end via manual dev testing.
 */
final class CtcNameLookupTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../server/public/api/crm/hubspot/hs_helpers.php';
        require_once __DIR__ . '/../server/public/api/crm/forth/forth_helpers.php';
    }

    // ------------------------------------------------------------------------
    // hs_ctc_name_object
    // ------------------------------------------------------------------------

    #[Test]
    public function hs_object_for_contacts_and_companies(): void
    {
        $this->assertSame(['contacts', 'firstname,lastname'], hs_ctc_name_object('hubspot'));
        $this->assertSame(['companies', 'name'], hs_ctc_name_object('hubspotcompany'));
    }

    #[Test]
    public function hs_object_null_for_deals_and_unknown(): void
    {
        $this->assertNull(hs_ctc_name_object('hubspotdeal'));
        $this->assertNull(hs_ctc_name_object('forth'));
        $this->assertNull(hs_ctc_name_object(''));
    }

    // ------------------------------------------------------------------------
    // hs_ctc_name_from_props
    // ------------------------------------------------------------------------

    #[Test]
    public function hs_contact_props_map_to_first_last(): void
    {
        $this->assertSame(
            ['first_name' => 'Rachel', 'last_name' => 'Sample'],
            hs_ctc_name_from_props('hubspot', ['firstname' => ' Rachel ', 'lastname' => 'Sample'])
        );
    }

    #[Test]
    public function hs_contact_missing_or_null_props_are_empty_strings(): void
    {
        // HubSpot returns null for unset properties.
        $this->assertSame(
            ['first_name' => '', 'last_name' => ''],
            hs_ctc_name_from_props('hubspot', ['firstname' => null])
        );
    }

    #[Test]
    public function hs_company_name_goes_in_first_name(): void
    {
        // Mirrors pb_dialsession_selection.php company normalization.
        $this->assertSame(
            ['first_name' => 'Acme Corp', 'last_name' => ''],
            hs_ctc_name_from_props('hubspotcompany', ['name' => 'Acme Corp'])
        );
    }

    #[Test]
    public function hs_unnamed_object_type_returns_null(): void
    {
        $this->assertNull(hs_ctc_name_from_props('hubspotdeal', ['dealname' => 'Big Deal']));
    }

    // ------------------------------------------------------------------------
    // forth_extract_contact_name
    // ------------------------------------------------------------------------

    #[Test]
    public function forth_snake_case_fields(): void
    {
        $this->assertSame(['Jane', 'Doe'], forth_extract_contact_name(['first_name' => 'Jane', 'last_name' => 'Doe']));
    }

    #[Test]
    public function forth_compact_fields(): void
    {
        $this->assertSame(['Jane', 'Doe'], forth_extract_contact_name(['firstname' => 'Jane', 'lastname' => 'Doe']));
    }

    #[Test]
    public function forth_fullname_fallback_splits_on_first_space(): void
    {
        $this->assertSame(['Mary', 'Ann Smith'], forth_extract_contact_name(['fullname' => 'Mary  Ann Smith']));
    }

    #[Test]
    public function forth_no_name_fields_returns_empty(): void
    {
        $this->assertSame(['', ''], forth_extract_contact_name(['cell_phone' => '5551234567']));
    }

    // ------------------------------------------------------------------------
    // forth_try_mint_access_token — fail-open (no api_error / exit)
    // ------------------------------------------------------------------------

    #[Test]
    public function forth_try_mint_returns_null_on_missing_credentials(): void
    {
        $reason = null;
        $this->assertNull(forth_try_mint_access_token('test-client', [], $reason));
        $this->assertSame('missing_credentials', $reason);
    }
}
