# API Teknisi KomporCare

Base path: `/api`. Semua endpoint memerlukan token Sanctum `Authorization: Bearer <token>` dan role `technician`. Profil teknisi harus berstatus `verified`. Respons validasi mengikuti format Laravel (`message` dan `errors`). Endpoint di bawah hanya memakai database Laravel yang sudah dikonfigurasi; Firebase tidak menjadi sumber data order.

## Dashboard

`GET /technician/dashboard`

```json
{
  "available": true,
  "today_count": 2,
  "completed_today": 1,
  "next_order": {
    "order_code": "KC-...",
    "status": "accepted",
    "scheduled_at": "2026-10-30T10:00:00+07:00",
    "completed_at": null,
    "service_finished_at": null,
    "service_name": "...",
    "est_duration_min": 60,
    "address": "...",
    "address_label": "...",
    "customer_name": "...",
    "customer_phone": "...",
    "total_price": 140000,
    "next_status": "on_the_way"
  }
}
```

`next_order` is `null` when the technician has no active task scheduled today. Dashboard counts and order details are queried from the configured Laravel database; no sample values are returned.

## Active tasks and history

`GET /technician/orders?view=active` returns `{"orders": [...]}` ordered by scheduled time ascending. `GET /technician/orders?view=history` returns completed/cancelled tasks ordered newest first. `view` accepts `active` or `history` only. Each order has the `next_order` fields above.

## Availability

`PUT /technician/availability`

Request: `{"available": true}`

Response: `{"available": true}`

## Reject an assigned task

`POST /technician/orders/{orderCode}/reject`

Request:

```json
{"reason_code":"unavailable","note":"Optional explanation"}
```

Allowed reason codes: `unavailable`, `schedule_conflict`, `system_error`, `duplicate_offer`, `customer_unavailable`. The technician must own the assigned order and its status must be `accepted`; duplicate rejection returns 409. The assignment is released and the matcher runs again, excluding technicians who already rejected this same order. Response includes `matched`, replacement `technician` (null if none), and `rejection_penalty_applied`.

## Progress status

`POST /technician/orders/{orderCode}/status`

Request: `{"status":"on_the_way"}`, then `{"status":"in_progress"}`, then `{"status":"service_finished"}`. Only these sequential transitions are accepted, for an order assigned to the caller. `service_finished` records `orders.service_finished_at` and history while preserving `orders.status = in_progress`, matching the existing customer payment/tracking logic that permits payment after service completion. A payment must still complete the existing customer flow before the order reaches `completed`.

## Rejection-priority rule

`TECHNICIAN_REJECTION_PENALTY` configures the deduction on the existing matcher score (default 15 points per eligible rejected order; score remains between 0 and 100). A rejection is persisted once per `(order_id, technician_id)` in `technician_order_rejections`; its unique constraint prevents duplicate penalty events. `system_error`, `duplicate_offer`, and `customer_unavailable` are exempt by default and do not reduce future ranking. The technician who rejected an order is excluded from that order's rematch regardless of whether the rejection was exempt. Adjust the environment value only after product approval.

## Setup and verification limits

The checked-in `.env.example` selects SQLite, and Laravel's configured default is SQLite. The main order flow, however, calls MySQL stored procedures and uses MySQL `FIELD()` in customer order listing. No core schema migrations, SQL dump, stored procedure definitions, local `.env`, or SQLite file are present in this repository. Therefore the database engine/schema must be reconciled with the database that owns the existing project data before migrations or live API integration. Do not run these migrations against a production database until reviewed and backed up.

No Firebase dependency/configuration was found. Firebase's intended purpose is not established by this repository. Keep Laravel's configured relational database as the source of truth; request the Firebase project/config and intended use before adding Firebase calls.

The tests in `tests/Feature/TechnicianApiTest.php` verify unauthenticated access is rejected without requiring a database. Matcher penalty tests verify the configured score arithmetic. Full data-backed endpoint verification requires the authoritative schema, database connection, and application key.
