---
title: Use the Halyard API
slug: use-the-halyard-api
status: published
position: 2
---

The Halyard API lets your own app create customers, send invoices and read payments. It's available on the Growth and Scale plans; see [[Change your plan]].

## Create an API key

1. Open **Settings → Developers → API keys**.
2. Click **New key**, name it after the app that will use it, and choose its permissions.
3. Copy the key. It's shown once.

Keys starting with `hk_test_` work with test data; `hk_live_` keys work with real data.

## Make your first request

```bash
curl https://api.halyard.app/v1/invoices \
  -H "Authorization: Bearer hk_test_4f2a…" \
  -d customer=cus_81Hd2 \
  -d "lines[0][description]=Consulting, March" \
  -d "lines[0][amount]=120000" \
  -d currency=eur
```

Amounts are in the smallest currency unit: `120000` is €1,200.00.

## Limits

| Plan | Requests per minute |
|---|---|
| Growth | 100 |
| Scale | 1,000 |

Over the limit, the API answers `429 Too Many Requests` with a `Retry-After` header.

> **Tip:** don't poll for payments. [[Set up webhooks]] and we'll tell your app the moment something changes.
