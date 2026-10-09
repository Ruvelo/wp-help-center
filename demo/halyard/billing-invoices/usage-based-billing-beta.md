---
title: Usage-based billing (beta)
slug: usage-based-billing-beta
status: draft
position: 5
---

Usage-based billing lets you charge for what customers use, like API calls or seats, instead of a fixed price.

## Join the beta

Ask your account manager, or reply to the beta invitation email.

## Report usage

Send usage with the [[Use the Halyard API|API]]:

```bash
curl https://api.halyard.app/v1/usage \
  -H "Authorization: Bearer hk_test_4f2a…" \
  -d subscription=sub_9Kd1 \
  -d quantity=1200
```

> **Note:** usage is billed at the end of each period, on the next invoice.
