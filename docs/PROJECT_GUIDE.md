# Marikina E-Concern — Project Guide

Complete reference for the citizen reporting portal and the BasuraAlert waste
module. Written for **defense preparation**: it explains what each module does,
where every file lives, how a request flows through the system, and why the
architecture is shaped the way it is.

> **Deployment note.** This document is safe to distribute. Every credential in
> this repository is redacted, and the root `PROJECT_GUIDE.md` is the guarded
> configuration reference — `.htaccess` returns 403 for it so it can never be
> served over HTTP. Read that file for the key-by-key configuration surface;
> read this one for the system.

---

## 1. What the system is

**Marikina E-Concern** is a PHP/MySQL web portal for the City Government of
Marikina. It replaces two paper-based processes:

| Problem | Module that solves it |
|---|---|
| Residents file complaints in person, on paper, and get no tracking number | **E-Concern** |
| Waste collection schedules, holidays and disruptions are announced by word of mouth | **BasuraAlert** |
| Staff cannot see a queue, a status, or an audit trail | **Admin console** |

| | |
|---|---|
| **Live site** | `https://marikina-econcern-ba.infinityfree.io` |
| **Citizen app (Android)** | `ph.gov.marikina.econcern` |
| **Admin app (Android)** | `ph.gov.marikina.econcern.admin` |
| **Stack** | PHP 8.1+, MySQL (InnoDB / utf8mb4), Bootstrap 5.3.3, vanilla JS + jQuery, Service Worker, Bubblewrap TWA |
| **Framework** | None. Plain PHP with a modular service layer |

### Scale of the codebase

| Area | Count |
|---|---|
| Citizen pages (`public/`) | 26 |
| Admin pages (`admin/`) | 23 |
| Citizen JSON endpoints (`api/`) | 11 |
| Admin JSON endpoints (`admin/api/`) | 25 |
| BasuraAlert service modules (`includes/BasuraAlert/`) | 7 |
| Database tables (production snapshot) | 29 |
| Stylesheets | 50 |
| JavaScript files | 5 |
| Test files (`tests/`) | 14 |
| Build/maintenance scripts (`bin/`) | 8 |
| Third-party network calls | 21 across 9 services |

---

## 2. The three modules

### 2.1 E-Concern — citizen reporting

A resident registers, verifies their email, picks a department and concern
type, describes the problem, attaches up to five photos, and receives a
reference number like `EC-26-00042`. They can then watch the status change
from *New* → *Ongoing* → *Acknowledged* → *Completed*, read a full timeline,
and message the department directly.

### 2.2 BasuraAlert — waste collection

Collection schedules by barangay and waste type, a Leaflet drop-off map,
Philippine holiday calendar, announcements and service alerts, pre-collection
SMS/email reminders, an issue-reporting flow with photo evidence, and a waste
segregation guide.

### 2.3 Admin console — staff operations

Department-scoped queues with assignment, status transitions, internal notes,
two-way messaging, and CSV export. Plus analytics, citizen/department/type
management, and a read-only integration health page.

---

## 3. Architecture

### 3.1 Request flow

```
Browser
  │
  ├─ GET  /public/ba_dashboard.php
  │     ├─ includes/partials/head.php          CSRF meta, PWA meta, CSS
  │     ├─ includes/basuraalert.php            loads db/helpers/auth/sms + 7 modules
  │     ├─ ba_fetch_weather_mock()             → OpenWeather (cached 10m/30m)
  │     ├─ ba_fetch_ph_holidays_mock()         → Calendarific (cached 24h)
  │     └─ includes/partials/foot.php          Bootstrap, Lucide, jQuery, page JS
  │
  └─ POST /api/basuraalert_actions.php
        ├─ require_api_login()                  401 if no session
        ├─ require_csrf_token()                 403 on mismatch
        ├─ ba_create_report()                   transaction
        ├─ ba_store_image_mock()                → Cloudinary (signed upload)
        └─ ba_push_notification()              writes `queued` row, returns
              └─ shutdown flush                 → TextBee / Brevo, never blocks
```

**The two load-bearing ideas:**

1. **Service modules are required by their consumer, not by a bootstrap.**
   `includes/Avatar.php` requires `External.php` itself (line 74), and
   `External.php` requires `Cache.php` itself. `admin/api/citizen.php` reaches
   the avatar code without going through `basuraalert.php`; without the
   self-require, every upload from that endpoint failed with a misleading
   "Upload support is unavailable."

2. **A request never waits on a third party.** Notification producers write a
   `queued` row and return immediately. A registered shutdown handler drains
   the queue after the response. This is forced by the host: **InfinityFree's
   free tier has no cron**, so the dispatcher must run inside some request.

### 3.2 Directory map

```
Marikina Concern/
├── index.php              Outer container → redirects into the project
├── .htaccess              Apache rules (secrets, storage/, PWA headers)
├── .env                   All secrets. git-ignored, never deployed
├── manifest.php           Serves the PWA manifest (see §10.1)
├── manifest.webmanifest   Canonical relative-URL manifest
├── sw.js                  Service worker
├── public/                CITIZEN PAGES (26)
├── admin/                 ADMIN PAGES (23)
│   └── api/               Admin JSON endpoints (25)
├── api/                   Citizen JSON endpoints (11)
├── includes/
│   ├── env.php            .env loader (no Composer)
│   ├── config.php         .env → constants; every setting
│   ├── db.php             mysqli wrapper, prepared statements
│   ├── helpers.php        e(), app_url(), CSRF, password rules, audit log
│   ├── auth.php           Citizen sessions, lockout
│   ├── admin_auth.php     Admin sessions, current_admin()
│   ├── otp.php            OTP generation + verification
│   ├── sms.php            TextBee gateway
│   ├── mailer.php         Brevo, 3-tier fallback
│   ├── email_verification.php
│   ├── Avatar.php         Cloudinary profile photos
│   ├── basuraalert.php    Backwards-compatible loader
│   ├── BasuraAlert/       7 service modules
│   │   ├── Cache.php          file-backed API cache + flock
│   │   ├── Dropoffs.php       drop-off points, geofence
│   │   ├── External.php       ALL third-party API calls
│   │   ├── Notifications.php  queue, flush, preferences
│   │   ├── Reports.php        BasuraAlert reports
│   │   ├── Schedule.php       collection schedules
│   │   └── Validation.php     input validators
│   └── partials/          head/foot + app & admin shells
├── assets/                css/ (50), js/ (5), img/
├── database/              schema + 10 migrations + install snapshot
├── docs/                  This file, deployment + PWA/APK guides
├── bin/                   8 build & maintenance scripts
├── tests/                 14 PHPUnit files
├── storage/               Cache, fixtures, mock data, evidence photos
├── uploads/               Resident-uploaded local copies
├── apk/ apk-admin/        Bubblewrap TWA projects
└── .well-known/           assetlinks.json (TWA verification)
```

### 3.3 Why the architecture is what it is

**Hosting constraints drove three decisions** (from `docs/DEPLOY_INFINITYFREE.md`):

| Constraint | Consequence |
|---|---|
| No SSH, no Composer | PHPMailer is optional — `mailer.php` falls back to native `mail()`. Hand-rolled `env.php` and `lint.php` instead of dependencies. |
| No cron | The notification flush is a PHP shutdown handler, not a scheduled job. `ba_run_reminders.php` / `ba_dispatch_queued_notifications.php` exist for a host that *does* have cron. |
| No shell, File Manager only | `bin/build_deploy_zip.php` builds a safe archive; migrations are idempotent so a re-run is a no-op. |

**Migrations are versioned, but the install is a snapshot.**
`bin/migrate.php` holds an ordered, append-only list. However
`database/full_install_infinityfree.sql` is a **generated snapshot** from a
working database, because the migration chain cannot rebuild from zero —
`citizen_management_migration.sql` adds a column *before* the column it
depends on exists, and several BasuraAlert tables were historically created by
inline SQL in a one-off script. The snapshot is the supported deploy path.

---

## 4. Citizen guide

### 4.1 The journey

| # | Step | Page | What happens |
|---|---|---|---|
| 0 | Land | `index.php` | Redirects to `public/login.php`; or `public/index.php` landing page if signed out |
| 1 | Register | `register.php` | CSRF check → validate → `password_hash` → transaction: insert user, create verification token, send email via Brevo → redirect to notice page |
| 2 | Verify | `verify_notice.php` → `verify_email.php?token=` | Masked address shown. Token valid **24h**. Success **auto-logs the user in**. |
| 3 | Log in | `login.php` | Lockout check → `password_verify` → branch: unverified (auto-resend, 180s cooldown) / OTP enabled / straight in |
| 4 | OTP | `login_otp.php` | 6-digit code by **SMS (TextBee)**. 6 separate inputs, paste handler, auto-submit, countdown. **5 min** lifetime, **120s** resend cooldown, **5** attempts. |
| 5 | Home | `dashboard.php` | Five live status counts via `/api/dashboard_counts.php` |
| 6 | Submit | `submit_concern.php` | Department accordion (searchable) → details → up to 5 photos → confirmation modal requiring *"I confirm that this is not a prank report."* |
| 7 | Track | `my_concern.php` → `concern_view.php?id=` | Status chips, debounced search, photo lightbox, full timeline, chat with the department |
| 8 | BasuraAlert | `ba_dashboard.php` | Weather, holiday banner, next collection, announcements, reminders |
| 9 | Profile | `profile.php` | Identity, barangay, 2FA toggle, notification prefs, password, avatar |

### 4.2 Navigation

Two menus, **rendered in three places** (mobile drawer <768px, desktop dropdowns
≥768px, mobile top bar). Defined once in
`includes/partials/app_shell_start.php`:

**BasuraAlert** — 9 items

| Label | Page |
|---|---|
| Dashboard | `ba_dashboard.php` |
| Collection Schedule | `ba_schedule.php` |
| Waste Segregation | `ba_waste.php` |
| Drop-off Map | `ba_dropoff_map.php` |
| Announcements | `ba_announcements.php` |
| Notifications | `ba_notifications.php` *(unread badge)* |
| Report an Issue | `ba_report.php` |
| My Reports | `ba_my_reports.php` |
| FAQ & Contact | `ba_faq.php` |

**E-Concern** — 3 items

| Label | Page |
|---|---|
| Submit Concern | `submit_concern.php` |
| My Concern | `my_concern.php` |
| Profile | `profile.php` |

**Every citizen sees the same 12 items.** There is no role branching in the
citizen shell at all.

### 4.3 Every citizen page

**Unauthenticated**

| Page | Purpose |
|---|---|
| `index.php` | Public landing. Bilingual hero, 4 service tiles, City Hall contact |
| `register.php` | Sign-up with live password-strength meter and RA 10173 consent |
| `verify_notice.php` | "Check your inbox" interstitial, masked address |
| `verify_email.php` | Consumes the token; auto-login on success |
| `resend_verification.php` | Manual resend, 60s cooldown, enumeration-safe |
| `login.php` | Email + password, full branching |
| `login_otp.php` | SMS OTP challenge |
| `forgot_password.php` | Reset request, 60-min token |
| `reset_password.php` | Set new password |
| `logout.php` | Sign out |
| `offline.php` | PWA offline fallback |

**E-Concern (authenticated)**

| Page | Capability |
|---|---|
| `dashboard.php` | Live counts by status |
| `submit_concern.php` | Two-step wizard. Department + type, street/barangay/landmark, description ≤500, up to 5 photos ≤5MB each |
| `my_concern.php` | 6 status chips, 80ms-debounced search, keyboard-navigable rows |
| `concern_view.php` | Details, photos, timeline, chat modal (Enter to send) |
| `profile.php` | Six cards: identity, barangay, 2FA, notification prefs, password, avatar |

**BasuraAlert (authenticated)**

| Page | Capability |
|---|---|
| `ba_dashboard.php` | Weather card, holiday banner + 12-month modal, next collection, 5 latest announcements, quick stats, recent reports. **Redirects to profile if no barangay set.** |
| `ba_schedule.php` | List **and** calendar views. Filter by barangay/waste type/schedule type, live search, month navigation (±10y), day-detail modal, phone agenda |
| `ba_waste.php` | Segregation guide. Search + category filter, per-item prep and disposal guidance, Accepted / NOT accepted badges |
| `ba_dropoff_map.php` | Leaflet map. Filter by barangay/waste/24-7, click pin for accepted-waste pills and hours, **"use my location, show nearest 3"** with Haversine, deep-link support |
| `ba_announcements.php` | 7 kinds (Holiday, Disruption, Delay, Cancellation, Resumption, Schedule Change, General), barangay vs city-wide, revised-schedule block |
| `ba_report.php` | Report an issue. **At least 1 photo required**, ≤5. Upload progress bar. Explicit scope reminder: this does *not* book on-demand pickup |
| `ba_my_reports.php` | List/detail, public-only timeline (internal notes filtered out), **1–5 star rating** once resolved |
| `ba_notifications.php` | All/Unread tabs, mark-all-read, per-row and bulk delete with a typed `DELETE` guard, channel + delivery status on every card |
| `ba_notification_preferences.php` | Full matrix: 6 events × 3 channels = 18 checkboxes, plus 1–72h lead-time slider |
| `ba_faq.php` | Three tabs: FAQ accordion, Feedback, Contact Us. Photos optional ≤5 |

### 4.4 Citizen JSON API

All 11 endpoints call `require_api_login()` (401) and `require_csrf_token()` (403).

| Endpoint | Method | Purpose |
|---|---|---|
| `api/dashboard_counts.php` | GET | The caller's own concern counts by status |
| `api/departments.php` | GET | Active departments + their active types (for the submit wizard) |
| `api/submit_concern.php` | POST | Create a concern. 413 on `post_max_size` overrun |
| `api/concerns.php` | GET | The caller's own concerns, filtered and searched, `LIMIT 200` |
| `api/concern.php` | GET | One concern. 404 if not found **or not owned** |
| `api/concern_timeline.php` | GET | Status history, oldest first |
| `api/concern_messages.php` | GET | Chat thread |
| `api/post_message.php` | POST | Citizen sends a message |
| `api/change_password.php` | POST | Re-verifies the stored hash after update |
| `api/profile_basuraalert.php` | POST | Multi-action: `upload_avatar`, `delete_avatar`, `update_identity`, `save_barangay`, `save_notification_prefs`, `save_otp_pref` |
| `api/basuraalert_actions.php` | POST | Multi-action: `submit_report`, `mark_report_rating`, `mark_notifications_read`, `delete_notification`, `delete_all_notifications` |

### 4.5 Validation rules worth knowing

**Passwords** — `validate_password_rules()` in `helpers.php` is the single
source of truth, mirrored in the browser by `assets/js/password-rules.php`:
≥8 characters **and** a lowercase, an uppercase, a digit, and a symbol.

**Uploads** — verified server-side with `finfo_open(FILEINFO_MIME_TYPE)` against
an allowlist of `image/jpeg`, `image/png`, `image/gif`, `image/webp`. Filenames
are never trusted. Avatar limit is tighter (2 MB / 4000 px) than evidence
photos (5 MB).

**Dates** — a BasuraAlert report's `date_of_concern` must parse **and must not
be in the future**.

**Enumeration safety** — forgot-password and resend-verification always return
the same message whether or not the address exists.

---

## 5. Admin guide

### 5.1 Logging in

`admin/login.php` accepts email **or** name plus password. If the account has
`otp_enabled = 1` it redirects to `admin/login_otp.php` for an SMS code.
Brute-force protection: **15 attempts → 10-minute lock**. The error message is
identical for "no such account" and "wrong password".

### 5.2 Two roles, four enforcement layers

| Layer | Where | Effect |
|---|---|---|
| 1. UI gating | Nav arrays in `admin_shell_start.php` | Management items don't render for department admins |
| 2. Page-level | `if (!$isSuper) { http_response_code(403); … exit; }` | The 6 super-only pages `exit` **before** their scripts run |
| 3. Endpoint-level | `require_super_admin($admin)` | JSON 403 |
| 4. **Row-level SQL** | Appended to every query | `AND d.id = ?` with the department derived from the *session role*, never from client input |

Layer 4 is what actually matters. The scoping pattern repeats in ~13 endpoints:

```php
if (($admin['role'] ?? '') === 'department_admin') {
    $where .= ' AND d.id = ?';
    $params[] = (int) ($admin['department_id'] ?? 0);
}
```

The department comes from `concerns → concern_types → departments`, so a
department admin cannot read another department's queue by changing a URL
parameter.

| Capability | super_admin | department_admin |
|---|---|---|
| See the management nav | ✅ | ❌ |
| Assign a concern to anyone | ✅ | ❌ self only (403 otherwise) |
| Edit departments, types, admins, citizens | ✅ | ❌ |
| Write BasuraAlert content (waste, announcements, FAQs, drop-off points, push) | ✅ | ❌ (view-only) |
| Analytics + CSV export | ✅ | ❌ |
| Enable/disable accounts | ✅ | ❌ |

**Last-super-admin protection.** The system refuses to disable, demote, or
self-disable the last active super admin. There is no recovery path without
`bin/recover_admin.php`.

**Archive ≠ inactive.** Archiving a citizen sets `deleted_at = NOW(), active = 0`.
Restoring clears `deleted_at` but deliberately **leaves `active = 0`** — a spam
account should not get working credentials back from one click.

**Disabling is immediate.** `current_admin()` returns `null` when `active = 0`,
so the shell force-logs-out on the next page load. No lingering session.

### 5.3 Every admin page

**Auth (outside the shell)**

| Page | Purpose |
|---|---|
| `login.php` | Staff login step 1 |
| `login_otp.php` | SMS OTP step 2 |
| `logout.php` | Sign out |
| `setup.php` | **Unauthenticated.** First-run super admin creation and account recovery, gated by `SETUP_RECOVERY_KEY` via a 15-minute session grant. Also renders a credential-integrity diagnostic table |

**Core (all roles)**

| Page | Capability |
|---|---|
| `dashboard.php` | KPI cards, 30-day stacked status chart, per-department progress, holiday banner, live weather |
| `concerns.php` | Queue with status chips, department select, date range, debounced search, deep-linkable filters |
| `concern_view.php` | Details, photos, timeline, messages, **Update Status** (note mandatory), **Internal Notes** (admin-only), assignment panel |

**BasuraAlert (all roles view; super admin writes)**

| Page | Capability |
|---|---|
| `ba_dashboard.php` | Report counts, drop-off counts, >24h pending queue, KPI tiles, filtered CSV export. Each widget in its own `try/catch` so one failing query can't blank the page |
| `ba_schedules.php` | Create/edit/delete schedules, including the drop-off ⇄ collection bidirectional link |
| `ba_waste.php` | Segregation guide CRUD |
| `ba_dropoffs.php` | 3-step map editor (Pin → Info → Waste). **Server-side geofence** rejects pins outside Marikina |
| `ba_announcements.php` | Compose and publish. Publishing fans out per-resident notifications respecting each person's channel prefs, then renders a **delivery report** |
| `ba_notifications.php` | Push to all / a barangay / one user. Type + channel selection. Shows recent deliveries with status pills |
| `ba_reports.php` | Resident issue reports. Mandatory stepper, resolution note required to close, internal handling note, resident rating display |
| `ba_feedback.php` | Two tabs: feedback/contact messages (reply inline) and FAQ CRUD |

**Management (super admin only)**

| Page | Capability |
|---|---|
| `admin_accounts.php` | Create/update admins, set role + department, reset another admin's password |
| `departments.php` | Search, active toggle per department, KPIs |
| `concern_types.php` | Grouped by department, active toggle |
| `citizens.php` | Search, active toggle, archive/restore, link to detail |
| `citizen_view.php` | View/edit a citizen, upload their avatar, list their concerns |
| `reports.php` | Date-range presets, KPI tiles, SVG daily volume chart, status mix, average resolution time, per-department matrix, CSV export |
| `integration_status.php` | **Read-only health page** for every third-party API. Not in the nav |

**Profile (all roles)** — `profile.php`: avatar, name/email/mobile (email change
needs the current password), 2FA toggle, password change. Role, department and
`active` are deliberately not editable here.

### 5.4 Admin navigation

Same shell pattern as the citizen side. All roles see **BasuraAlert** (2 core +
8 module) and **Profile**; `super_admin` also sees a **Management** group:

| Label | Page |
|---|---|
| Departments | `departments.php` |
| Concern Types | `concern_types.php` |
| Admin Accounts | `admin_accounts.php` |
| Citizens | `citizens.php` |
| Analytics | `reports.php` |

### 5.5 Admin JSON API (25 endpoints)

All gated by `require_api_admin_login()` (401 JSON) + CSRF. Super-admin-only
ones are marked ★.

| Group | Endpoints |
|---|---|
| Concerns | `concerns.php`, `concern.php`, `concern_timeline.php`, `concern_messages.php`, `concern_notes.php`, `post_message.php`, `update_status.php`, `assign_concern.php` |
| Dashboard | `dashboard_counts.php`, `dashboard_charts.php`, `recent_concerns.php` |
| Reference data | `departments.php`, `concern_types.php` |
| ★ Management | `admins.php`, `citizens.php`, `citizen.php`, `citizen_concerns.php`, `reports_summary.php`, `reports_export.php` |
| Self-service | `update_profile.php`, `change_password.php` |
| BasuraAlert | `basuraalert_admin.php` (multi-action router), `ba_dropoffs_admin.php`, `ba_geocode_reverse.php`, `ba_reports_export.php` |

`basuraalert_admin.php` runs with `@ignore_user_abort(true)` and
`set_time_limit(60)` because a publish can queue hundreds of notifications.

### 5.6 What a status change actually does

`update_status.php` does **not** assign. In one transaction it updates the
status and writes a `concern_timeline` row with `old_status` and `new_status`.
The note is **mandatory** — a bare status flip with no explanation is rejected,
because the citizen sees this timeline.

Assignment is a separate endpoint that writes an `assigned` event with
`old_status`/`new_status` deliberately `NULL`, so assignment never appears as a
status change.

---

## 6. BasuraAlert deep-dive

### 6.1 The notification queue

This is the most interesting design decision in the project.

```
Producer                          Consumer
────────                          ────────
ba_push_notification()
  ├─ in_app  → delivery_status = 'sent'    (immediate, no provider)
  └─ sms/email → delivery_status = 'queued' (returns immediately)
                          │
                          ▼
        ba_register_notification_flush($db)
          └─ registers a PHP shutdown handler:
               • bails on fatal error
               • session_write_close()
               • fastcgi_finish_request()      ← response is already out
               • ob_start() / ob_end_clean()   ← never append to sent JSON
               • ba_flush_queued_notifications()
                     └─ SMS → send_sms()             (TextBee)
                     └─ email → ba_brevo_send_…()    (Brevo)
```

**Why this exists.** It originally lived inline in one endpoint only, so every
*admin* queue producer — feedback replies, announcement fan-out, report status
updates — had no drain. Rows sat at `queued` forever, because InfinityFree has
no cron to run a worker. `NotificationQueueSchemaTest` now asserts that
**every** queue producer calls `ba_register_notification_flush()`.

**Retries** live in `ba_dispatch_queued_notifications.php`: 3 attempts with
backoff `[30s, 60s, 300s]` plus jitter, then "GIVE UP". Prefer that on a host
with cron; the shutdown flush is the fallback.

**Reminder de-duplication.** `ba_run_reminders.php` fingerprints each message as
`md5(type|user|ref_table|ref_id|title|first-80-chars)` so re-running the
scheduler cannot double-send.

### 6.2 Channels and preferences

Three channels: `in_app` (immediate), `sms` (TextBee), `email` (Brevo).

Preferences are stored as a global switch **and** a per-event switch: 6 events
× 3 channels = 18 columns. Defaults are all on, with a 12-hour reminder lead
time (clamped 1–72h). `ba_push_notification_via_prefs()` falls back to
`in_app` if a resident has switched everything off, so a notification is never
silently lost.

### 6.3 Drop-off geofencing

The admin map lets you drag a pin, but the **server** re-validates:

1. Philippines bounding box (lat 5–21, lon 116–128) → 400
2. Marikina bounding box (14.5968–14.6926 N, 121.0596–121.1441 E) → 422
3. Haversine distance ≤ 4200 m from the nearest seeded drop-off → 422
4. `ST_Contains` point-in-polygon against `barangays.boundary_poly` when valid

Client-side validation is a convenience; the server is the gate. `DropoffHoursFormatTest`
additionally pins the browser's `isValidHoursFormat()` to the server's
`ba_validate_operation_hours()` so the two cannot drift.

### 6.4 Waste segregation and drop-off linkage

`ba_collection_schedules` and `ba_dropoff_schedules` are bidirectionally linked
via `linked_type` / `linked_id` / `linked_group_uid`, with
`UNIQUE(linked_type, linked_id, waste_type)` preventing duplicates.

> **⚠️ A real inconsistency, worth knowing if a panel member reads the schema.**
> The two tables number days of the week differently:
> `ba_collection_schedules.day_of_week` is **1=Sun … 7=Sat**, while
> `ba_dropoff_schedules.day_of_week` is **0=Sun … 6=Sat**. The linkage code adds
> `+1` to reconcile. It is commented in the code but is an easy trap.

---

## 7. Database

**Engine:** InnoDB, `utf8mb4` throughout. **29 tables** in the production
snapshot.

### 7.1 E-Concern

| Table | Purpose | Notable columns |
|---|---|---|
| `users` | Residents | `email` UNIQUE, `password_hash`, `barangay`, `active`, `otp_enabled`, `email_verified_at`, `locked_until`, `avatar_public_id`, `deleted_at` |
| `departments` | LGU offices (18 seeded) | `name` UNIQUE, `active` |
| `concern_types` | Categories per department | `UNIQUE(department_id, name)` |
| `admins` | Staff logins | `role` ENUM, `department_id` FK, `active`, `otp_enabled`, `avatar_public_id` |
| `concerns` | A filed concern | `report_number` UNIQUE `EC-YY-NNNNN`, `status` ENUM, `photos_json` JSON |
| `concern_timeline` | Status + assignment audit | `event_type` (`status_change`\|`assigned`), `old_status`, `new_status` |
| `concern_messages` | Admin↔resident thread | `sender` ENUM, `seen_by_admin`, `seen_by_user` |
| `concern_notes` | Internal notes | Never exposed to citizens |
| `admin_activity_log` | Privileged-action trail | `old_value` / `new_value` as separate JSON columns, `ip_address`, `user_agent` |

### 7.2 Auth

| Table | Purpose |
|---|---|
| `user_email_verifications` | `token_hash` CHAR(64) UNIQUE, `expires_at`, `consumed_at` |
| `user_login_otps` / `admin_login_otps` | `otp_hash`, `attempt_count`, `max_attempts`, `consumed_at` |
| `user_password_resets` | Plus `requested_ip` and `consumed_ip` |
| `login_failures` | Brute-force audit: `realm` ENUM, `identifier`, `ip_address` |
| `admin_notifications` | Admin inbox |

**Tokens and OTPs are stored as SHA-256 hashes, never plaintext**, and every one
has `expires_at` + `consumed_at` for single use.

### 7.3 BasuraAlert

| Table | Purpose |
|---|---|
| `barangays` | 16 Marikina barangays + `boundary_poly` for reverse geocoding |
| `ba_collection_schedules` | Curbside schedules, `waste_type` + `schedule_type` ENUMs, linkage columns |
| `ba_dropoff_points` | Official pins, `pickup_type` ENUM, 5 `accepts_*` flags, `status` ENUM |
| `ba_dropoff_schedules` | Per-drop-off day windows (see the DOW warning in §6.4) |
| `ba_waste_guide` | Item, category, accepted, prep + disposal guidance |
| `ba_announcements` | 7 `kind`s, barangay or city-wide scope, revised schedule info |
| `ba_reports` | Issue reports. `resolution_note` (public) and `internal_handling_note` (admin-only) |
| `ba_report_timeline` | Per-report audit with `is_internal` flag |
| `ba_report_ratings` | 1–5 stars, `UNIQUE(report_id, user_id)` |
| `ba_feedback` | Feedback / FAQ suggestion / Contact Us, with optional photos |
| `ba_faqs` | Public FAQ with `sort_order` |
| `ba_notifications` | **The queue** — `channel`, `delivery_status`, `retry_count`, `next_attempt_at`, `sent_at` |
| `ba_notification_preferences` | Per-user per-event per-channel opt-ins |
| `migrations` | Applied-migration tracker |

### 7.4 Internal vs public separation

Two columns carry the distinction and both are enforced on read:

- `ba_reports.resolution_note` (public) vs `internal_handling_note` (admin)
- `ba_report_timeline.is_internal` + `internal_note`, filtered out of the
  citizen timeline at `ba_my_reports.php`

### 7.5 Migration approach

`bin/migrate.php` runs an **append-only** ordered list. Never insert a migration
in the middle — a file is skipped by name once recorded in `migrations`.

```powershell
C:\xampp\php\php.exe bin\migrate.php --status
C:\xampp\php\php.exe bin\migrate.php --dry-run
```

It has its own quote- and backtick-aware SQL splitter, and treats duplicate-column
/ duplicate-key / already-exists errors as **ignorable**, including the
`mysqli_sql_exception` that PHP 8.1 throws. Every post-baseline migration wraps
each statement in an `INFORMATION_SCHEMA` check, so re-running is a no-op.

---

## 8. API reference

**21 network calls across 9 services.** All outbound HTTP is `curl`.

### 8.1 The service matrix

| Service | Endpoint(s) | Used for | Config key |
|---|---|---|---|
| **Brevo** | `POST /v3/smtp/email` | Verification, password reset, notification email | `BREVO_API_KEY` |
| Brevo (fallback) | `smtp-relay.brevo.com:587` via PHPMailer STARTTLS, then native `mail()` | Second and third email tiers | `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` |
| **TextBee** | `POST /api/v1/gateway/send-sms` | Login OTPs, all BasuraAlert SMS | `SMS_TEXTBEE_API_KEY`, `SMS_TEXTBEE_DEVICE_ID` |
| **Cloudinary** | `POST /v1_1/{cloud}/image/upload` | Evidence photos + profile avatars (signed) | `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` |
| Cloudinary | `POST /image/destroy` | Avatar removal (best-effort, row cleared first) | same |
| Cloudinary | `GET /image/upload/c_thumb,…` | Avatar delivery | same |
| **OpenWeather** | `GET /data/2.5/weather` | Current conditions | `OPENWEATHER_API_KEY` |
| OpenWeather | `GET /data/2.5/forecast` | 2-day outlook | same |
| **Calendarific** | `GET /api/v2/holidays` | PH holidays | `CALENDARIFIC_API_KEY` |
| **Nominatim / OSM** | `GET /search`, `GET /reverse` | Geocoding + reverse geocoding | *no key* |
| **Leaflet 1.9.4** | unpkg CDN (with SRI) | Both maps | *hard-coded* |
| **OSM tiles** | `{s}.tile.openstreetmap.org` | Raster basemap | *hard-coded* |
| Bootstrap 5.3.3 | jsDelivr | Grid, modals, offcanvas | *hard-coded* |
| Google Fonts | fonts.googleapis.com | Montserrat, Merriweather | *hard-coded* |
| jQuery 3.7.1 | code.jquery.com | Page scripts | *hard-coded* |
| Lucide | unpkg | All icons | *hard-coded* |

### 8.2 How email sending degrades

`includes/mailer.php` tries three tiers and only reports failure if all three
fail:

1. **Brevo REST API** (`_mailer_send_brevo_api`) — 8s, one retry at 12s on
   transient cURL errors
2. **PHPMailer SMTP** — only if `includes/vendor/PHPMailer/` exists (not
   deployed; no Composer on the host)
3. **Native `mail()`**

### 8.3 Caching — the quota arithmetic

Every integration is read by **three dashboards on every page render**. Each is
cached on disk under `storage/cache/` (blocked from HTTP by `.htaccess`, so no
DB migration is needed to deploy).

| Key | Default | Reasoning |
|---|---|---|
| `CALENDARIFIC_CACHE_TTL` | `86400` (24h) | Free tier is **500 calls per MONTH** (no daily reset). Uncached: 3 dashboards × 20 views/day ≈ 1,800/month = **3.6× over budget** → guaranteed 429. Cached: ~30/month, **~16× headroom** |
| `OPENWEATHER_CURRENT_CACHE_TTL` | `600` (10m) | Weather changes slowly; without it, three dashboards re-request identical data within one minute |
| `OPENWEATHER_FORECAST_CACHE_TTL` | `1800` (30m) | `/forecast` is a separate quota call and stable for half an hour |
| `CALENDARIFIC_FAIL_TTL` | `300` | Failure backoff |
| `OPENWEATHER_FAIL_TTL` | `120` | Failure backoff |

**Three properties make the cache safe:**

- **Atomic writes.** Temp file + `rename()`, so a concurrent reader never sees a
  half-written file.
- **Stale-if-error.** When the live API fails, `ba_cache_read_stale()` serves
  slightly old *real* data and refreshes its mtime, so a dead provider costs one
  connect timeout per TTL rather than one per page view. The outage path is
  therefore the **cheapest** path, not the most expensive.
- **Failure backoff.** A live-call failure writes a short-lived `*_fail` marker
  that suppresses further attempts for its window. It expires on its own, so
  recovery needs no manual step.
- **`flock()` on the miss → fetch → write cycle.** Eight concurrent cold-cache
  renders cost **one** API call, not eight. Without this a burst right after
  deploy would burn a month's Calendarific quota in seconds.

**A write failure is logged, not silent.** If `storage/` is unwritable the code
emits `cache_write_failed` to `app_error.log` — otherwise an unwritable
directory looks identical to a healthy cache miss and quota drains invisibly.

### 8.4 Honesty rules in the UI

Both external-data panels state their provenance rather than asserting "Live":

| Source | Weather label | Holiday label |
|---|---|---|
| `live_api` | Live from OpenWeather | Fetched live from Calendarific |
| `cache` | Live · cached Ns ago | Calendarific data (cached Ns ago) |
| `cache_stale` | Outlook from cached copy — forecast API did not respond | Cached copy — live API did not respond |
| `fixture` | **Sample data, not live** | **Sample data, not live** |

Missing values render as an em dash, **never** `0°` or `0%`. A cached zero is a
lie the reader cannot detect.

The forecast buckets its 40 three-hour slots using `city.timezone` from the
response with **`gmdate()`**, and today is labelled "rest of day" because it
only holds the slots still ahead. Getting this wrong shifts every day label by
the server's own timezone offset.

### 8.5 Health checks

`admin/integration_status.php` is **read-only** — it never sends an SMS, sends
an email, or uploads a file.

| Provider | Probe | When |
|---|---|---|
| Brevo | `GET /v3/account` | Every load (unlimited) |
| Cloudinary | `GET /config` (Basic auth) | Every load — validates key **and** secret without creating an asset |
| OpenWeather | `GET /weather` | **`?probe=1` only** (metered) |
| Calendarific | `GET /holidays` | **`?probe=1` only** (metered) |

It also shows cache state (exists / valid / fresh / stale, age, size, TTL) and
the tail of `app_error.log` integration events.

---

## 9. Configuration

Everything lives in `.env`, loaded by `includes/env.php` and turned into
constants by `includes/config.php`. **38 keys**, zero drift between the file and
the code that reads it.

| Group | Keys |
|---|---|
| App | `APP_NAME`, `APP_BASE_URL`, `APP_PUBLIC_URL` |
| Database | `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` |
| Email | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `BREVO_API_KEY` |
| SMS | `SMS_PROVIDER`, `SMS_TEXTBEE_BASE_URL`, `SMS_TEXTBEE_ENDPOINT`, `SMS_TEXTBEE_API_KEY`, `SMS_TEXTBEE_DEVICE_ID`, `SMS_SENDER` |
| Images | `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`, `CLOUDINARY_UPLOAD_PRESET` |
| External | `CALENDARIFIC_API_KEY`, `OPENWEATHER_API_KEY` |
| Cache | the 5 TTL keys in §8.3 |
| Mocks | 6 `MOCK_*` keys |
| Recovery | `SETUP_RECOVERY_KEY` |

### 9.1 The two URL keys, and a trap

| Key | Meaning |
|---|---|
| `APP_PUBLIC_URL` | The public origin baked into emailed verification and reset links. **Must** be the real origin in production or those links are unreachable. |
| `APP_BASE_URL` | The subfolder the app is served from. `""` for a root deploy, `/some/subfolder` otherwise. |

`APP_BASE_URL=""` is **correct** for a root deployment: `app_base_url()` returns
`''`, so `app_url('/public/login.php')` yields `/public/login.php` and the
session cookie path collapses to `/`. A single wrong value here breaks every
asset URL and the cookie path at once.

### 9.2 Test modes

The 6 `MOCK_*` keys select bundled fixtures instead of live APIs. They exist for
offline development only — **with credentials present the app always prefers
live data** and falls back to fixtures only when the provider is unreachable.
`config.php` notes the consequence: that fallback is exactly why an *uncached*
integration looks identical to a working one on screen.

---

## 10. PWA and Android

### 10.1 Why `manifest.php` exists

The canonical `manifest.webmanifest` is never served directly. **InfinityFree's
bot filter answers static `.webmanifest` requests with an HTML challenge page**,
Chrome then reports `Manifest: Line 1, column 1, Syntax error` and refuses to
install. `manifest.php` streams the file with the correct
`application/manifest+json` content type, so the PWA installs.

`bin/build_apk_project.js` has the same constraint — it reads the manifest from
the **local** server, not from InfinityFree, because the bot filter would hand
Bubblewrap the challenge page and the build would die with `Unexpected token '<'`.

### 10.2 Service worker strategy

`sw.js`, `CACHE_VERSION = 'econcern-v1'`:

| Request type | Strategy |
|---|---|
| Static (`/assets/`, css/js/img/font) | **Cache-first**, ignoring query strings |
| Pages and API | **Network-first**, cached on success |
| Navigation, offline | → `public/offline.php` |

Never caches non-GET, and deliberately excludes `/admin/setup.php`,
`/verify_email.php` and `/reset_password.php`.

`.htaccess` sends `Service-Worker-Allowed: /` plus `no-cache` for `sw.js` so a
new worker is never masked by the HTTP cache.

### 10.3 Two Android apps

Built with Bubblewrap (Trusted Web Activity), sharing one keystore so
`.well-known/assetlinks.json` can list both package names.

| | Citizen | Admin |
|---|---|---|
| **Package** | `ph.gov.marikina.econcern` | `ph.gov.marikina.econcern.admin` |
| **Launcher** | E-Concern | E-Concern Admin |
| **Starts at** | `/public/index.php` | `/admin/login.php` |
| **Shortcuts** | Submit, Schedule, My Concerns, Admin Login | Login, Dashboard, Concerns, Schedule |
| **Fallback** | Custom Tabs | Custom Tabs |

```powershell
node bin\build_apk_project.js all
cd apk      ; bubblewrap build --manifest=./twa-manifest.json
cd ..\apk-admin ; bubblewrap build --manifest=./twa-manifest.json
```

Without the matching SHA-256 fingerprint in `assetlinks.json`, TWA silently
falls back to a Custom Tab and the user sees a browser URL bar.

Icons are regenerated by `bin/build_icons.ps1`. It exists because the shipped
icons were dark-on-dark — a navy seal on a navy maskable background — and were
effectively invisible on a home screen.

---

## 11. Build and deploy

| Script | Purpose |
|---|---|
| `bin/lint.php` | Cross-platform `php -l` sweep. `--quiet` for failures only |
| `bin/migrate.php` | Versioned migrations. `--status`, `--dry-run`, `--fresh` |
| `bin/recover_admin.php` | CLI-only last-resort admin rescue |
| `bin/build_infinityfree_sql.php` | Generates the install snapshot from `mysqldump` |
| `bin/build_deploy_zip.php` | Safe archive for the File Manager |
| `bin/build_github_zip.php` | Public-repo archive; **verifies** no `.env` value leaked |
| `bin/build_apk_project.js` | Generates both TWA projects non-interactively |
| `bin/build_icons.ps1` | Regenerates the icon set |

### 11.1 Deploy order

1. `php bin\lint.php` — must be clean
2. `php bin\build_deploy_zip.php` — writes `deploy.zip`
3. File Manager → `htdocs/` → **Upload & Unzip** → extract → tick overwrite
4. Create `.env` by hand. **It is excluded from the archive on purpose** —
   extracting over a live `.env` would destroy every credential
5. Make `uploads/` and `storage/mock_images/` writable (chmod 755)
6. Import `database/full_install_infinityfree.sql` via phpMyAdmin
7. Open `/admin/setup.php` once with a temporary `SETUP_RECOVERY_KEY`, create the
   first super admin, then **empty the key again**

### 11.2 What is excluded from the deploy archive

`.env`, `app_error.log`, `tests/`, `vendor/`, `.git/`, `storage/cache/`,
`storage/qa_harnesses/`, `uploads/*`, `docs/`, `apk/`, `apk-admin/`, and — by
suffix — `*.log`, `*.zip`, `*.jks`, `*.keystore`. The suffix rule means a
signing key can never end up in an archive even if it is dropped in the project
root by mistake.

`bin/build_github_zip.php` is deny-by-default and then **verifies** the output
by searching the archive for every real `.env` value plus generic credential
patterns. A hit is a hard failure with a non-zero exit. Run it rather than
zipping the folder by hand.

---

## 12. Security

| Control | Implementation |
|---|---|
| **CSRF** | 32-byte token per session. Checked on every non-GET. `hash_equals`. Mismatch → **403 with `hint: csrf_mismatch`** (not 419 — this Apache build turns 419 into a 500) |
| **Passwords** | `password_hash(PASSWORD_DEFAULT)` / `password_verify` everywhere |
| **Tokens & OTPs** | SHA-256 hashed, single-use via `consumed_at`, always with `expires_at` |
| **Brute force** | 15 attempts → 10-minute lock, plus a `login_failures` audit table |
| **Session cookies** | `httponly`, `samesite=Lax`, `secure` auto-detected, path from `APP_BASE_URL` |
| **Session separation** | `user_id` and `admin_id` are distinct keys, so both portals can be logged in at once |
| **Audit trail** | `admin_activity_log` splits before/after into two JSON columns; an audit failure never blocks a legitimate update |
| **Open redirect** | `safe_redirect_target()` blocks `//host`, `/\host`, absolute cross-host URLs and control characters |
| **Upload safety** | `finfo` MIME allowlist; filenames never trusted; PHP execution blocked in `uploads/` and `storage/mock_images/` |
| **Secret exposure** | `.env` git-ignored, excluded from both archives, 403'd by `.htaccess` (with a `<FilesMatch>` backstop **outside** `mod_rewrite` so it works even without the module) |
| **Maintenance scripts** | Guarded by `require_maintenance_authorization()` **before** the `?run=1` gate. CLI always allowed; the browser path needs a logged-in super admin |

### 12.1 Secrets hygiene

`.env` holds live production credentials. It is never committed, never
deployed, and never served. **If any value is ever exposed, rotate it** —
redaction hides a leak, it does not undo one.

---

## 13. Tests

14 PHPUnit files, one `unit` suite. `tests/bootstrap.php` is deliberately
light: no database, no browser.

```powershell
composer install
composer test          # phpunit --testdox
composer lint
```

| File | Pins |
|---|---|
| `HelpersTest` | Escaping, password rules, upload rejection, `app_url` encoding |
| `OtpTest` | OTP format, PH mobile normalisation, masking |
| `BasuraAlertValidationTest` | Drop-off schedules, operation hours, collection windows |
| `AvatarTest` | Deterministic public_ids, version-segment URL caching, the audit log |
| `SecurityHardeningTest` | Open redirect, blocked paths, **`PROJECT_GUIDE.md` carries no live secrets**, `.env.example` carries no real values, maintenance guards |
| `NotificationQueueSchemaTest` | No phantom columns; **every** queue producer registers the flush; the flush cannot corrupt a sent response |
| `EncodingHygieneTest` | Strict UTF-8 everywhere, and the scanner is not vacuously green |
| `DropoffHoursFormatTest` | Client validator matches the server validator |
| `DropoffFormBannerTest` | Don't scold an untouched form |
| `DropoffListMobileTest` | Phone layout contract |
| `CalendarMonthNavTest` | Month navigation and legibility floor |
| `StackedCardPrimitiveTest` | The shared card CSS primitive and its adopters |
| `FaqEditorDialogTest` | The FAQ edit dialog uses the shared primitive |

Several suites assert **source-level contracts** by reading files rather than
executing code — because that is the contract being pinned.

---

## 14. Known issues

Honest list, so you are not caught out in a defense.

| # | Issue | Impact |
|---|---|---|
| 1 | `ba_notification_preferences.php` (the 18-checkbox matrix) was reachable only by typed URL | Fixed — linked from `profile.php` |
| 2 | The holiday provenance footer always rendered amber | Fixed — was reading an unset variable |
| 3 | **"My Concern" vs "My Reports"** — two different systems with similar names, both in the sidebar | Real IA ambiguity. Left as-is; renaming risks breaking a working flow. Be ready to explain: *My Concern* = E-Concern reports to a department, *My Reports* = BasuraAlert waste issues |
| 4 | `ba_collection_schedules` and `ba_dropoff_schedules` number days of the week differently (1–7 vs 0–6) | Handled by `+1` in the linkage code, but a schema trap |
| 5 | Cloudinary `CLOUDINARY_UPLOAD_PRESET` is defined but unused | Every upload is server-side signed. The preset would be a bearer token, so it is deliberately not used |
| 6 | Calendarific's free tier can be exhausted | The cache keeps it at ~30 calls/month, but the **current** quota is spent — the calendar honestly labels itself "Sample data, not live" until the monthly reset |
| 7 | PHPUnit has never run in this environment | No `vendor/` and no Composer on the dev machine. Run `composer install` before the defense |
| 8 | `includes/basuraalert.legacy.php` (123 KB) was dead code holding a fabricated forecast | **Deleted.** It was never required by anything |

### 14.1 A note on the forecast

The "2-Day Outlook" originally showed **generated** numbers: the current
temperature plus a fixed offset, the current condition copied to both days, and
a binary 80%/20% rain chance. It was labelled as a forecast.

It now calls OpenWeather's real `/forecast` endpoint, groups the 40 three-hour
slots by Philippine calendar day, and reports each day's real max, min, dominant
condition and rain probability. Minimums differ per day, which generated numbers
never do. If a panel member asks how you know it is real, the answer is that
both days can no longer share a minimum — the arithmetic version always did.

---

## 15. Quick reference

```
public/  26 pages  ── 12 nav items (9 BasuraAlert + 3 E-Concern)
admin/   23 pages  ── 25 JSON endpoints, 2 roles, 4 enforcement layers
api/     11 endpoints (citizen)
includes/BasuraAlert/  7 modules — External.php holds every API call
storage/cache/  API cache; 403 over HTTP; rebuilt on demand
database/  29 tables in the production snapshot
bin/  8 scripts — lint, migrate, build deploy zip, build APK
```

| Where to look for… | Go to |
|---|---|
| How an email is sent | `includes/mailer.php` |
| How an SMS is sent | `includes/sms.php` |
| How a photo is uploaded | `includes/BasuraAlert/External.php` → `ba_cloudinary_upload_file()` |
| How the weather card works | `public/ba_dashboard.php` + `External.php` |
| Why a status change needs a note | `admin/api/update_status.php` |
| How department scoping is enforced | `admin/api/concerns.php` (and 12 others) |
| How notifications reach a phone | `includes/BasuraAlert/Notifications.php` |
| How the drop-off pin is validated | `admin/api/ba_dropoffs_admin.php` |
| Every credential | root `PROJECT_GUIDE.md` (redacted) |
