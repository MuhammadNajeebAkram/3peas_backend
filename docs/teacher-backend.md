# Teacher accounts and collections backend

Teacher profiles and student profiles share `web_users` authentication, but are
separate records. `web_users.status=active` permits login; teacher profile status
controls collection approval. Pending/rejected/suspended teachers can log in to
view their profile and history. Only active teachers with a collection code can
confirm student payments. Blocked web accounts cannot use teacher endpoints.

## Signup and login

Public routes under `/web_api/auth`:

| Method | Path | Body |
| --- | --- | --- |
| POST | `/teacher/register` | `name`, `email`, `phone`, `password`, `password_confirmation`, optional `city_id`, `institute_id` |
| POST | `/teacher/google-login` | `idToken` from Google |
| POST | `/lms-login` | `login` (email or phone), `password` |

Teacher registration fixes the role on the server, regardless of client role input.
Existing `/register_user` and `/google-login` also accept `role: teacher`; public
roles are restricted to student/teacher. A student Google account cannot be silently
converted to teacher. Existing teachers remain teachers through the student Google
entry point. Google ID tokens use the existing configured Google client ID and
verified email requirement; Google signup still requires completing a contact phone.

Both signup methods create a pending teacher profile and generate a stable
`teacher_code`; `collection_code` starts null. No new student profile is created
for teachers. Responses use the existing LMS JWT cookie/token format and include
`user.teacher_profile` and `profile_complete`. Approval is independent of profile
completion. Google tests mock identity verification; no real Google account is needed
to run the suite.

Authenticated teacher routes under `/web_api/auth`:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/teacher/profile` | Profile plus city/institute |
| POST | `/teacher/profile` | Update name, phone, optional city/institute IDs |
| GET | `/teacher/dashboard` | Profile, `can_collect`, collection/settlement totals |
| GET | `/teacher/payment-requests` | Own student requests; optional status filter |
| GET | `/teacher/collections` | Confirmed collections with settled and pending allocation totals |
| GET | `/teacher/settlements` | Own settlements; optional status filter |
| POST | `/teacher/settlements` | Submit remittance for administrator review |
| GET | `/teacher/settlements/{id}/proof` | Private proof download |

`/complete-profile` dispatches teachers to their profile update flow. Teachers cannot
write approval status, either code, audit fields or internal administrator notes.
The dashboard reports `currency: PKR`, `collected`, `settled`, `outstanding`,
`pending_settlement`, `available_to_settle`, and payment-request counts. Amounts
are decimal strings. Suspended teachers may settle existing collections.

## Administrator review

Routes under `/api/admin/auth` use admin JWT authentication and existing activity logging:

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/teachers` | `teachers.view` |
| GET | `/teachers/{id}` | `teachers.view` |
| POST | `/teachers/{id}/status` | `teachers.approve` |
| POST | `/teachers/{id}/collection-code` | `teachers.approve` |
| GET | `/teachers/{id}/collections` | `teacher-settlements.view` |
| GET | `/teacher-settlements` | `teacher-settlements.view` |
| GET | `/teacher-settlements/{id}/proof` | `teacher-settlements.view` |
| POST | `/teacher-settlements/{id}/confirm` | `teacher-settlements.review` |
| POST | `/teacher-settlements/{id}/reject` | `teacher-settlements.review` |

Teacher lists accept `status` and `search` (name/email). Settlement lists accept
`status` and `teacher_profile_id`. Lists paginate at 20 rows. Teacher detail includes
the internal admin note, totals and immutable status/code event history.

Activate with `{"status":"active"}` to generate a collection code automatically,
or include `collection_code`. Codes are 6–32 uppercase letters, digits or hyphens.
Activation requires an active teacher web account and contact phone. Reject or
suspend with `status` and a required `admin_note`. Suspension immediately disables
new collections, including approval of earlier pending requests.

POST `/collection-code` with `{}` rotates the code, or supply `collection_code`.
Used codes cannot be reassigned, including historical codes. Changing a code does
not move existing requests; they retain their original teacher and code snapshot.
The stable `teacher_code` remains separate and unchanged.

## Settlement submission

Cash example (JSON); use multipart for an optional `proof_file` (JPEG/PNG/PDF, 2 MB):

```json
{
  "submission_key": "02c802b2-0c29-49a1-b4a2-4fa5860667cf",
  "payment_method": "cash",
  "paid_at": "2026-09-17",
  "notes": "Cash handed to the office",
  "allocations": [
    {"payment_request_id": 25, "amount": "300.00"},
    {"payment_request_id": 26, "amount": "500.00"}
  ]
}
```

Methods: `cash`, `bank`, `jazzcash`. Bank/JazzCash require `payment_account_id` and
`transaction_reference`; cash prohibits those fields. Bank maps to the existing
`bank_deposit` account method. Destination accounts must be active and match the
method. `paid_at` cannot be future-dated. Amount is calculated from 1–100 distinct
allocations; each has at most two decimal places and must be positive.

Allocations must reference this teacher's confirmed collections and cannot exceed
their balance after confirmed and pending allocations. They support partial and
multi-student remittances. Use a fresh UUID submission key for each new remittance;
retrying the same key returns the original record, while changing its financial
details returns 409. A transfer reference cannot be reused for the same destination.
Rejected transfers retain that reference for audit; submit a new actual transfer
with a new reference, or correct the operational issue before submission.

Pending settlements reserve allocations but do not reduce outstanding cash.
Administrators confirm with `{"received_funds":true}` only after receiving money,
or reject with `rejection_reason`. Repeated confirmation is safe. Rejection releases
reserved amounts. Confirmed/rejected records cannot change to another status and
cannot be edited/deleted through these APIs. Proof paths are hidden; `has_proof`
indicates whether an owner/admin-authenticated download is available.

Settlement submission/confirmation locks the teacher row and runs transactionally.
Currency is PKR, matching the local collection workflow. This release provides no
refund/reversal interface; do not edit financial records directly to correct them.
Student receipts and subscription activation are covered in [teacher-payments.md](teacher-payments.md).

## Deployment and verification

```sh
php artisan migrate --path=database/migrations/2026_09_17_000001_create_teacher_profiles_table.php
php artisan migrate --path=database/migrations/2026_09_17_000002_add_teacher_payments.php
php artisan migrate --path=database/migrations/2026_09_17_000003_create_teacher_settlements.php
php artisan db:seed --class=TeacherPermissionsSeeder
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter='TeacherBackendTest|TeacherGoogleLoginTest|TeacherPaymentTest|TeacherProfileTest'
```

The settlement migration creates pending profiles for existing teacher accounts
missing one and preserves existing student history. The permission seeder grants
Super Admin the new permissions without replacing other grants. Assign other
administrator roles explicitly. Proofs use private local storage; include them in
backups and use shared private storage for deployments with multiple app instances.

Frontend pages are outside this backend repository. Classroom membership,
assignments and tests are excluded from this work.
