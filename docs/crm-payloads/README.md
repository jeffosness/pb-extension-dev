# CRM payload examples

One complete, scrubbed example of every CRM API response an integration reads,
so the next feature that needs a field (address, owner, a custom counter) can
look up its exact name and nesting instead of guessing.

**Why this exists:** we used to record only the fields the code needed that
day. When address sync came along (#268), nobody knew Forth's address was a
nested `address` object, and the first implementation guessed top-level keys
that would never have matched.

## Layout

```
docs/crm-payloads/<crm>/<endpoint>.json    e.g. forth/get_contact.json
```

Name the file after the API call (`get_contact`, `search_contacts`,
`list_members`, `lead_addresses`). Capture **every** endpoint the integration
reads, including list and search shapes, which often differ from the
single-record shape (Forth: Search returns `firstname` and a `phone` array;
Get Contact returns `first_name` and `phone_number`).

## How to capture one

1. Get a **full** response, not a trimmed one: a real call against a test
   account, the provider's documented example, or a dev launch with a
   temporary dump.
2. Scrub it (the repo is public):
   `php scripts/scrub-payload.php < raw.json > docs/crm-payloads/<crm>/<endpoint>.json`
   The script keeps every key, the nesting, types and value *formats*
   (`"IL"` → `"XX"`, `"60001"` → `"99999"`, dates → `9999-99-99`) and masks
   all content.
3. **Read the output before committing.** Delete the raw file.
4. Add a line to the table below: where the example came from, when, and any
   value meaning the mask hides (enums, codes).

## Examples on file

| File | Source | Captured | Notes |
|---|---|---|---|
| `forth/get_contact.json` | SetForth Complete API Documentation, Get Contact example (pp. 48-49) | 2026-08-21 (doc export) | `object` is `"contact"`. `address.state` is a 2-letter code. No country key. |
| (field names only) | Live prod `forth_fetch_contacts.fields` log | 2026-11-25 | Confirms the documented shape, including nested `address.{address1,address2,address3,city,state,zip}`. Live records ALSO carry fields the docs example lacks: `tp_id`, `secondary_assignments.{companies,roles,teams}`, `is_test_contact`, `dba`, `ein`, `has_valid_ssn`, `stageID`, `stage_label`, `status_label`, `status_timestamp`, `linked_coapp`, `budget_fields`, `del_source`, and per-org `customs[<label>]` rows. **There is no call-attempt field**, and the API has no per-contact call list (only `POST /calls`, `GET /calls/{id}`, `GET /calls/disposition`). |
