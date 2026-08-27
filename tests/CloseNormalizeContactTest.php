<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

// close_helpers.php is all function definitions (no top-level execution), so
// requiring it here just makes close_normalize_contact() available. It's a
// pure function — no I/O, no config — which is exactly why it's worth locking:
// the Close dial-session timeout fix depends on it producing the SAME shape
// whether the contact came from the per-ID fetch or the lead-resolution list
// response. A regression here silently drops phones/emails from live sessions.
require_once __DIR__ . '/../server/public/api/crm/close/close_helpers.php';

final class CloseNormalizeContactTest extends TestCase
{
    #[Test]
    public function full_contact_maps_all_fields(): void
    {
        $c = close_normalize_contact([
            'id'      => 'cont_ABC123',
            'lead_id' => 'lead_XYZ789',
            'name'    => 'Wendy Appelbaum',
            'phones'  => [
                ['phone' => '+15551110000', 'type' => 'office'],
                ['phone' => '+15552220000', 'type' => 'mobile'],
                ['phone' => '+15553330000', 'type' => 'home'],
            ],
            'emails'  => [
                ['email' => 'wendy@example.com'],
                ['email' => 'w.appelbaum@example.com'],
            ],
        ]);

        $this->assertSame('cont_ABC123', $c['close_id']);
        $this->assertSame('lead_XYZ789', $c['lead_id']);
        $this->assertSame('Wendy', $c['first_name']);
        $this->assertSame('Appelbaum', $c['last_name']);
        // Primary phone = first in the array; the rest become additional_phone.
        $this->assertSame('+15552220000', $c['additional_phones'][0]['number']);
        $this->assertSame('+15551110000', $c['phone']);
        // Primary email = first non-empty in the array.
        $this->assertSame('wendy@example.com', $c['email']);
        $this->assertCount(2, $c['additional_phones']);
    }

    #[Test]
    public function phone_type_mapping_and_labels(): void
    {
        $c = close_normalize_contact([
            'id'     => 'cont_1',
            'name'   => 'Type Tester',
            'phones' => [
                ['phone' => '+1000', 'type' => 'office'], // primary — type not recorded
                ['phone' => '+1001', 'type' => 'mobile'], // -> 3 / Mobile
                ['phone' => '+1002', 'type' => 'home'],   // -> 1 / Home
                ['phone' => '+1003', 'type' => 'office'], // -> 2 / Office (default Work)
                ['phone' => '+1004', 'type' => ''],       // -> 2 / Phone
            ],
        ]);

        $add = $c['additional_phones'];
        $this->assertSame('3', $add[0]['phone_type']);
        $this->assertSame('Mobile', $add[0]['phone_label']);
        $this->assertSame('1', $add[1]['phone_type']);
        $this->assertSame('Home', $add[1]['phone_label']);
        $this->assertSame('2', $add[2]['phone_type']);
        $this->assertSame('Office', $add[2]['phone_label']);
        $this->assertSame('2', $add[3]['phone_type']);
        $this->assertSame('Phone', $add[3]['phone_label']);
    }

    #[Test]
    public function single_word_name_leaves_last_empty(): void
    {
        $c = close_normalize_contact(['id' => 'cont_1', 'name' => 'Cher']);
        $this->assertSame('Cher', $c['first_name']);
        $this->assertSame('', $c['last_name']);
    }

    #[Test]
    public function multi_word_last_name_kept_intact(): void
    {
        // preg_split with limit 2: everything after the first space is "last".
        $c = close_normalize_contact(['id' => 'cont_1', 'name' => 'Mary Jane Watson']);
        $this->assertSame('Mary', $c['first_name']);
        $this->assertSame('Jane Watson', $c['last_name']);
    }

    #[Test]
    public function empty_phone_entries_are_skipped(): void
    {
        $c = close_normalize_contact([
            'id'     => 'cont_1',
            'name'   => 'No Number',
            'phones' => [
                ['phone' => '', 'type' => 'mobile'],
                ['phone' => '   ', 'type' => 'home'],
                ['phone' => '+15550001111', 'type' => 'office'],
            ],
        ]);
        // The two blank entries are skipped; the real number becomes primary.
        $this->assertSame('+15550001111', $c['phone']);
        $this->assertCount(0, $c['additional_phones']);
    }

    #[Test]
    public function missing_fields_yield_safe_empty_defaults(): void
    {
        // A contact object with no name/phones/emails must not fatal and must
        // return empty strings (the caller skips phone-less contacts later).
        $c = close_normalize_contact(['id' => 'cont_1']);
        $this->assertSame('cont_1', $c['close_id']);
        $this->assertSame('', $c['lead_id']);
        $this->assertSame('', $c['first_name']);
        $this->assertSame('', $c['last_name']);
        $this->assertSame('', $c['phone']);
        $this->assertSame('', $c['email']);
        $this->assertSame([], $c['additional_phones']);
    }

    #[Test]
    public function first_non_empty_email_wins(): void
    {
        $c = close_normalize_contact([
            'id'     => 'cont_1',
            'name'   => 'Email Order',
            'emails' => [
                ['email' => ''],
                ['email' => 'second@example.com'],
                ['email' => 'third@example.com'],
            ],
        ]);
        $this->assertSame('second@example.com', $c['email']);
    }
}
