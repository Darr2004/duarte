# QR Code Asset Tracking — fixes (this round)

Four changes, in priority order. See the code comments at each spot for
the full "why," this is just a map of where to look.

## 1. Server-side scan verification (closes a real bypass)

**Problem:** "must scan, can't pick by hand" on the release screen was
enforced only in the browser. The `<select>` was reset on a manual
`change` event, but the release handler (`inventory/verify.php`)
trusted whatever `asset_choice[]` value showed up in the POST — so
anyone posting the release form directly (devtools, curl) could claim
any available asset without ever scanning it.

**Fix:**
- New table `asset_scan_verifications` (`database/schema.sql`,
  `database/migration_add_asset_scan_verifications.php`).
- New helpers `create_asset_scan_verification()` /
  `consume_asset_scan_verification()` in `includes/assets.php` — mint a
  short-lived (10 min), single-use token when a scan genuinely
  decodes a matching tag; require + consume it at release.
- New endpoint `inventory/asset_scan_verify.php` — the JS calls this
  the moment a scan decodes, gets back a token, and only THEN marks
  the row verified.
- `inventory/verify.php` — release handler now requires
  `asset_verify_token[line_id]` to match the chosen asset id for that
  exact line, or it throws the same "scan again" error as before.

**Run this before deploying:**
```
php database/migration_add_asset_scan_verifications.php
```
(or re-import `schema.sql` on a fresh install — it's already in there.)

## 2. Variant items can now generate QR tags at creation time

**Problem:** `inventory/item_add.php` disabled the "Generate QR"
checkbox for items with variants/options, with a comment claiming QR
tags can't point at one variant's stock — that was true before
`assets.item_variant_id` existed, but the column has been there since
the assets module shipped. `inventory/stock_in.php` already supported
variant-scoped QR generation; `item_add.php` never got the matching
update.

**Fix:** `item_add.php` now generates one tag/lot per option that was
given starting stock, scoped to that option's `item_variant_id` — same
behavior `stock_in.php` already had. No schema change needed.

## 3. De-duplicated scanner JS and the webcam-focus hint

**Problem:** the camera-fallback chain (facingMode → getCameras() →
back-camera → first-camera) and the Tagalog "ilayo ang QR ~20–30cm"
hint were each copy-pasted across three places: `verify.php`'s main
scanner, `verify.php`'s per-item scanner, and `asset_audit.php`.

**Fix:** new `assets/js/qr-scan-shared.js`, loaded by all three pages.
Holds `extractParam()`, `checkPrereqs()`, `buildScanConfig()`,
`startWithFallback()`, and `injectFocusHints()` (fills any
`.qr-focus-hint-long` / `.qr-focus-hint-short` element with the shared
copy). The three pages now call in instead of each carrying their own
copy — one place to fix if scanner behavior ever needs to change.

## 4. Clearer distinction between the two "QR" concepts, in the UI copy

**Problem:** `requisitions.qr_token` (one-time, per-transaction) and
`assets.asset_tag` (permanent, per-unit) were both just called "QR
code" on the release screen, with nothing telling staff apart which is
which unless they read the source comments.

**Fix:** `verify.php`'s main scanner card now reads "Scan the
request's QR code" with a one-line note that it's different from the
permanent tag on each item, scanned separately on the right.

## 5. Direct/manual asset checkout now moves the same stock number a requisition release does

**Problem:** `inventory/asset_view.php`'s "Check out to" action (a
manual handout of a tagged asset, outside the requisition flow
entirely) never touched `items.quantity_on_hand` — only a
requisition's QR release did, via `record_stock_movement()`. So a
directly-checked-out tool still counted as "on hand" in the catalog.
That let a requisition for the same item pass its stock check and get
approved against a unit that was already out, with the mismatch
surfacing only at release time (or never, if the item wasn't fully
QR-tagged). The return side had the same gap in reverse:
`return_tool_loan()` only restored stock for a loan that came from a
requisition (`requisition_id` set), so a direct checkout's return was
correctly *not* restoring stock — consistent with its checkout never
having deducted it, but consistent with the wrong baseline.

**Fix:**
- `inventory/asset_view.php`'s `checkout` action now calls
  `record_stock_movement()` (type `release`, reference
  `direct_checkout`) right alongside `checkout_asset()` and
  `record_direct_checkout_loan()`, decrementing `quantity_on_hand`
  (and the specific variant's count, via the asset's own
  `item_variant_id`, if the item has variants) the same way a
  QR-released line item already does. Alerts fire only after commit,
  matching every other stock-changing action in the app.
- `includes/loans.php`'s `return_tool_loan()` now restores stock for a
  direct-checkout loan too (resolved from `assets.item_variant_id`
  instead of a `requisition_items` row, since there isn't one), not
  just for a requisition-released one — so checkout and return stay
  symmetric regardless of which path the loan came from.
- `inventory/stock_ledger.php` now links a `direct_checkout` ledger
  entry to the asset's own page, the same way a `requisition`-linked
  entry already links to the requisition.
- A checkout that would take an item below zero (e.g. the catalog
  count was already off) now fails with a clear message instead of
  silently succeeding or throwing a generic error.

**Run this before relying on it:** no schema change — existing
`stock_movements` rows are unaffected; this only changes what gets
recorded going forward. Worth a quick physical recount of any items
that have both catalog stock *and* individually tagged assets, since
past direct checkouts on those items were never reflected in
`quantity_on_hand` and may have left it overstated.

---

Not done this round (optional, only worth it if real usage shows it's
needed): a "keep scanning without restarting the camera per line item"
bulk-scan mode on the release screen, mirroring the continuous-scan
UX `asset_audit.php` already has.
