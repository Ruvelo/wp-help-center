---
title: Set up webhooks
slug: set-up-webhooks
status: published
position: 3
---

Webhooks tell your app when something happens in Halyard, like an invoice being paid, so you don't have to keep asking the [[Use the Halyard API|API]].

## Add an endpoint

1. Open **Settings → Developers → Webhooks** and click **Add endpoint**.
2. Enter the HTTPS URL that should receive events.
3. Pick the events you want, or **All events**.
4. Copy the signing secret.

## Events

| Event | Sent when |
|---|---|
| `invoice.sent` | An invoice is emailed to a customer |
| `invoice.paid` | An invoice is fully paid |
| `invoice.overdue` | An invoice passes its due date unpaid |
| `payment.failed` | A card payment fails, see [[Handle failed payments]] |
| `payout.paid` | A payout reaches your bank |

## Check the signature

Every request has a `Halyard-Signature` header. Compute an HMAC-SHA256 of the raw body with your signing secret and compare:

```php
$expected = hash_hmac('sha256', $request->getContent(), config('services.halyard.webhook_secret'));

abort_unless(hash_equals($expected, $request->header('Halyard-Signature')), 401);
```

> **Heads up:** answer with a `2xx` within 10 seconds, then do slow work in a queue. Slower answers count as failures, see [[Webhook deliveries are failing]].
