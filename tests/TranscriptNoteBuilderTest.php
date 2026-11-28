<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/../server/public/api/crm/close/close_transcript_logger.php';
require_once __DIR__ . '/../server/public/api/crm/forth/forth_transcript_logger.php';

/**
 * Tests for the pure transcript note-builders extracted from the Close/Forth
 * transcript loggers. These hold the real bug surface the adversarial review
 * flagged: HTML escaping, speaker mapping, empty-skip, and — critically — the
 * UTF-8-safe truncation that keeps json_encode() from failing on a long call.
 */
final class TranscriptNoteBuilderTest extends TestCase
{
    // ---- Close (HTML note) --------------------------------------------------

    #[Test]
    public function close_builds_summary_and_transcript_with_speaker_labels(): void
    {
        $html = close_build_transcript_note_html(
            'Call via PhoneBurner: Interested',
            'They want a follow-up.',
            [
                ['speaker' => 'member',  'text' => 'Hi Ada, this is Sam.'],
                ['speaker' => 'contact', 'text' => 'Hi Sam.'],
            ]
        );
        $this->assertIsString($html);
        $this->assertStringContainsString('AI Summary', $html);
        $this->assertStringContainsString('Transcript', $html);
        $this->assertStringContainsString('<strong>Agent:</strong>', $html);
        $this->assertStringContainsString('<strong>Contact:</strong>', $html);
    }

    #[Test]
    public function close_escapes_html_in_transcript_text_no_injection(): void
    {
        $html = close_build_transcript_note_html(
            '',
            '',
            [['speaker' => 'contact', 'text' => '<script>alert(1)</script>']]
        );
        $this->assertIsString($html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function close_returns_null_when_no_summary_and_no_segments(): void
    {
        // Even with an original note, there is nothing new to add.
        $this->assertNull(close_build_transcript_note_html('Call via PhoneBurner: No Answer', '', []));
        $this->assertNull(close_build_transcript_note_html('', '', [['speaker' => 'contact', 'text' => '']]));
    }

    #[Test]
    public function close_truncation_stays_valid_utf8_and_json_encodable(): void
    {
        // A long transcript of 3-byte UTF-8 chars (U+2019) whose byte length far
        // exceeds the 20000 cap — a naive byte substr would split a char and
        // produce invalid UTF-8 that makes json_encode() return false.
        $longText = str_repeat("\u{2019}", 9000); // 27000 bytes
        $html = close_build_transcript_note_html('', '', [['speaker' => 'contact', 'text' => $longText]]);

        $this->assertIsString($html);
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'truncated note must be valid UTF-8');
        $this->assertNotFalse(json_encode(['note_html' => $html]), 'json_encode must not fail on the note');
        $this->assertStringContainsString('transcript truncated', $html);
    }

    // ---- Forth (plain-text note) --------------------------------------------

    #[Test]
    public function forth_builds_plain_text_summary_and_transcript(): void
    {
        $content = forth_build_transcript_note_content(
            'Short recap.',
            [
                ['speaker' => 'member',  'text' => 'Hello.'],
                ['speaker' => 'contact', 'text' => 'Hi there.'],
            ]
        );
        $this->assertIsString($content);
        $this->assertStringContainsString('AI Summary:', $content);
        $this->assertStringContainsString('Transcript:', $content);
        $this->assertStringContainsString('Agent: Hello.', $content);
        $this->assertStringContainsString('Contact: Hi there.', $content);
    }

    #[Test]
    public function forth_returns_null_when_empty(): void
    {
        $this->assertNull(forth_build_transcript_note_content('', []));
    }

    #[Test]
    public function forth_truncation_stays_valid_utf8_and_json_encodable(): void
    {
        $longText = str_repeat("\u{2019}", 9000);
        $content = forth_build_transcript_note_content('', [['speaker' => 'contact', 'text' => $longText]]);

        $this->assertIsString($content);
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'), 'truncated note must be valid UTF-8');
        $this->assertNotFalse(json_encode(['content' => $content]), 'json_encode must not fail on the note');
        $this->assertStringContainsString('transcript truncated', $content);
    }

    #[Test]
    public function forth_update_note_carries_only_the_new_summary(): void
    {
        $segments = [['speaker' => 'member', 'text' => 'Hi there']];
        $note = forth_build_transcript_note_content('Call back Tuesday.', $segments, true);
        $this->assertStringContainsString('AI summary (updated)', $note);
        $this->assertStringContainsString('Call back Tuesday.', $note);
        $this->assertStringNotContainsString('Hi there', $note, 'transcript was already posted with the first note');
        $this->assertNull(forth_build_transcript_note_content('', $segments, true), 'no summary → no update note');
    }


    #[Test]
    public function close_error_detail_keeps_only_validation_messages(): void
    {
        $resp = [
            'errors'       => ['Invalid activity state.'],
            'field-errors' => ['note_html' => 'Not a valid string.'],
            'note_html'    => '<body>secret transcript</body>',
        ];
        $d = close_transcript_error_detail($resp);
        $this->assertSame(['Invalid activity state.'], $d['close_errors']);
        $this->assertSame(['note_html: Not a valid string.'], $d['close_field_errors'], 'a list, so the note-key redactor leaves it readable');
        $this->assertStringNotContainsString('secret transcript', json_encode($d));
        $this->assertSame([], close_transcript_error_detail(null));
    }


    #[Test]
    public function close_note_html_is_well_formed_xhtml(): void
    {
        // Close requires note_html to be a restricted subset of XHTML; a bare
        // <br> made a live dev PUT fail with a note_html field error.
        $segments = [];
        for ($i = 0; $i < 400; $i++) {
            $segments[] = ['speaker' => $i % 2 ? 'contact' : 'member', 'text' => "Line $i: it's <fine> & \"quoted\" … ok"];
        }
        $html = close_build_transcript_note_html("Call via PhoneBurner: Interested", "Summary line 1\nline 2 with Jeff's note", $segments);
        $this->assertStringNotContainsString('<br>', $html);
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($html);
        libxml_use_internal_errors($prev);
        $this->assertNotFalse($doc, 'note_html must parse as XML (long + truncated case included)');
    }

}
