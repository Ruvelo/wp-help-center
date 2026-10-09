---
title: Webhook deliveries are failing
slug: webhook-deliveries-are-failing
status: published
position: 3
---

When your endpoint doesn't answer in time, or answers with an error, Halyard retries the event and eventually turns the endpoint off.

## See what went wrong

Open **Settings → Developers → Webhooks**, click the endpoint, then **Recent deliveries**. Each attempt shows the response code and the first 1,000 characters of the body.

| You see | Likely cause |
|---|---|
| `timeout` | Your endpoint took more than 10 seconds |
| `401` or `403` | Your signature check or your firewall rejected us |
| `404` | The URL changed |
| `5xx` | An error in your app; check its logs |

## Retries

We retry failed deliveries for 3 days, with longer gaps each time. After 3 days of failures the endpoint is turned off and the owner gets an email.

## Fix it and catch up

1. Fix the cause, then click **Turn on** if the endpoint was turned off.
2. Click **Resend** on any failed delivery, or **Resend all failed** to replay them in order.

> **Heads up:** events can arrive more than once and out of order. Use the event `id` to skip duplicates, and keep [[Set up webhooks#Check the signature|checking the signature]] on every request.
