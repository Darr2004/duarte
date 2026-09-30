# DuaRTE — Inventory Requisition & Equipment Borrowing Management System

A PHP/MySQL system for Duarte Trucking covering authentication and
role-based access control, a digital inventory catalog, online
requisitions with approval workflow, QR-based release verification,
automated stock recording, tool borrowing/loans, reports & analytics,
and a system-wide audit log. Everything plugs into the `users` table
and the `require_role()` guard built in the original Admin/User
Accounts foundation.

## Requirements
- PHP 8.0+
- MySQL 5.7+ / MariaDB
- A local server (XAMPP, MAMP, Laragon, or `php -S`)

## Setup
1. Create the database: import `database/schema.sql` into MySQL
   (e.g. via phpMyAdmin, or `mysql -u root -p < database/schema.sql`).
2. Open `config/config.php` and set `DB_HOST`, `DB_USER`, `DB_PASS`, and
   `BASE_URL` to match your environment. If you place this folder directly
   in your server root, set `BASE_URL` to an empty string `''`.
3. Point your web server at this folder, or from inside it run:
   ```
   php -S localhost:8000
   ```
4. Visit `http://localhost:8000/auth/login.php`.

## Database reset / fixing a broken install
`database/schema.sql` is the **one and only file** you need to set up or
repair the database — every table the app uses (users, categories,
items, stalls/stall_layers, requisitions, notifications,
stock_movements, tool_loans, audit_logs) lives in that single file.
The separate `migration_*.sql` files that used to exist have been
folded into it and are no longer needed.

If phpMyAdmin ever shows an error like `Table 'duarte_db.xxx' doesn't
exist` (e.g. `stock_movements`, `notifications`), it means your
`duarte_db` is missing tables — usually from having run an old,
partial import at some point. To fix it, just re-import
`database/schema.sql`:
- Every `CREATE TABLE` in it uses `IF NOT EXISTS`, so re-running it is
  safe — it only adds whatever tables are missing and leaves your
  existing data alone.
- If you'd rather start completely fresh instead, drop the database
  first (`DROP DATABASE duarte_db;` in the SQL tab), then import
  `database/schema.sql` again to rebuild it from scratch.

`database/seed_stall1_real_data.sql` is optional — only import it if
you want the real Stall 1 inventory data on top of the base schema.

## Default logins
| Username        | Password    | Role             |
|-----------------|-------------|------------------|
| admin           | Admin@123   | admin (incl. Management) |
| inventorystaff  | Staff@123   | inventory_staff  |
| driverhelper    | Driver@123  | driver_helper    |
| fieldsupervisor | Super@123   | field_supervisor |

**Change these passwords immediately** via Edit Account once logged in.
These demo accounts exist so you can test the full workflow across every
role — delete them once real staff accounts are entered.

## What's included

**Foundation (Users & Auth)**
- `database/schema.sql` — `users` and `login_attempts` tables, seeded accounts
- `config/` — DB connection + app settings
- `includes/auth.php` — login, logout, session guards (`require_login`,
  `require_role`), CSRF token helpers
- `includes/header.php` / `footer.php` — shared sidebar layout, nav links
  and cart/notification badges shown per role
- `auth/login.php`, `auth/logout.php`
- `admin/dashboard.php` — account counts + recent login activity
- `admin/users.php`, `user_add.php`, `user_edit.php` — account CRUD

**Digital Inventory Catalog** (addresses problems 3.1, 3.4, 3.5 in the proposal)
- `database/schema.sql` — adds `categories` and `items` tables, seeded
  with 5 categories and 7 sample items
- `includes/functions.php` — `stock_status()` (in stock / low / out,
  based on `reorder_level`), `item_initials()` (placeholder when no photo)
- `includes/uploads.php` — validated image upload (type, size, real
  MIME check), used by both add and edit forms
- `uploads/items/` — stores item photos; `.htaccess` inside blocks any
  script from being executed out of this folder
- `inventory/items.php` — Inventory Staff/Admin: search, filter by
  category, activate/deactivate items
- `inventory/item_add.php`, `item_edit.php` — item CRUD with photo upload,
  replace, or remove
- `inventory/categories.php` — add categories, delete only if unused
- `catalog/browse.php` — search/filter the catalog with live stock badges

**Online Requisition & Approval** (addresses the manual, paper-based
request process described in the proposal)
- `database/schema.sql` — adds `requisitions`, `requisition_items`, and
  `notifications` tables; adds the `field_supervisor` role
- `includes/cart.php` — session-based cart a Driver/Helper builds while
  browsing, before submitting everything as one requisition
- `includes/notifications.php` — in-app notifications (`notify_user`,
  `notify_role`) standing in for the proposal's real-time/SMS alerts
- `catalog/browse.php` — now has an "Add to request" control per item for
  Driver/Helper accounts, blocked once quantity requested would exceed
  what's on hand
- `requisition/cart.php` — review, edit quantities, remove items, add a
  purpose note, and submit; re-validates stock at submission time in case
  it changed since items were added
- `requisition/my_requests.php` — a Driver/Helper's own request history
- `requisition/pending.php` — queue of pending requests for Field
  Supervisor/Admin to review
- `requisition/all.php` — full requisition history, filterable by status
- `requisition/view.php` — request detail; shows the Approve/Decline form
  to Field Supervisor/Admin while pending, or a Cancel button to the
  requester; both sides get notified the moment a decision is made
- `notifications/api.php` — notification inbox, marks items read on view

Approving a request does **not** yet deduct stock — that happens when the
QR code is scanned at release, in the QR Code Generation/Verification module.

**QR Code Generation & Verification** (closes the loop between approval
and physical pickup)
- `database/schema.sql` — adds `qr_token`, `released_by`, `released_at` to
  `requisitions`, and a `released` status
- `includes/functions.php` — `generate_unique_qr_token()`,
  `requisition_status_class()` (shared badge coloring across all
  requisition list pages)
- `requisition/view.php` — the moment a Field Supervisor/Admin approves,
  a unique QR token is generated automatically; the requester's page then
  renders that token as an actual scannable QR code (via the client-side
  `qrcodejs` library) with pickup instructions
- `inventory/verify.php` — Inventory Staff/Admin scan the code with their
  camera (via `html5-qrcode`, with a manual code-entry fallback if no
  camera is available), see the requisition's items, and confirm release.
  Confirming:
  - re-checks stock is still sufficient for every line (in case it changed
    since approval)
  - marks the requisition `released` with a timestamp and who released it
  - deducts `quantity_on_hand` for each item
  - notifies the requester it's ready
  - is blocked server-side from running twice on the same requisition,
    even if the QR is scanned again after release

A full stock movement ledger/audit trail (a running log of every stock-in
and stock-out event, not just the current on-hand number) is left for the
Automated Stock Recording module — this module already keeps
`quantity_on_hand` accurate the moment items are handed over.

**Automated Stock Recording** (the audit trail earlier modules deferred to here)
- `database/schema.sql` — adds `stock_movements`, a full ledger of every
  quantity change: type, signed amount, before/after, who recorded it,
  and what it's linked to
- `includes/stock.php` — `record_stock_movement()`, now the **only**
  place `items.quantity_on_hand` is ever changed. It locks the item row
  (`SELECT ... FOR UPDATE`) inside a transaction, so two staff members
  recording stock on the same item at the same time can't race each
  other, and it refuses to let stock go negative
- `inventory/verify.php` — refactored to call `record_stock_movement()`
  for each line released, instead of a raw `UPDATE`, so every release is
  now in the ledger too (type `release`, linked back to the requisition)
- `inventory/item_add.php` — creating an item with a starting quantity
  now logs that quantity as a `stock_in` movement (`initial_stock`)
  instead of silently setting a number
- `inventory/item_edit.php` — **quantity on hand is no longer editable
  here.** It's shown read-only with a link to Adjust Stock, so every
  change to stock — no matter where it comes from — goes through the
  ledger
- `inventory/stock_in.php` — record incoming stock (deliveries/purchases)
  with a note (supplier, PO number, etc.)
- `inventory/stock_adjust.php` — record a correction (damage, loss,
  miscount) in either direction; a reason is required
- `inventory/stock_ledger.php` — the full movement history, filterable
  by item, movement type, and date range; each item's Catalog Management
  row now links straight to its own history

**Stock Monitoring & Alerts** (closes the loop on the "nobody notices
until they physically check" problem from the proposal)
- `includes/functions.php` — `count_stock_alerts()` (items at or below
  reorder level)
- `includes/stock.php` — `maybe_alert_stock_threshold()`, called after
  every stock-changing action (stock-in, adjustment, QR release) *once
  its transaction has actually committed* — never before, so a rollback
  can't leave behind a false alert. It only fires when a movement
  actually crosses a boundary (in stock → low → out, or back), not on
  every single movement, so staying flat inside the low-stock zone
  doesn't spam the same alert twice
- `inventory/alerts.php` — a live dashboard splitting flagged items into
  Out of Stock and Low Stock, each with a one-click link to record
  incoming stock
- `admin/dashboard.php` — now shows a live count of items needing restock
- Sidebar nav — a Stock Alerts badge for Inventory Staff/Admin shows the
  live count, the same way Cart and Notifications already do

Not alerted on: creating a new catalog item that starts below its
reorder level — that's initial setup, not a depletion event, so it
would just be noise.

**Tool Borrowing & Due Date Monitoring** (distinguishes tools that must
come back from consumables that don't)
- `database/schema.sql` — adds `is_borrowable` to `items` (Inventory
  Staff mark which catalog items are tools vs. consumables), adds
  `is_borrowable`/`requested_days` snapshot columns to
  `requisition_items`, adds `return` as a stock movement type, and adds
  `tool_loans` — one row per borrowed line item, with a due date, who
  borrowed it, and when (if ever) it came back
- `inventory/item_add.php`, `item_edit.php` — a checkbox marks an item
  as a returnable tool; seed data marks the wrench, hammer, impact
  driver, and tow chain as tools, leaving PPE/consumables as one-way
- `includes/cart.php` — cart entries now carry an optional borrow
  duration alongside quantity
- `catalog/browse.php`, `requisition/cart.php` — tools show a "must
  return" badge and a duration picker (1 day / 3 days / 1 week);
  consumables don't get one, since there's nothing to return
- `inventory/verify.php` — releasing a requisition now also opens a
  `tool_loans` row for every borrowable line, with the due date counted
  from the moment of release (when the requester actually has it in
  hand), not from when they submitted the request
- `inventory/loans.php` — Inventory Staff/Admin see every tool
  currently out, its due date and live status, and can mark it returned
  — which restores stock through the same `record_stock_movement()`
  path as everything else (movement type `return`) and re-runs the
  stock alert check in case the return crosses back into "in stock"
- `requisition/my_loans.php` — a Driver/Helper's own borrowing history
- `includes/loans.php` — `check_overdue_loans()` finds loans past due
  that haven't been alerted on yet, notifies the borrower and every
  Inventory Staff/Admin, and marks them so the same loan never alerts
  twice
- `cron/check_overdue.php` — a CLI-runnable script meant to be wired to
  an actual cron job (see the comment in the file for the crontab line)
  so overdue alerts go out even when nobody's in the app. As a fallback
  on hosts without cron access, the same check also runs opportunistically
  whenever Inventory Staff/Admin load the dashboard or Tool Loans page —
  but a real cron job is what makes alerts timely rather than lucky
- Sidebar nav — a Tool Loans badge (overdue count) for Inventory
  Staff/Admin, a My Borrowed Tools link for Drivers/Helpers
- `admin/dashboard.php` — now shows a live overdue-loan count too

**Reports & Analytics** (the last of the 7 modules — Company Management's
system-wide view, restricted to `admin` since that's the merged
Management/Administrator role)
- `reports/index.php` — a date-range dashboard (defaults to the last 30
  days): requisitions submitted, approval rate, average time-to-decide,
  units released, units received, active tool loans and how many are
  overdue right now; plus simple CSS bar charts (no external charting
  library) for requisitions by status, top 5 requested items, top 5
  most-borrowed tools, and top requesters
- `reports/export.php` — CSV export for the same date range, three
  flavors: requisitions, stock movements, and tool loans — each pulling
  straight from the same tables the rest of the app already trusts, so
  the export can never disagree with what's on screen
- `includes/functions.php` — `bar_pct()`, a small helper for the bar
  chart widths

## Notes on the role model
The proposal lists 5 stakeholders (Driver/Helper, Inventory Staff, Field
Supervisor, Company Management, Administrator). Company Management and
Administrator are merged into a single `admin` role — but rather than
giving that role full system oversight, `admin` was later scoped down to
just user account administration and read-only Reports (see "Admin
scoped down to user management + reports" above). `driver_helper` and
`inventory_staff` were added for the catalog module; `field_supervisor`
was added for approvals.

## Security choices worth knowing about
- Passwords are hashed with `password_hash()` (bcrypt), never stored in plain text.
- All queries use PDO prepared statements — no raw SQL string building.
- Every state-changing form includes a CSRF token, checked server-side.
- Session ID is regenerated on login to prevent session fixation.
- Failed and successful logins are both logged to `login_attempts`.
- Uploaded images are validated by extension AND real MIME type, size-capped,
  renamed to a random filename, and served from a folder where PHP execution
  is blocked via `.htaccess`.
- Requisition decisions are re-checked server-side (status must still be
  `pending`), not just hidden in the UI — so a resubmitted or replayed
  approval request can't flip an already-decided requisition.
- Stock availability is re-validated at submission time, not just when an
  item is added to the cart, closing the gap where stock changes in between.
- QR tokens are 32-byte random values (not sequential IDs), so a token
  can't be guessed from a requisition number. Release is blocked
  server-side once a requisition is no longer in `approved` status —
  scanning the same QR twice can't double-release or double-deduct stock.
- Stock is re-checked again at release time (not just at approval time),
  in case it changed in between.
- Every stock change — release, manual stock-in, adjustment, initial
  stock — goes through one function that locks the row and writes an
  audit-trail entry, so `quantity_on_hand` and the ledger can never
  drift apart, and concurrent edits to the same item can't race each other.
- Adjustments require a reason; stock can never be pushed below zero,
  whether by a release, an adjustment, or a race between two staff
  members acting on the same item at once.
- Stock alerts fire only after a database commit succeeds, never inside
  the transaction itself — so a release that fails partway through can't
  leave behind a notification describing a change that got rolled back.
- The same commit-then-notify pattern applies to overdue loan alerts and
  to tool returns.
- Overdue alerts are idempotent (`overdue_notified_at`), so whether
  they're triggered by the cron script, an admin loading the dashboard,
  or both in the same minute, nobody gets the same overdue notice twice.
- Reports and their CSV exports are `admin`-only and read directly from
  the same tables every other module writes to — there's no separate
  reporting datastore that could drift out of sync.

## Tested
This module was run end-to-end on a local PHP 8.3 + MariaDB server before
being handed to you: full requisition lifecycle (add to cart → stock
validation → submit → notify supervisors → approve/decline → QR token
generated → QR rendered on the requester's page → staff looks it up/scans
it → confirm release → stock deducted → requester notified → stock alert
fired when the release pushed an item out of stock), stock recording
(initial stock, manual stock-in, adjustments, negative-stock guard,
ledger filters), stock monitoring (threshold-crossing alerts in both
directions, no duplicate alerts within the same zone), tool borrowing
end to end (loan created only for the tool in a mixed cart, correct due
date, return restoring stock, overdue detection, idempotent
re-notification), and reports (seeded a realistic spread of requisitions,
movements, and loans across a 30-day window, then confirmed every KPI —
requisition count, approval rate, top items, top tools, top requesters —
matched by hand, plus all three CSV exports and the `admin`-only
restriction).

## All 7 modules are built
Foundation (Users & Auth) → Digital Inventory Catalog → Online
Requisition & Approval → QR Code Generation & Verification → Automated
Stock Recording → Stock Monitoring & Alerts → Tool Borrowing & Due Date
Monitoring → Reports & Analytics. Let me know if you'd like any
refinements, a specific bug fixed, or a feature added on top of what's
here.

## Bug-fix pass
A full review pass across every file, testing each fix live rather than
just reading code:

- **Admin could only create/edit `admin` accounts.** `admin/user_add.php`
  and `admin/user_edit.php` were built in the Foundation module before
  the other 3 roles existed, and were never updated afterward — there
  was no way to create a Driver/Helper, Inventory Staff, or Field
  Supervisor account, or to change anyone's role, through the UI at all.
  Both pages now support all 4 roles. Editing your own account locks the
  role field so an admin can't accidentally strip their own access.
- **Deactivating a user (or changing their role) didn't affect their
  current session.** A user who was already logged in kept full access
  under their old role/status until they happened to log out.
  `require_login()` now re-checks the account's status and role against
  the database on each request; a deactivated account is logged out
  immediately with a clear message, and a role change takes effect on
  the very next page load rather than the next login.
- **`inventory/verify.php` could show a broken requisition card after a
  failed release.** When a release attempt failed (e.g. stock changed
  since approval), the error page tried to display requester name and
  item details from a query that never fetched them, and would fall
  back to a blank/undefined display. Failed attempts now always
  re-fetch full requisition details, the same way a successful lookup
  does.
- **`inventory/item_add.php` could leave a transaction open.** Its catch
  block only caught `PDOException`, so any other exception type thrown
  inside the transaction (initial-stock recording, in particular) would
  bubble up as an uncaught fatal error instead of rolling back cleanly.
- **`inventory/item_edit.php` could delete a photo it shouldn't have.**
  If a new photo upload succeeded but the subsequent database save
  failed (e.g. duplicate item code), the *old* photo had already been
  deleted from disk — leaving the item with no photo file at all, even
  though the database still pointed at the (now-gone) old filename. The
  old file is now only removed after the save actually succeeds; on
  failure, only the newly-uploaded file is cleaned up.
- **`inventory/stock_ledger.php`'s type filter didn't know about tool
  returns.** The `return` movement type (added later, for tool loan
  check-ins) was missing from both the filter dropdown and the type
  whitelist, so returns couldn't be filtered and showed an unstyled raw
  label instead of "Tool returned".
- **Report date filters trusted raw URL input.** `reports/index.php` and
  `reports/export.php` now validate the `from`/`to` query parameters
  look like actual dates before using them, falling back to sensible
  defaults otherwise, rather than passing whatever was in the URL
  straight into a date string.
- A handful of smaller defensive fixes: guarding `$_POST['category_id']`
  against being entirely absent rather than just empty.

## Cart drawer (modal), replacing the full-page cart navigation
- Clicking **Cart** in the sidebar no longer navigates to a separate
  page — it slides open a drawer (modal) over the current page, so
  Drivers/Helpers can review and submit their request without losing
  their place in the catalog
- **"Add to request" is now instant** — clicking it no longer reloads
  the page. The button flashes "Added ✓" and the cart badge pulses, via
  `requisition/cart_api.php`, a small AJAX endpoint mirroring the same
  validation `catalog/browse.php` already did (stock checks, CSRF), just
  returning JSON instead of redirecting
- Quantity +/- and Remove inside the drawer update instantly too, and
  the final "Submit request for approval" button submits via the same
  API and redirects straight to the new requisition's page
- **Progressive enhancement, not a replacement** — `requisition/cart.php`
  (the original full-page cart) still exists and still works exactly as
  before. If JavaScript is unavailable, the "Add to request" forms and
  the Cart link fall back to their original plain-HTML behavior
  automatically — nothing breaks, it just loses the animation
- `includes/cart_drawer_fragment.php` — the drawer's line-item markup is
  rendered server-side by the same PHP template style as the rest of
  the app, not built with client-side JS templating

## Admin scoped down to user management + reports
Admin used to have full oversight of every module (a merged Company
Management + Administrator role). That's been narrowed: **Admin now
only handles User Accounts and Reports & Analytics** — no catalog,
stock, verification, tool loans, or requisition approvals. Those stay
exactly where they already were, with the roles that actually perform
them day to day:

| Capability | Who has it now |
|---|---|
| User Accounts (create/edit/roles) | **Admin only** |
| Reports & Analytics (read-only) | Admin, Inventory Staff |
| Catalog Management, Categories | Inventory Staff |
| Stock Ledger, Stock In, Adjustments, Alerts | Inventory Staff |
| Verify & Release (QR scanning) | Inventory Staff |
| Tool Loans (mark returned) | Inventory Staff |
| Browse Catalog | Inventory Staff, Field Supervisor, Driver/Helper |
| Pending Approvals, All Requisitions | Field Supervisor |
| Cart, My Requests, My Borrowed Tools | Driver/Helper |

Reports was later opened up to Inventory Staff too, since they're the
ones running stock day to day and benefit from seeing the trends (top
requested items, most-borrowed tools, stock movement volume) that Admin
mostly wouldn't act on directly.

What changed to make this real, not just cosmetic:
- Every affected page's `require_role()` was narrowed — this is enforced
  server-side, so it's not just hidden nav links; a direct URL visit
  gets a 403.
- `admin/dashboard.php` no longer shows stock/loan widgets that linked
  to pages Admin can no longer open — it now shows account stats by
  role and a link to Reports instead.
- Admin no longer receives notifications about new requisitions, stock
  threshold alerts, or overdue tool loans — those go to Field Supervisor
  and Inventory Staff, the people who actually act on them. Reports
  still gives Admin the aggregate picture without the operational noise.
- `requisition/view.php`'s approval authority check now excludes Admin,
  since approving/declining is exclusively a Field Supervisor action.

## UI/UX refinement pass
- **Color palette** — shifted from the original charcoal/safety-amber
  theme to a warm brown/leather palette (deep espresso sidebar, caramel
  accent, cream page background). Only the values in the `:root`
  variables changed in `assets/css/style.css` — every page already
  references those same variable names, so nothing else needed editing.
- **Mobile navigation** — the sidebar used to dump its full link list
  (10+ items for Admin) above the page content on phones, pushing the
  actual page far down the screen. It's now a collapsible menu: a
  "Menu" button (with a combined badge count) toggles the nav open,
  implemented with a few lines of vanilla JS in `includes/footer.php`
  and CSS in `includes/header.php` — no framework needed.
- **Tables on small screens** — every `table.data` now scrolls
  horizontally on mobile instead of squeezing columns unreadably thin.
- **Forms on mobile** — inputs, selects, and textareas bump to 16px font
  size under 720px width, which stops iOS Safari's auto-zoom-on-focus
  (a common source of "why does the page jump every time I tap a field"
  complaints).
- **Touch targets** — buttons and nav links get more padding on mobile
  for easier tapping.
- **Page headers and filter bars** — stack vertically on narrow screens
  instead of cramming a title and an action button onto one line.
- **Bar charts** (Reports & Analytics) — the label column shrinks on
  mobile so the actual bar stays visible instead of getting squeezed to
  a sliver.

## System Audit Log
Admin-only page (`admin/audit_logs.php`) giving a single, read-only
timeline of every security-relevant or state-changing event across the
system — not just stock movements (which already had their own ledger)
but logins, account changes, catalog edits, and requisition decisions
too.

- **New `audit_logs` table**, part of `database/schema.sql` (it's now
  the single, all-in-one setup file — see "Database reset / fixing a
  broken install" below).
- **`log_audit_event()`** in the new `includes/audit.php` is the single
  place that ever writes to `audit_logs`, the same pattern
  `record_stock_movement()` already uses for `stock_movements`. It
  snapshots the actor's name and role at the time of the event (not
  just a foreign key), so the log stays accurate even if that account
  is later renamed or its role changes.
- **What gets logged:** login success/failure, logout, account
  create/update/activate/deactivate, catalog item create/update,
  category create/delete, stock in, stock adjustments, requisition
  approve/decline/cancel/release, and tool returns.
- **The audit page itself** supports search (person, description, IP),
  filtering by action and entity type, a date range, pagination (25 per
  page), and a "Export CSV" button that pulls the full filtered history
  rather than just the current page — useful for handing history to
  someone outside the system during a review.
- This log is intentionally **read-only from the app** — there's no
  edit or delete UI for it anywhere, which is what makes it useful as
  an actual audit trail rather than just another editable table.

