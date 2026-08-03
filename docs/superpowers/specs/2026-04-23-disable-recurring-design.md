# Design: Disable Recurring Payments (per-gateway toggle)

**Date:** 2026-04-23
**Module version target:** next release (1.7.2 or 1.8.0 — left to author)
**Status:** Approved by user, awaiting implementation

## Goal

Give merchants a per-gateway option to prevent the CHIP module from saving cards as recurring tokens, and to reject capture attempts that use a previously-saved token.

## Behavior

When the new `disableRecurring` setting is `on` for a gateway:

1. **New payments** — `ChipAction::complete_payment()` skips the `RemoteCreditCard::factoryPayMethod(...)` save block, even when `$payment['is_recurring_token']` is true. No new `payMethod` row is created.
2. **Capture with stored token** — `ChipGateway::capture()` returns `['status' => 'declined', 'declinereason' => 'Recurring payments are disabled for this gateway.']` before any CHIP API call, **only** when the request would have used a stored token (`$params['gatewayid']` is set) and `disableRecurring` is `on`. The guard is intentionally conditional so a hypothetical future WHMCS call to `capture()` without a stored token (e.g. for a first-time card payment) would not be silently broken.
3. **Existing tokens** — remain in the WHMCS database and remain chargeable **only** if the merchant flips the toggle back off. They are not auto-deleted, and the existing `store_remote` 'delete' action is the merchant's manual cleanup path.

When the setting is off (default) or unset, behavior is unchanged.

## Config surface

New entry in the `get_config_params()` array (`modules/gateways/chip/helpers.php`):

```
'disableRecurring' => [
    'FriendlyName' => 'Disable Recurring Payments',
    'Type'         => 'yesno',
    'Default'      => '',
    'Description'  => 'Tick to prevent saving cards and reject charges using previously saved tokens.',
],
```

Unconditional (does not require `secretKey`/`brandId` to be set first), so the merchant sees it on first install.

Setting name and meaning mirror the existing `forceTokenization` yesno pattern.

## Implementation sites

### 1. `modules/gateways/chip/helpers.php` — config schema

Add the entry above to the `$config_params` array returned by `ChipHelpers::get_config_params()`. No new method.

### 2. `modules/gateways/chip/action.php` — save-site gate

Inside `ChipAction::complete_payment()` (within the `Capsule::transaction` closure), change:

```php
if ($payment['is_recurring_token']) {
```

to:

```php
if ($payment['is_recurring_token'] && ($params['disableRecurring'] ?? '') !== 'on') {
```

This is the only call site that creates a `RemoteCreditCard` pay method from a fresh payment.

### 3. `modules/gateways/chip/gateway.php` — capture-time guard

Inside `ChipGateway::capture()`, add immediately after the `convertto` currency-conversion block and before the `ChipAPI::get_instance(...)` call:

```php
if (($params['disableRecurring'] ?? '') === 'on') {
    \logActivity('CHIP Capture Rejected: Recurring payments disabled for gateway ' . $gateway_name);

    return [
        'status' => 'declined',
        'declinereason' => 'Recurring payments are disabled for this gateway.',
    ];
}
```

This rejects the capture **before** any `create_payment` / `charge_payment` call and before `GET_LOCK`, so it has no side effects.

## Files modified (3)

- `modules/gateways/chip/helpers.php`
- `modules/gateways/chip/action.php`
- `modules/gateways/chip/gateway.php`

## Files not modified

All seven gateway wrappers (`chip.php`, `chip_cards.php`, `chip_fpx.php`, `chip_fpxb2b1.php`, `chip_ewallets.php`, `chip_dnqr.php`, `chip_crypto_coin.php`) and all seven callback entry points delegate to `ChipGateway::capture()` / `ChipAction::complete_payment()`. They pick up the new behavior automatically because `disableRecurring` is part of the `$params` array WHMCS builds from `tblpaymentgateways`.

`chip/api.php`, `chip/exceptions.php`, `chip/redirect.php`, and per-gateway `whmcs.json` files are untouched.

## Error handling

- **API outage at capture** — unchanged; the new guard returns before the existing `try/catch` so real API failures still surface.
- **Toggle flipped mid-call** — impossible: WHMCS resolves `$params` once per call, so behavior is consistent within a single capture attempt.
- **Toggle flipped between redirect and webhook** — both paths consult `$params['disableRecurring']`, so a webhook that fires after the toggle was enabled will not save the card.
- **Empty / unset value** — `?? '' !== 'on'` treats anything except the literal `'on'` as "recurring allowed". Matches the existing yesno convention (`forceTokenization`, `paymentWhitelist`, `dueStrict`, `updateClientInfo`).

## Testing (manual, no test suite in repo)

1. **Toggle off (default) → recurring works.** Pay with a new card; verify a `tblpaymethods` row is created and the card appears under the client's saved pay methods.
2. **Toggle on → no save on new payment.** Pay with a new card; verify no new `tblpaymethods` row is created and `$payment['is_recurring_token']` was true.
3. **Toggle on → capture using saved token is rejected.** With an existing token in place (from step 1), flip the toggle on and run an admin manual-capture or let a renewal cron run. Verify the transaction log shows the `declinereason` and the invoice stays unpaid.
4. **Toggle on then off → previously-saved token is still chargeable.** Existing tokens are not deleted.
5. **Lint passes:** `composer lint` — must complete with no diff.

## Out of scope

- Auto-deletion of existing tokens when the toggle is enabled (explicitly rejected by user).
- Suppressing the credit-card confirmation email when the card is not saved (the email path uses `$_GET['capturecallback']` and `$payment['recurring_token']`, not `$payment['is_recurring_token']`, and the user chose the single-gate scope).
- A global kill switch across all CHIP gateways (explicitly rejected — per-gateway is sufficient).
- Bumping `CHIP_MODULE_VERSION` or editing `changelog.txt` — left to the author at PR time.
