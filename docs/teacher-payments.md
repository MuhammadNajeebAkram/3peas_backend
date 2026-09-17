# Teacher payments

Routes use LMS authentication under `/web_api/auth`. An administrator must activate
the teacher profile and assign its unique `collection_code`. The linked web user
must have role `teacher` and status `active`. See [teacher-backend.md](teacher-backend.md)
for signup, administrator approval, dashboards and settlement accounting.

`GET /payments/methods` supplies the three frontend payment choices. Existing
`GET /accounts/get-bank-accounts` supplies active bank/JazzCash destinations.

1. `POST /payments/teacher/lookup` with `collection_code` returns the teacher name.
2. `POST /save-payment-request` with:

```json
{"payment_method":"teacher","offered_program_id":1,"collection_code":"COL-EXAMPLE"}
```

The amount comes from the program's offered class: `discount_price` when present,
otherwise `price` (legacy `Price` supported). Client amounts cannot override it.
Free, inactive, expired or unpriced programs are rejected. No bank account or proof
upload is required. Duplicate pending requests and active subscriptions return 409.
Teacher identity and collection code are captured at submission.

3. `GET /teacher/payment-requests?status=pending` lists only that teacher's requests,
with student names, program titles and amounts, paginated at 20 per page.
4. `POST /teacher/payment-requests/{id}/approve` with
`{"received_full_payment":true}` confirms collection and activates the subscription
atomically. Repeated approval returns the same payment and receipt. Access expires
at the class session end, or has no expiry when none is configured.
5. `POST /teacher/payment-requests/{id}/reject` with `rejection_reason` rejects a
pending request. Approved payments cannot be rejected through this endpoint.
6. `GET /payments/teacher/requests` returns the student's own teacher payments,
including status and receipt number.

Approval records the teacher in `confirmed_by_web_user_id`, with a timestamp and
unique receipt. `approved_by` remains reserved for administrators. Existing admin
approval cannot approve teacher requests. `teacher_code` does not authorize collection.
Confirmation does not represent settlement of money to the business.

Bank/JazzCash submissions continue through their payment account flow; their
`payment_method` defaults to `account`, with the account identifying the channel.
Apply `2026_09_17_000002_add_teacher_payments.php` after teacher profiles.
