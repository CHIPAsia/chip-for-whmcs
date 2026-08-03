# Spec: Whitelist UX collapse + drop Update Client Info

**Date**: 2026-08-03
**Branch**: `feature/disable-recurring` (PR #14)
**Author**: Wan Zulkarnain

## Problem

The chip-for-whmcs admin config currently exposes the raw CHIP API
`payment_method` values as individual tickboxes. For the card family
and the DuitNow QR / Shopee Pay groups, this is repetitive and
confusing for merchants, who don't care about the internal naming.

Specifically:

1. The card family has three network values (`visa`, `mastercard`,
   `maestro`) that almost always go together — a merchant who wants
   "cards" wants all three.
2. DuitNow QR has two interchangeable values (`dnqr`, `duitnow_qr`).
   A merchant who wants DuitNow QR doesn't care which code is
   emitted; only CHIP cares, based on which the merchant's brand
   supports.
3. Shopee Pay has two values (`razer_shopeepay`, `shopee_pay`).
   `shopee_pay` is the preferred value but is whitelist-only; if
   the merchant's brand doesn't support it, the only fallback is
   `razer_shopeepay`.
4. The "Update client information" admin toggle is dead weight
   after the PR #14 / commit `b7baac8` refactor removed the only
   code path that read it.

## Goals

- One tickbox per *user-facing* payment category: `Card`, `DuitNow
  QR`, `Shopee Pay`, plus the existing per-method ticks for FPX,
  individual e-wallets, MPGS Apple/Google Pay, and Crypto.
- Emit the correct raw `payment_method` value(s) to CHIP's
  `payment_method_whitelist` based on the merchant's brand
  configuration, with documented preferences.
- Drop the `updateClientInfo` admin toggle and confirm no remaining
  code references it.
- Keep the schema build path (`helpers.php::get_config_params`) and
  the consume path (`gateway.php::redirect()` and
  `helpers.php::get_whitelisted_methods`) loosely coupled — a single
  source of truth for alias groups, used by both sides.

## Non-goals

- No DB migration. Legacy `tblpaymentgateways` rows with the old
  per-method keys (`payment_method_whitelist__dnqr`, `__visa`, etc)
  become silent orphans — present in the table, not rendered in the
  UI, not consumed by emit code. They are harmless.
- No new admin UI widgets, layout changes, or copy beyond what's
  needed to render the three new tickboxes.
- No change to the `payment_methods` / `payment_recurring_methods`
  API calls in `api.php`. Same calls, same shape.
- No change to PR #14 (`disableRecurring`).

## Canonical CHIP values (verified)

Source: `https://docs.chip-in.asia/openapi/chip-collect.yaml` (lines
1953-1990 for `PaymentMethod` response schema; lines 2920-2950 and
3940-3970 for the two `payment_method_whitelist` request enums).

| Group | Values valid in `payment_method_whitelist` |
|---|---|
| Card network | `maestro`, `mastercard`, `mpgs_apple_pay`, `mpgs_google_pay`, `visa` |
| DuitNow QR | `dnqr`, `duitnow_qr` |
| Shopee | `razer_shopeepay`, `shopee_pay` (whitelist-only — never returned in `PaymentMethod` response schema) |
| FPX | `fpx`, `fpx_b2b1` |
| Razer e-wallets | `razer_atome`, `razer_grabpay`, `razer_maybankqr`, `razer_tng` |
| Crypto | `crypto_coin` |

## Design

### 1. New alias-group registry

Add a single private helper to `ChipHelpers`:

```php
private static function whitelist_alias_groups(): array
{
    return [
        'card' => [
            'label' => 'Card',
            'members' => ['visa', 'mastercard', 'maestro'],
            'expand' => 'static',          // always emit members verbatim
        ],
        'duitnow_qr' => [
            'label' => 'DuitNow QR',
            'members' => ['dnqr', 'duitnow_qr'],
            'expand' => 'preferred',      // see resolution rules below
        ],
        'shopee_pay' => [
            'label' => 'Shopee Pay',
            'members' => ['razer_shopeepay', 'shopee_pay'],
            'expand' => 'preferred',
        ],
    ];
}
```

This is the single source of truth. Both the schema builder and the
emit step read from it.

### 2. Schema builder changes (`helpers.php::get_config_params`)

- For each `apm` returned by `/payment_methods/`, look up whether it
  belongs to an alias group's `members` list.
- If yes, render a single tickbox per group (not one per member).
  The tickbox's storage key is `payment_method_whitelist__{group_key}`.
- If no, render the per-method tickbox as today.
- Default tick for a group: ON if any of its members would have
  been default-on for the active gateway (e.g. `chip_cards` → Card
  is on; `chip_dnqr` → DuitNow QR is on; `chip_ewallets` → Shopee
  Pay is on).
- Stash the merchant's available payment methods in a hidden
  non-UI config field, `_availablePaymentMethods`, as a
  comma-separated string. Used at emit time. Refreshed every time
  the admin renders the form.

### 3. Emit-time resolution (`gateway.php::redirect` and `helpers.php::get_whitelisted_methods`)

Add a new helper:

```php
public static function expand_whitelist_aliases(
    array $tickedKeys,
    array $merchantAvailable
): array
{
    // For each ticked key, return the corresponding raw payment_method
    // value(s). Dedup the result.
}
```

Rules:

- **Static group (`card`):** emit each of its `members` if (and
  only if) that member appears in `$merchantAvailable`. Order
  preserved. Rationale: a merchant's brand may not support all
  three networks; sending a whitelist of unsupported methods
  causes a CHIP API error per the spec note on line ~1294
  ("no payment methods available").
- **Preferred group (`duitnow_qr`):**
  - If `dnqr` is in `$merchantAvailable` AND `duitnow_qr` is also
    in `$merchantAvailable` → emit `['dnqr']` (preferred per user).
  - If only `dnqr` is available → emit `['dnqr']`.
  - If only `duitnow_qr` is available → emit `['duitnow_qr']`.
  - If neither is available → emit `[]` (will be caught by the
    existing `link()` config-error path).
- **Preferred group (`shopee_pay`):**
  - If `shopee_pay` is in `$merchantAvailable` → emit `['shopee_pay']`
    (preferred per user, even if `razer_shopeepay` is also available).
  - Else if `razer_shopeepay` is available → emit `['razer_shopeepay']`.
  - Else emit `[]`.
- **Raw apm keys (`fpx`, `mpgs_apple_pay`, `razer_atome`, etc):**
  pass through unchanged.

### 4. `redirect()` and `get_whitelisted_methods()` integration

- `redirect()` (gateway.php ~line 479) replaces its inline
  `preg_grep` loop with a call to
  `ChipHelpers::expand_whitelist_aliases($ticked, $merchantAvailable)`.
  The current per-method logic is deleted.
- `get_whitelisted_methods()` (helpers.php ~line 212) likewise
  expands. It now also reads the merchant-available list from
  `$params['_availablePaymentMethods']`, splits on comma, and passes
  to the expander.

### 5. `updateClientInfo` removal

- Delete the config field from `helpers.php` (lines 169-174).
- Confirmed by `grep -rn updateClientInfo` that no PHP file reads
  it. The consume path was already removed in commit `b7baac8`.
- `changelog.txt` notes the option is no longer rendered; existing
  DB rows are ignored.

### 6. Default-tick matrix

| Gateway | Default-on group(s) |
|---|---|
| `chip` (all methods) | none |
| `chip_cards` | `card` |
| `chip_fpx` | (no group; per-method `fpx` default-on, as today) |
| `chip_fpxb2b1` | (no group; per-method `fpx_b2b1` default-on) |
| `chip_dnqr` | `duitnow_qr` |
| `chip_ewallets` | `shopee_pay` + per-method `razer_*` default-on |
| `chip_crypto_coin` | (no group; per-method `crypto_coin` default-on) |

## Data flow

```
Admin loads gateway config form
   ↓
helpers.php::get_config_params()
   ↓
chip.payment_methods(currency) → available list
   ↓
For each available apm:
  - If in an alias group → render one tickbox per group
  - Else → render one tickbox per apm (existing)
   ↓
Stash _availablePaymentMethods (CSV) for emit step
   ↓
User ticks Card, ticks fpx_b2b1, saves
   ↓
DB: payment_method_whitelist__card=on, payment_method_whitelist__fpx_b2b1=on,
    _availablePaymentMethods="visa,mastercard,maestro,dnqr,duitnow_qr,fpx,fpx_b2b1,..."
   ↓
User pays → redirect() called
   ↓
redirect reads _availablePaymentMethods, calls expand_whitelist_aliases()
   ↓
Emitted: ['visa', 'mastercard', 'maestro', 'fpx_b2b1']
```

## Edge cases

- **No `paymentWhitelist` is on**: same as today, `payment_method_whitelist` is omitted from the purchase body. No change.
- **All ticks off but `paymentWhitelist` on**: emit `[]`, the `link()` config-error path catches this. No change.
- **Merchant's brand has zero card members**: the `card` alias is not rendered (no members in available list). No regression.
- **Merchant's brand has only `razer_shopeepay`, not `shopee_pay`**: `shopee_pay` group tick is rendered, but the expander resolves to `['razer_shopeepay']` at emit time. Merchant sees the familiar friendly name regardless of the underlying code.
- **Legacy config rows**: `payment_method_whitelist__dnqr=on` or `__visa=on` in `tblpaymentgateways` from before this change become orphan rows. The new code only reads `__card`, `__duitnow_qr`, `__shopee_pay`, plus the un-grouped apm keys. Legacy keys are silently ignored. Documented in changelog.

## Testing notes

The repo has no test suite (per CLAUDE.md). Manual verification:

1. Set `CHIP_BRANCH=feature/disable-recurring` on the live Dokploy
   whmcs service; redeploy.
2. Open `https://whmcs-dev.wanzul-hosting.com/admin/configgateways.php`.
3. For each chip_* gateway, confirm the new tickbox set: chip_cards
   shows "Card" (and mpgs_apple_pay, mpgs_google_pay as individual
   ticks), chip_dnqr shows "DuitNow QR" (only), chip_ewallets shows
   "Shopee Pay" plus the per-method Razer ticks.
4. Tick Card, attempt a payment, inspect the activity log for
   `payment_method_whitelist` and the WHMCS API request payload
   (via `ChipAPIException` message or by tail-ing container logs).
5. Confirm `updateClientInfo` is no longer in the form.

## Out-of-scope follow-ups (not in this spec)

- Per-group "use only X" admin sub-options (e.g. "Card but only
  Visa"). YAGNI for now.
- A new `card_network__amex` alias if CHIP ever adds Amex. Defer
  until the upstream enum changes.
- Renaming the friendly label of the per-method ticks. Out of scope.
