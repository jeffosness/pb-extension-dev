<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the Forth call-attempt sort inputs:
 *
 *   forth_extract_call_attempts($c)  — contact → ['field', 'value']
 *   forth_contact_field_names($c)    — contact → field NAMES for the diag log
 *
 * The real field name isn't in Forth's public docs, so these pin the
 * tolerance rules (and, just as important, what must NOT match).
 */
final class ForthCallAttemptsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../server/public/api/crm/forth/forth_helpers.php';
    }

    #[Test]
    public function reads_top_level_snake_case(): void
    {
        $this->assertSame(
            ['field' => 'call_attempts', 'value' => 3],
            forth_extract_call_attempts(['first_name' => 'A', 'call_attempts' => '3'])
        );
    }

    #[Test]
    public function reads_camel_case_and_dial_spelling(): void
    {
        $this->assertSame(2, forth_extract_call_attempts(['callAttempts' => 2])['value']);
        $this->assertSame(4, forth_extract_call_attempts(['dial_attempts' => 4])['value']);
        $this->assertSame(1, forth_extract_call_attempts(['attempts' => 1])['value']);
    }

    #[Test]
    public function empty_value_on_present_field_means_never_called(): void
    {
        $this->assertSame(0, forth_extract_call_attempts(['call_attempts' => ''])['value']);
        $this->assertSame(0, forth_extract_call_attempts(['call_attempts' => null])['value']);
    }

    #[Test]
    public function reads_one_level_nested_object(): void
    {
        $this->assertSame(
            ['field' => 'stats.call_attempts', 'value' => 5],
            forth_extract_call_attempts(['stats' => ['call_attempts' => 5]])
        );
    }

    #[Test]
    public function reads_custom_field_rows(): void
    {
        $c = ['custom_fields' => [
            ['name' => 'Favorite Color', 'value' => 'blue'],
            ['name' => 'Call Attempts', 'value' => '7'],
        ]];
        $this->assertSame(
            ['field' => 'custom_fields[Call Attempts]', 'value' => 7],
            forth_extract_call_attempts($c)
        );
    }

    #[Test]
    public function ignores_non_call_attempt_counters(): void
    {
        // Debt-settlement CRM: payment/NSF attempt counters must never be read
        // as call attempts.
        $c = ['payment_attempts' => 2, 'nsf_attempts' => 1, 'draft_attempt_count' => 9];
        $this->assertSame(['field' => null, 'value' => null], forth_extract_call_attempts($c));
    }

    #[Test]
    public function ignores_non_numeric_value(): void
    {
        $this->assertNull(forth_extract_call_attempts(['call_attempts' => 'n/a'])['value']);
    }

    #[Test]
    public function missing_field_returns_nulls(): void
    {
        $this->assertSame(
            ['field' => null, 'value' => null],
            forth_extract_call_attempts(['first_name' => 'A', 'phone_number' => '5551234567'])
        );
    }

    #[Test]
    public function field_names_never_include_values(): void
    {
        $c = [
            'first_name'    => 'Alice',
            'email'         => 'alice@example.com',
            'stats'         => ['call_attempts' => 3],
            'custom_fields' => [['name' => 'Call Attempts', 'value' => '3']],
            'co_applicants' => [['name' => 'Bob Smith', 'phone' => '5550001111']],
        ];
        $names = forth_contact_field_names($c);
        $this->assertContains('first_name', $names);
        $this->assertContains('stats.call_attempts', $names);
        $this->assertContains('custom_fields[Call Attempts]', $names);
        $flat = implode(' ', $names);
        $this->assertStringNotContainsString('Alice', $flat);
        $this->assertStringNotContainsString('alice@example.com', $flat);
        // A list of people (no `value` key) must not leak a person's name.
        $this->assertStringNotContainsString('Bob Smith', $flat);
    }
}
