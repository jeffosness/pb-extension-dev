<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the pure logic behind HubSpot owner-id enrichment (see
 * hs_helpers.php owner-id section + memory/reference_hubspot_owner_lookup.md).
 *
 * The two functions covered here are extracted so the message-parsing +
 * owner-matching logic can be unit-tested without hitting HubSpot's API:
 *
 *   hs_parse_introspect_response($decoded) — parses /oauth/v1/access-tokens response
 *   hs_find_owner_id_in_page($page, $userId) — matches userId in one Owners API page
 *
 * The HTTP-fetching wrappers (hs_introspect_access_token, hs_lookup_owner_id)
 * are exercised end-to-end via manual dev testing.
 */
final class HsOwnerLookupTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../server/public/api/crm/hubspot/hs_helpers.php';
    }

    // ------------------------------------------------------------------------
    // hs_parse_introspect_response
    // ------------------------------------------------------------------------

    #[Test]
    public function introspect_valid_shape_returns_triple(): void
    {
        // Real response shape from HubSpot as observed on Jeff's demo account
        // 2026-08-03 (memory/reference_hubspot_owner_lookup.md).
        $result = hs_parse_introspect_response([
            'user_id' => 45186743,
            'hub_id'  => 21697445,
            'user'    => 'saleshubspotdemo@phoneburner.biz',
            'app_id'  => 41691265,
        ]);
        $this->assertNotNull($result);
        $this->assertSame(45186743, $result['user_id']);
        $this->assertSame(21697445, $result['hub_id']);
        $this->assertSame('saleshubspotdemo@phoneburner.biz', $result['email']);
    }

    #[Test]
    public function introspect_returns_null_on_non_array(): void
    {
        $this->assertNull(hs_parse_introspect_response(null));
        $this->assertNull(hs_parse_introspect_response('unexpected string'));
        $this->assertNull(hs_parse_introspect_response(42));
    }

    #[Test]
    public function introspect_returns_null_on_missing_user_id(): void
    {
        $this->assertNull(hs_parse_introspect_response([
            'hub_id' => 21697445,
            'user'   => 'a@b.com',
        ]));
    }

    #[Test]
    public function introspect_returns_null_on_missing_hub_id(): void
    {
        $this->assertNull(hs_parse_introspect_response([
            'user_id' => 45186743,
            'user'    => 'a@b.com',
        ]));
    }

    #[Test]
    public function introspect_returns_null_on_zero_ids(): void
    {
        // Empirical: HubSpot returns all-nulls for expired/revoked tokens
        // (verified against 3 stale tokens in Jeff's demo portal 2026-08-03).
        // Guard against those being treated as valid.
        $this->assertNull(hs_parse_introspect_response([
            'user_id' => null,
            'hub_id'  => null,
            'user'    => null,
        ]));
    }

    #[Test]
    public function introspect_defaults_email_to_empty_string(): void
    {
        $result = hs_parse_introspect_response([
            'user_id' => 45186743,
            'hub_id'  => 21697445,
            // no 'user' key
        ]);
        $this->assertNotNull($result);
        $this->assertSame('', $result['email']);
    }

    // ------------------------------------------------------------------------
    // hs_find_owner_id_in_page
    // ------------------------------------------------------------------------

    #[Test]
    public function find_owner_returns_matching_id(): void
    {
        // The empirical Owners API response from Jeff's demo account.
        $page = [
            [
                'id'     => '24523741',
                'userId' => 24523741,
                'email'  => 'daniellaloggia@properexpression.com',
            ],
            [
                'id'     => '184855386',
                'userId' => 45186743,
                'email'  => 'saleshubspotdemo@phoneburner.biz',
            ],
        ];
        $this->assertSame('184855386', hs_find_owner_id_in_page($page, 45186743));
    }

    #[Test]
    public function find_owner_returns_null_when_userid_not_present(): void
    {
        $page = [
            ['id' => '24523741', 'userId' => 24523741, 'email' => 'x@y.com'],
        ];
        $this->assertNull(hs_find_owner_id_in_page($page, 99999999));
    }

    #[Test]
    public function find_owner_returns_null_on_empty_page(): void
    {
        $this->assertNull(hs_find_owner_id_in_page([], 45186743));
    }

    #[Test]
    public function find_owner_skips_owners_missing_userid(): void
    {
        // Defensive: HubSpot occasionally returns owner records with no userId
        // (e.g. deactivated users, integration users). Skip them without erroring.
        $page = [
            ['id' => '999', 'email' => 'no-userid@example.com'],
            ['id' => '184855386', 'userId' => 45186743, 'email' => 'ok@example.com'],
        ];
        $this->assertSame('184855386', hs_find_owner_id_in_page($page, 45186743));
    }

    #[Test]
    public function find_owner_returns_null_when_id_field_missing(): void
    {
        // Guard against a match where the id field is empty/absent — better
        // to return null than an empty string that gets stamped as owner_id.
        $page = [
            ['userId' => 45186743, 'email' => 'no-id@example.com'],
        ];
        $this->assertNull(hs_find_owner_id_in_page($page, 45186743));
    }

    #[Test]
    public function find_owner_coerces_string_userid_to_int(): void
    {
        // HubSpot sometimes returns userId as an int and sometimes as a
        // stringified int depending on version — guard the compare.
        $page = [
            ['id' => '184855386', 'userId' => '45186743', 'email' => 'x@y.com'],
        ];
        $this->assertSame('184855386', hs_find_owner_id_in_page($page, 45186743));
    }

    #[Test]
    public function find_owner_skips_malformed_entries(): void
    {
        // Defensive: array with non-array entries (unlikely but not impossible
        // if HubSpot's shape drifts). Skip cleanly.
        $page = [
            'not-an-array',
            null,
            42,
            ['id' => '184855386', 'userId' => 45186743, 'email' => 'x@y.com'],
        ];
        $this->assertSame('184855386', hs_find_owner_id_in_page($page, 45186743));
    }
}
