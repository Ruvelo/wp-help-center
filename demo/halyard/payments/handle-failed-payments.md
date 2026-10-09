---
title: Handle failed payments
slug: handle-failed-payments
status: published
position: 4
---

Card payments fail when a card has expired, has no funds, or the bank blocks the payment. Halyard retries automatically, and tells you and your customer.

## Automatic retries

For subscriptions and saved cards, we retry a failed payment on this schedule:

1. 1 day after the failure
2. 3 days after
3. 7 days after

After the last retry the invoice stays **Overdue** and we stop trying.

## What your customer sees

They get an email after each failure, with a link to update their card. Once they do, we try again straight away.

## What you can do

- Open the invoice to see the reason the bank gave, like `insufficient_funds` or `expired_card`.
- Click **Retry now** after the customer tells you they've fixed it.
- Turn off retries for one customer under **Customer → Payment settings**.

> **Heads up:** if a customer says they were charged even though the payment shows as failed, see [[A customer was charged twice]].

Get a webhook for every failure to act on it in your own app, see [[Set up webhooks]].
