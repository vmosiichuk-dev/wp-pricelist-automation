# Stock Sync — Code Review Notes

This document records major inconsistencies and bad practices identified during automated code review, along with the fixes applied. Refer to this file to avoid reintroducing similar issues in future development.

---

## 1. Dynamic File Inclusion from User Input

**Location:** `admin/views/page-wrapper.php`

**Issue:** `$active_tab` was concatenated directly into a file path for `include`:
```php
$view_file = STOCK_SYNC_PLUGIN_DIR . 'admin/views/tab-' . $active_tab . '.php';
```

**Risk:** Path traversal / local file inclusion if `$_GET['tab']` is manipulated (even after `sanitize_text_field`).

**Fix:** Replaced with a strict allowlist mapped to known tab filenames. Only files from `admin/views/` that are explicitly listed can be included.

**Rule for future:** Never `include` or `require` files built from request data. Always use an allowlist.

---

## 2. HTML Injection (XSS) in JavaScript

**Location:** `assets/js/admin.js` (multiple locations)

**Issue:** Server responses were concatenated into HTML strings and injected via `.html()`:
```javascript
$results.html('<p style="color:red;">Error: ' + response.data + '</p>');
```

**Risk:** If `response.data` contains malicious markup (e.g., `<script>`), it executes in the admin context.

**Fix:** Use jQuery DOM construction (e.g., `$('<p>').css('color', 'red').text(response.data)`) or an `escapeHtml()` helper when building HTML strings.

**Rule for future:** Never pass untrusted data (even from your own AJAX endpoints) into `.html()` via string concatenation. Prefer `.text()`, DOM node creation, or explicit escaping.

---

## 3. HTML Injection (XSS) in Rendered Tables

**Location:** `assets/js/admin.js` — `renderBootstrapTable()` and `showResults()`

**Issue:** Product names, distributor references, and statuses from CSV/server were inserted into HTML via string concatenation without escaping.

**Fix:**
- `renderBootstrapTable()` now builds table rows with jQuery DOM nodes (`$('<tr>')`, `$('<td>').text(...)`).
- `showResults()` uses the existing `escapeHtml()` utility for all dynamic text.

**Rule for future:** Any data originating from external files (XLSX/CSV) or the database must be treated as untrusted when rendered into HTML. Build DOM nodes or escape explicitly.

---

## 4. Missing File Size Validation on Upload

**Location:** `includes/class-admin.php` — `ajax_upload_file()`

**Issue:** No maximum file size was enforced before accepting an uploaded XLSX.

**Fix:** Added an explicit `$max_bytes` check (5 MB) before MIME validation and `move_uploaded_file()`.

**Rule for future:** Always enforce file size limits on uploads to prevent resource exhaustion and denial of service.

---

## 5. Missing Path Traversal Validation on Uploaded Files

**Location:** `includes/class-ajax-handler.php` — `init_sync()` and `bootstrap_analyze()`

**Issue:** The file path from the client was only checked with `file_exists()`. A manipulated path could point outside the intended temp directory.

**Fix:** Added `validate_uploaded_file_path()` which uses `realpath()` + `wp_normalize_path()` to ensure the resolved path is strictly inside `wp_upload_dir()/stock-sync-temp` and has an `.xlsx` extension.

**Rule for future:** Any file path received from the client must be resolved and validated against an allowed directory before use. Never trust client-supplied paths.

---

## 6. Shared Transient Key Across Concurrent Runs

**Location:** `includes/class-ajax-handler.php`

**Issue:** The transient key `'stock_sync_queue_' . $slug` was shared. Two admins (or two browser tabs) syncing the same distributor would overwrite each other's queues.

**Fix:** Generated a unique `$run_id` per init and appended it to the transient key. The JS now passes `run_id` with every batch request so each run reads its own isolated queue.

**Rule for future:** Any server-side state stored in transients/options for a multi-step AJAX flow must be scoped to a unique run/session ID, not just a user or distributor slug.

---

## 7. Empty String Scoring 100% Confidence in Name Matching

**Location:** `includes/class-bootstrap-matcher.php` — `calculate_confidence()`

**Issue:** After normalization, two empty strings would match exactly and return `100`. Also, an empty string as a substring of any string would score `90`.

**Fix:** Added an early return of `0` when either normalized name is empty.

**Rule for future:** Edge cases on empty/null inputs must be handled before scoring/matching logic. Never let empty data produce a high confidence score.

---

## 8. Empty Distributor Slug Produces Generic Meta Key

**Location:** `includes/class-standard-product.php` — `get_meta_key()`

**Issue:** If `distributor_slug` was empty, the method returned `_supplier_ref_`, a generic key that could collide with other data.

**Fix:** Return `null` when the slug is empty. Callers already guard against empty meta keys (`find_by_distributor_ref`).

**Rule for future:** Composite meta keys must always include a validated, non-empty identifier. Fail fast or return null rather than producing a generic key.

---

## 11. Sparse XLSX Rows Break Column Mapping

**Location:** `includes/class-xlsx-parser.php`

**Issue:** The parser incremented `$col_index` for every `<c>` node. In sparse XLSX rows (cells with empty values omitted), the column indices would shift and the `distributor_ref`/`availability` mapping would point to wrong columns.

**Fix:** Derive the column index from the cell's `r` attribute (e.g., "B12") using a new `excel_col_to_index()` helper.

**Rule for future:** When parsing Excel/XML data, never rely on iteration order for column position. Always read the explicit coordinate attributes.

---

## 12. Case-Sensitive Unavailability Matching

**Location:** `includes/distributors/class-distributor-vininova.php`

**Issue:** Polish availability flags like "Brak" or "OS" (uppercase) would not match because the flags array contained lowercase entries and used strict `in_array` without normalization.

**Fix:** Normalize the input with `mb_strtolower(trim($value))` before comparing.

**Rule for future:** Human-entered text from external sources should be normalized (trim + lowercase) before matching against known flag lists. Use `mb_strtolower` for multibyte safety.

---

## 13. Vendor-Specific Copy in Base Distributor Class

**Location:** `includes/distributors/class-distributor.php`

**Issue:** The default `get_unavailable_description()` contained hardcoded "VININOVA" branding and a specific category name. Any new distributor inheriting this would show incorrect text to customers.

**Fix:** Replaced with neutral, generic Polish copy that still references the computed `$brand` but removes vendor names and specific categories.

**Rule for future:** Abstract/base classes must never contain vendor-specific text. Keep default copy neutral and let concrete classes override if needed.

---

## 14. Weak Temp File Cleanup Validation

**Location:** `stock-sync.php` — `stock_sync_cleanup_temp()`

**Issue:** Cleanup checked only `strpos($file_path, 'stock_') !== false`, which could match files outside the intended directory.

**Fix:** Added `realpath()` resolution and prefix checks to ensure the file is strictly inside the plugin's temp directory and its basename starts with `stock_`. Removed the `@` error suppression so failures can be observed.

**Rule for future:** Cleanup/deletion functions must resolve paths and verify the target is inside the intended directory before unlinking. Avoid `@` suppression on filesystem operations.

---

## 15. Silent Overwrite in Distributor Registry

**Location:** `includes/distributors/class-distributor-registry.php`

**Issue:** `register()` silently replaced an existing distributor with the same slug. Bugs from duplicate registrations would go unnoticed.

**Fix:** Throw `InvalidArgumentException` if the slug is already registered.

**Rule for future:** Registry `register()` methods should fail fast on duplicates. Silent overwrites hide configuration errors.

---

## 16. Accessibility — Missing Label on Checkbox

**Location:** `admin/views/tab-bootstrap.php`

**Issue:** The "check all" header checkbox had no accessible name for screen readers.

**Fix:** Added `aria-label="Select all entries"`.

**Rule for future:** Every interactive control (`<input>`, `<button>`, `<select>`) must have an accessible name via `<label>`, `aria-label`, or `aria-labelledby`.

---

## 17. CSS Contrast Failure

**Location:** `assets/css/admin.css`

**Issue:** `.status-suggest` used `#ffb900` (light amber) on a white background, failing WCAG AA contrast for normal text.

**Fix:** Changed to `#856404` (darker amber, already used in `.confidence-medium`) which meets ≥4.5:1.

**Rule for future:** Test all text/background color pairs for WCAG AA contrast. Use tools or design-system tokens instead of ad-hoc hex values.

---

## 18. Misleading Docblock on Row Data

**Location:** `includes/distributors/class-distributor.php`

**Issue:** The `@param` docblock for `$row_data` claimed it was "Zero-indexed" when the parser and all distributors actually use **1-based** column keys.

**Fix:** Updated docblock to "One-indexed row array from parser".

**Rule for future:** Docblocks must accurately describe the data contract. Incorrect documentation leads to off-by-one bugs in custom implementations.

---

## Summary of Principles

1. **Treat all external data as untrusted** — whether from `$_GET`, `$_POST`, uploaded files, XLSX cells, or even your own AJAX responses when rendered back into HTML.
2. **Never build file paths from user input without an allowlist or directory validation.**
3. **Scope server-side transient/state to a unique run ID** in multi-step flows.
4. **Fail fast** on duplicate registrations, empty slugs, and invalid paths.
5. **Normalize before matching** when comparing human-entered or external text.
6. **Keep base classes neutral** — no vendor-specific branding or categories.
7. **Always validate/clamp** numeric values before SQL interpolation.
8. **Ensure accessible names** on all interactive controls and sufficient color contrast.
9. **Keep docblocks accurate** to prevent integration bugs.

---

## New Rule: All UI text must be translatable via WordPress i18n

**Location:** All new PHP view files, PHP classes, and JS strings

**Rule:** Every piece of user-facing text must be wrapped in `__()`, `esc_html__()`, or `esc_attr__()` with the `'stock-sync'` text domain. For JS strings, add them to the `wp_localize_script` array in `class-admin.php` so they are picked up by the translation scanner. Add the Polish translation to `languages/stock-sync-pl_PL.po` immediately. Never hardcode Polish text directly in views without translation functions.

**Fix for this implementation:** All new strings for the publish feature (price column, markup, mode toggle, listed suffix) are wrapped in `__()` and added to `.po` files.
