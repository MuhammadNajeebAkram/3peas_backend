# Phase 3A — Admin Teacher Subscription APIs

## Scope and integration

This document describes the Laravel APIs for the Admin Panel's teacher subscription management module. Routes use the existing `/api/admin/auth` namespace, existing admin JWT/cookie authentication middleware, and the existing role-permission system. The Next.js Admin Panel consumes these APIs in Phase 3B. Student subscriptions, payments, settlements, teacher collection codes, and teacher-facing UI are outside this phase.

## Permissions

The idempotent `TeacherSubscriptionAdminPermissionsSeeder` creates these permissions without granting them to any role:

| Permission | Access |
| --- | --- |
| `teacher-subscription-plans.view` | List and inspect plans |
| `teacher-subscription-plans.manage` | Create/update plans, set plan status and default |
| `teacher-subscriptions.view` | List and inspect teacher subscriptions |
| `teacher-subscriptions.manage` | Assign, replace, extend, suspend, reactivate, cancel |
| `teacher-subscriptions.history.view` | Read paginated subscription history |

An administrator with no matching permission receives `403`; unauthenticated requests receive `401`. Super-admin access follows the existing `CheckPermission` behavior. Permission grants are administered through existing role management.

## API endpoints

All paths below are relative to `/api/admin/auth`.

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/teacher-subscription-plans` | `teacher-subscription-plans.view` | Filtered/paginated plans and active teacher counts |
| GET | `/teacher-subscription-plans/{id}` | `teacher-subscription-plans.view` | Plan configuration and latest plan audit entries |
| POST | `/teacher-subscription-plans` | `teacher-subscription-plans.manage` | Create a plan and its eight feature records transactionally |
| PUT | `/teacher-subscription-plans/{id}` | `teacher-subscription-plans.manage` | Change plan configuration and/or feature flags |
| PATCH | `/teacher-subscription-plans/{id}/status` | `teacher-subscription-plans.manage` | Activate/deactivate a plan |
| PATCH | `/teacher-subscription-plans/{id}/default` | `teacher-subscription-plans.manage` | Move the active default plan |
| GET | `/teacher-subscriptions` | `teacher-subscriptions.view` | Search/filter/paginate subscription records |
| GET | `/teacher-subscriptions/{teacherProfileId}` | `teacher-subscriptions.view` | Teacher, current entitlements, class usage, recent history |
| GET | `/teacher-subscriptions/{teacherProfileId}/history` | `teacher-subscriptions.history.view` | Read-only chronological history (newest first) |
| POST | `/teacher-subscriptions/{teacherProfileId}/assign` | `teacher-subscriptions.manage` | Assign a plan or replace the current one |
| POST | `/teacher-subscriptions/{subscriptionId}/extend` | `teacher-subscriptions.manage` | Extend a valid, active, non-lifetime subscription |
| POST | `/teacher-subscriptions/{subscriptionId}/suspend` | `teacher-subscriptions.manage` | Suspend and remove entitlements |
| POST | `/teacher-subscriptions/{subscriptionId}/reactivate` | `teacher-subscriptions.manage` | Reactivate a valid suspended subscription |
| POST | `/teacher-subscriptions/{subscriptionId}/cancel` | `teacher-subscriptions.manage` | Cancel and remove entitlements |

Plan listing accepts `search` (or `q`), `is_active`, `is_free`, `per_page` (1–100), `sort_by`, and `sort_direction`. Subscription listing accepts `search` (or `q`), `plan_id`, `status` (`active`, `scheduled`, `expired`, `suspended`, `cancelled`), `assignment_type`, `expiring_within_days` (1–365), `per_page`, and sorting. Results do not contain passwords, tokens, payment data, or settlement data.

## Request examples

Create a plan (the reason is required and retained in plan audit history):

```json
{
  "code": "pro-monthly",
  "name": "Pro Monthly",
  "description": "Expanded teacher access",
  "price": 1200,
  "currency": "PKR",
  "billing_interval": "monthly",
  "max_active_classes": 10,
  "is_free": false,
  "is_active": true,
  "features": {
    "classes.manage": true,
    "students.enroll": true,
    "question_bank.access": true,
    "tests.create": true,
    "tests.assign": true,
    "reports.basic": true,
    "announcements.manage": true,
    "messaging.use": true
  },
  "reason": "Initial admin plan setup"
}
```

Create returns `201` and a `data` plan object; list/show/update return `200` with `success` and `data`. Feature output is a map of all supported feature keys to booleans. Plan update does not accept a new code or `is_active`; status uses the dedicated endpoint. Custom billing intervals require `duration_days`; non-custom intervals clear duration. Free plans must cost zero and paid plans must cost more than zero. Class limits are non-negative integers or `null` for unlimited. Feature keys outside the supported eight-key set are rejected.

Assign a plan:

```json
{
  "plan_id": 2,
  "starts_at": "2026-11-10T00:00:00",
  "admin_note": "Approved promotional access"
}
```

The subscription duration is derived from the selected plan configuration (monthly/yearly/custom/lifetime); request-supplied expiry or status is not accepted. The response contains `data.id`, teacher/profile identifiers, plan, effective and recorded status, dates, assignment type, class usage/capacity, and feature booleans.

Extend, suspend, reactivate, and cancel require an administrative note. Extension body:

```json
{ "additional_days": 30, "admin_note": "Approved extension" }
```

Status-change body:

```json
{ "admin_note": "Reason for this subscription change" }
```

## Plan behavior and auditing

Plan creation and edits run in transactions. Creation writes one row for each supported feature; omitted feature values default to disabled. Updates preserve unspecified flags. The unique code is immutable. At most one active default plan is enforced by a database unique generated-column guard, and changing defaults clears the old value and sets the new one in one transaction. A default plan must be active; an active default cannot be deactivated until another active default is selected. Deactivated plans cannot be assigned, while current subscriptions keep the plan's existing entitlements. Referenced plans are protected from deletion by restrictive foreign keys.

Plan configuration changes affect feature and limit calculations for current subscribers because entitlement checks read the current plan and feature rows. They do not create subscriptions or alter subscription history.

Each plan create/update/status/default change adds a before/after snapshot to `teacher_subscription_plan_history`. The existing `LogAdminActivity` middleware records successful Admin mutations with actor, action, controller, route/response target, IP, user agent, and timestamp in `admin_activity_logs` when that existing table is installed. Subscription assignments and lifecycle operations also write durable records to `teacher_subscription_history` with previous/new plan, status, expiry, administrator, reason, and metadata.

## Assignment and replacement lifecycle

Only an active, approved teacher profile whose linked web account has the teacher role and active account status can receive a manual assignment. The eligibility check is repeated under the locked teacher-profile row in the subscription service. Only active plans can be assigned. The controller uses the existing `TeacherSubscriptionService` and records `assignment_type=manual_admin` and the admin actor.

If a different subscription is currently active, the service cancels that record with a replacement event and creates the new record in the same transaction. The old row remains as history; the new record's event includes both plan IDs and the previous status/expiry. Price comparisons label a replacement as upgrade, downgrade, or replacement for audit only; there is no proration, refund, payment, or student subscription mutation. Assigning the same current plan is rejected with `409`.

Extension only accepts an active, started, unexpired, non-lifetime subscription and adds days to its current expiry. Suspension and cancellation preserve dates and history and remove entitlements. Reactivation requires the prior status to be suspended, an active/approved teacher, and an unexpired subscription; expiry is not extended. Invalid lifecycle transitions return `409`; an ineligible teacher returns `422`. Teacher class records are not deleted or modified by subscription operations.

## History and response security

The history endpoint is read-only and paginated (`per_page` 1–100), ordered by event timestamp then ID descending. It returns event type, previous/new plans, statuses, expiry values, actor, reason, metadata, and timestamp. The `teacher_subscription_history` rows and `teacher_subscription_plan_history` snapshots have no Admin edit/delete route. Foreign keys prevent deleting referenced plan and subscription history records.

Teacher identity is taken from the route and loaded from the database; the assignment payload cannot select a teacher or status. Route IDs are numeric, missing records return `404`, and validated fields are explicitly mapped to model changes. Existing Admin authentication is reused; this module adds no auth or payment system.

## Phase 3B integration

The existing Next.js admin API client uses credentialed requests and the current admin auth cookie. Phase 3B should call the routes above, map `success/data/pagination`, expose controls only for the corresponding permissions, and still handle backend `401`, `403`, `404`, `409`, and `422` responses. Do not treat UI permission visibility as authorization. Do not add payment gateway behavior or change teacher collection/settlement endpoints in Phase 3B.

## Verification status

The Phase 3A feature tests are in `tests/Feature/TeacherSubscriptionAdminTest.php`; Phase 1 and Phase 2 suites are `TeacherClassTest.php`, `TeacherBackendTest.php`, and `TeacherSubscriptionTest.php`. PHP CLI 8.3.25 uses `C:\php-8.3\php.ini`; the matching installed `php_sqlite3.dll` and `php_pdo_sqlite.dll` are now enabled. The focused Phase 3A suite passed (8 tests, 80 assertions). Teacher class, subscription, backend, profile, payment, and Google-login regressions passed (86 tests, 534 assertions). The established fixtures use an in-memory SQLite connection; a temporary `LARAVEL_STORAGE_PATH` was used for tests because the sandbox blocks writes to the repository's default `storage` directory. The separate local MySQL additive plan-history migration remains applied in `3peas_db`, batch 128. The seeded Free Plan and existing subscription were rechecked after tests; test fixtures did not connect to or modify that MySQL database.
