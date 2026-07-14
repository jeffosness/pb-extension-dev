# TESTING.md — Automated tests, running & writing

**Purpose:** how to run the test suite, what it covers, and how to add new tests.

**Invariant lives in CLAUDE.md.** If you touched `utils.php` or a function listed in [SHARED_CODE.md](SHARED_CODE.md), you must run tests locally before pushing AND the CI check must be green. That rule is a contributor invariant kept in CLAUDE.md; this doc has the operational details.

---

## Running tests locally

On a fresh clone:

```bash
composer install    # installs PHPUnit (~30 seconds one-time)
composer test       # runs the whole suite (~1 second)
```

You should see:

```
OK (20 tests, 33 assertions)
```

On Windows, 2 POSIX permission tests skip:

```
OK, but some tests were skipped!
Tests: 20, Assertions: 33, Skipped: 2.
```

Both are fine — the POSIX checks run on Linux CI where prod parity matters.

## What's covered today

See `tests/` for the source. Coverage is intentionally focused on the highest-blast-radius, security-critical functions in `utils.php`:

| Function | Tests | Why it's covered |
|---|---|---|
| `safe_file_path()` | 7 | Path-traversal defense — attacker input goes through this |
| `atomic_write_json()` | 6 (2 POSIX-only) | 0600 file / 0700 dir permission guarantees, atomicity, no tmp_* leftovers |
| `temp_code_store()` + `temp_code_retrieve_and_delete()` | 7 | Single-use semantics, TTL expiration, sanitization |

## When you must add/run tests

- **Any edit to `server/public/utils.php`** — run the whole suite. If your change adds a new function, add tests for it in the same PR.
- **Any edit to a function listed in [SHARED_CODE.md](SHARED_CODE.md)** — treat the same way. High blast radius = tests required.
- **New shared function that other code will depend on** — add tests in the same PR, don't defer.
- **PR affects testable behavior in general** — declare it in the PR body's `## Test Impact` section. CI (`.github/workflows/test-impact-check.yml`) blocks PRs that touched `utils.php`, `bootstrap.php`, or `tests/**` without declaring intent.

## CI enforcement

Two workflows keep this honest:

- **`.github/workflows/tests.yml`** — runs `composer test` on every PR and every push to `main`. Red suite = merge blocked.
- **`.github/workflows/test-impact-check.yml`** — checks that PRs touching security-critical or test-adjacent files declare a `## Test Impact` in the PR body. Modeled on the existing `kb-impact-check.yml` and `security-impact-check.yml`.

## Adding a new test

The template is `tests/SafeFilePathTest.php`. It shows the shape:

- Class extends `PHPUnit\Framework\TestCase`
- Use PHP 8 attribute style: `#[Test]` above each test method (not the older `@test` docblock)
- `setUp()` for per-test isolation (temp dirs from `sys_get_temp_dir()`)
- `tearDown()` for cleanup (matters — tests share the same process)
- One assertion per behavior. Descriptive test method names — they show up in failure output.

Once your test file exists in `tests/`, PHPUnit picks it up automatically. Run `composer test` to verify.

## Follow-up test targets

These aren't covered yet but should be, in priority order. Each has a specific reason it wasn't included in the initial batch — noted here so the "why deferred" doesn't get lost.

1. **`redact_pii_recursive()`** (severity: high — PII leakage). Lives in `api/core/bootstrap.php` rather than `utils.php`. `bootstrap.php` sends CORS headers and assumes an HTTP request context, so it can't be blindly `require_once`'d from a test bootstrap. Right approach is probably to extract the function to `utils.php` (or a new `redact.php` includable from both bootstrap and tests), then test it in isolation.

2. **`rate_limit_or_fail()`** (severity: medium — abuse protection). Filesystem + time-dependent. Needs a cache-dir override (env var or config injection) so tests don't collide with each other or with production paths.

3. **`get_client_id_or_fail()`** (severity: medium — input validation). Calls `api_error()` on failure, which lives in `bootstrap.php` and calls `exit`. Testable via `expectException` after wrapping the exit path, or via extracting the validation logic separately.

4. **Provider `*_helpers.php` functions** (severity: low, but useful). Each L3 provider has API interaction code. Not currently tested; would benefit from mocking the underlying HTTP calls.

If you're touching one of these and it becomes practical to test as part of your change, take the win.

## Design notes

- **We do NOT test `api/core/bootstrap.php` itself.** It sends CORS headers, sets timezone, and generally assumes an HTTP context — safer left as-is until we have a reason to isolate individual functions from it.
- **`tests/bootstrap.php` seeds `config.php` from `config.sample.php`** if the real one is absent (fresh clones, CI). This is intentional — the sample values are safe for testing and don't need to be secrets.
- **PHP version pinned to 8.2 in CI.** Local can be higher (I develop on 8.5) but CI catches syntax that's newer than production.
- **No coverage floor.** We're not enforcing a coverage percentage — that produces noisy PRs that chase percentages instead of writing valuable tests. Level up to a coverage floor only if the "Test Impact" declaration turns out to be too easy to skip.
