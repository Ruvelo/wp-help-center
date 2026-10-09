---
title: Invoice emails are not arriving
slug: invoice-emails-are-not-arriving
status: published
position: 1
---

If a customer says they never got an invoice, it's almost always a spam filter or a typo in their address.

## Check the delivery log

Open the invoice and look at **Activity**. Each email shows one of:

| Status | Meaning | What to do |
|---|---|---|
| Delivered | Their mail server accepted it | Ask them to check spam and any "Promotions" tab |
| Bounced | The address doesn't exist | Fix the address on the customer and click **Resend** |
| Blocked | Their server refused it | Ask their IT team to allow `halyard.app` |
| Deferred | Their server asked us to try later | Nothing; we retry for 24 hours |

## Send from your own domain

Invoices sent from your own domain are much less likely to be filtered.

1. Open **Settings → Email → Sending domain**.
2. Add the three DNS records we show you.
3. Click **Verify**. DNS changes can take up to an hour.

> **Tip:** you can always copy the invoice link with **Share → Copy link** and send it yourself.

Customers who need a copy of an old invoice can download it from that link; see [[Download an invoice]].
