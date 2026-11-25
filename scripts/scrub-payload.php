<?php
// scripts/scrub-payload.php — make a real CRM API response safe to commit.
//
// The repo is PUBLIC, so raw payloads (names, phones, addresses, SSNs) can't
// be committed. This keeps everything we actually need from an example —
// every key, the nesting, value TYPES and value FORMATS — and replaces the
// content:
//   letters → x/X (case kept), digits → 9, other characters kept
//   "Chicago" → "Xxxxxxx", "IL" → "XX", "60001" → "99999",
//   "2022-01-08 12:24:18" → "9999-99-99 99:99:99", 534512161 → 999999999
//   true/false/null and "" are kept as-is (blank vs null matters).
// Lists keep their first 2 items. Strings over 60 chars are cut to 60 + "…".
// Custom-field LABELS are values too, so they get masked as well.
//
// Usage:
//   php scripts/scrub-payload.php < raw.json > docs/crm-payloads/<crm>/<endpoint>.json
//
// Then READ the output before committing it.

$raw = stream_get_contents(STDIN);
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "scrub-payload: input is not a JSON object/array\n");
    exit(1);
}

function scrub_value($v) {
    if (is_array($v)) {
        if (array_is_list($v)) {
            return array_map('scrub_value', array_slice($v, 0, 2));
        }
        $out = [];
        foreach ($v as $k => $x) $out[$k] = scrub_value($x);
        return $out;
    }
    if (is_int($v)) return (int)str_repeat('9', strlen((string)abs($v)));
    if (is_float($v)) return (float)preg_replace('/\d/', '9', (string)$v);
    if (!is_string($v) || $v === '') return $v;
    $s = mb_substr($v, 0, 60);
    $s = preg_replace(['/\p{Lu}/u', '/\p{Ll}/u', '/\p{Nd}/u'], ['X', 'x', '9'], $s);
    return mb_strlen($v) > 60 ? $s . '…' : $s;
}

echo json_encode(scrub_value($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
