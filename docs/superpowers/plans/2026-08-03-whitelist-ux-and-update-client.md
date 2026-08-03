# Whitelist UX Collapse + Drop Update Client Info Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse the chip-for-whmcs admin whitelist tickbox set to three user-facing groups (Card, DuitNow QR, Shopee Pay), drive them from a single alias-group registry, drop the dead `updateClientInfo` toggle, and ship it as a single squashed commit on top of `feature/disable-recurring`.

**Architecture:** One `ChipHelpers::whitelist_alias_groups()` private static method is the single source of truth for the alias map. The schema builder (`get_config_params()`) reads it to render one tickbox per group, skipping the per-member raw apm ticks. A new `ChipHelpers::expand_whitelist_aliases($ticked, $merchantAvailable)` public static method does the reverse mapping at emit time, used by both `helpers.php::get_whitelisted_methods()` and `gateway.php::redirect()`. The merchant's available list (from `/payment_methods/`) is stashed in a hidden config field `_availablePaymentMethods` (CSV) at schema-build time so the emit step has the data without re-calling the API.

**Tech Stack:** PHP 7.4+ / 8.x, WHMCS 8.0+, the existing `ChipAPI` Singleton Guzzle client. No new dependencies.

## Global Constraints

- PHP files use `declare(strict_types=1);` (CLAUDE.md, .php-cs-fixer.dist.php)
- WHMCS guards: gateway files start with `if (!defined("WHMCS")) { die(...); }` (CLAUDE.md)
- `.php-cs-fixer.dist.php` enforces PSR-12, short array syntax, alphabetical imports, no unused imports, multiline trailing comma in arrays, blank line before `return`/`break`/`throw`/`continue`/`try`/`declare`, one blank line between methods, fully-multiline method args
- `composer lint` must pass (`Found 0 of N files that can be fixed`)
- Commit author: `Wan Zulkarnain <wanzul@users.noreply.github.com>`. NO `Co-Authored-By: Claude` trailer (user instruction)
- The plan produces ONE squashed commit on top of the current `feature/disable-recurring` HEAD (`b7baac8`), force-pushed with `--force-with-lease`
- Spec: `docs/superpowers/specs/2026-08-03-whitelist-ux-and-update-client-design.md`
- Manual verification on the live Dokploy `whmcs` service (`whmcs-dev.wanzul-hosting.com`) since the repo has no test suite
- Files in scope: `modules/gateways/chip/helpers.php`, `modules/gateways/chip/gateway.php`, `changelog.txt`
- The container is restarted after the push so the entrypoint re-downloads the chip zip from the new commit

---

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `modules/gateways/chip/helpers.php` | Modify | Add `whitelist_alias_groups()` and `expand_whitelist_aliases()`; update `get_config_params()` schema builder to render one tick per group; remove `updateClientInfo` field; rewrite `get_whitelisted_methods()` to use the expander |
| `modules/gateways/chip/gateway.php` | Modify | Replace inline `preg_grep` block in `redirect()` (~line 479) with a call to `expand_whitelist_aliases()`; read `_availablePaymentMethods` from the admin params |
| `changelog.txt` | Modify | Add a 1.7.2 entry noting (a) the new whitelist UX, (b) the removed `updateClientInfo` toggle, and (c) the orphan-row behavior for legacy `__dnqr` / `__visa` / etc config keys |

No new files. No new tests (per repo convention).

---

### Task 1: Add the alias-group registry and the expander to `ChipHelpers`

**Files:**
- Modify: `modules/gateways/chip/helpers.php` — add two new methods (one private, one public), keep existing method signatures intact

**Interfaces:**
- Consumes: nothing (this is the source of truth)
- Produces:
  - `private static function whitelist_alias_groups(): array` — returns `['card' => ['label' => 'Card', 'members' => ['visa', 'mastercard', 'maestro'], 'expand' => 'static'], 'duitnow_qr' => [...], 'shopee_pay' => [...]]`
  - `public static function expand_whitelist_aliases(array $ticked, array $merchantAvailable): array` — returns a deduplicated list of raw `payment_method` values

- [ ] **Step 1: Add the two helper methods to `ChipHelpers`**

Open `modules/gateways/chip/helpers.php`. Find the line `class ChipHelpers\n{\n    public static function get_config_params(`. Insert the two new methods **before** `get_config_params`, inside the class. Use the exact code below (note: this is a method declaration, not a test — the repo has no test suite):

```php
    /**
     * Single source of truth for admin-tickbox alias groups.
     *
     * Each group renders as one tickbox in the admin UI, but expands to
     * one or more raw CHIP `payment_method` values at emit time. The
     * `expand` key controls resolution:
     *   - 'static':    emit each member that the merchant's brand supports
     *   - 'preferred': see expand_whitelist_aliases() for resolution rules
     *
     * @return array<string, array{label: string, members: list<string>, expand: string}>
     */
    private static function whitelist_alias_groups(): array
    {
        return [
            'card' => [
                'label' => 'Card',
                'members' => ['visa', 'mastercard', 'maestro'],
                'expand' => 'static',
            ],
            'duitnow_qr' => [
                'label' => 'DuitNow QR',
                'members' => ['dnqr', 'duitnow_qr'],
                'expand' => 'preferred',
            ],
            'shopee_pay' => [
                'label' => 'Shopee Pay',
                'members' => ['razer_shopeepay', 'shopee_pay'],
                'expand' => 'preferred',
            ],
        ];
    }

    /**
     * Expand a list of ticked config keys into the raw CHIP payment_method
     * values to send in `payment_method_whitelist`. Unknown keys are
     * passed through unchanged. Result is deduplicated, preserving the
     * order in which values first appear.
     *
     * @param list<string> $ticked Config-key tails (e.g. ['card', 'fpx_b2b1'])
     * @param list<string> $merchantAvailable The merchant's available payment methods
     *                                        (from /payment_methods/), used to
     *                                        resolve 'preferred' groups
     * @return list<string>
     */
    public static function expand_whitelist_aliases(array $ticked, array $merchantAvailable): array
    {
        $available = array_flip($merchantAvailable);
        $groups = self::whitelist_alias_groups();
        $out = [];

        foreach ($ticked as $key) {
            if (!isset($groups[$key])) {
                $out[] = $key;
                continue;
            }

            $group = $groups[$key];

            if ($group['expand'] === 'static') {
                foreach ($group['members'] as $member) {
                    if (isset($available[$member])) {
                        $out[] = $member;
                    }
                }
                continue;
            }

            // 'preferred' resolution
            $members = $group['members'];
            if ($key === 'duitnow_qr') {
                if (isset($available['dnqr']) && isset($available['duitnow_qr'])) {
                    $out[] = 'dnqr';
                } elseif (isset($available['dnqr'])) {
                    $out[] = 'dnqr';
                } elseif (isset($available['duitnow_qr'])) {
                    $out[] = 'duitnow_qr';
                }
                continue;
            }

            if ($key === 'shopee_pay') {
                if (isset($available['shopee_pay'])) {
                    $out[] = 'shopee_pay';
                } elseif (isset($available['razer_shopeepay'])) {
                    $out[] = 'razer_shopeepay';
                }
                continue;
            }

            // Fallback (shouldn't reach here given the static registry)
            foreach ($members as $member) {
                if (isset($available[$member])) {
                    $out[] = $member;
                }
            }
        }

        return array_values(array_unique($out));
    }
```

The opening brace of the class is at `class ChipHelpers\n{` on line 7. Place the new methods right after that brace.

- [ ] **Step 2: Run the linter**

Run: `composer lint`
Expected: `Found 0 of 20 files that can be fixed`. If anything is reported, fix the formatting (the most common issue is missing trailing comma in the inner `['label' => 'Card', 'members' => [...], 'expand' => 'static']` arrays — make sure each array has a trailing comma after its last element).

- [ ] **Step 3: Commit the helpers (not yet — this is part of the squash)**

Don't commit yet. The squash is the final step. Continue to Task 2.

---

### Task 2: Update the schema builder in `get_config_params()`

**Files:**
- Modify: `modules/gateways/chip/helpers.php` — within `get_config_params()`, change the loop that builds the per-apm tickbox set; remove `updateClientInfo` field; add `_availablePaymentMethods` stashing

**Interfaces:**
- Consumes: `whitelist_alias_groups()` (Task 1)
- Produces: updated `$available_payment_method` array; removed `updateClientInfo` config field; new `_availablePaymentMethods` hidden field

- [ ] **Step 1: Remove the `updateClientInfo` field block**

In `modules/gateways/chip/helpers.php`, find this block (around lines 169-174):

```php
            'updateClientInfo' => [
                'FriendlyName' => 'Update client information',
                'Type' => 'yesno',
                'Description' => 'Tick to update client information on purchase creation.',
                'Default' => 'on',
            ],
```

Delete the entire 6-line block.

- [ ] **Step 2: Modify the per-apm tickbox loop to use alias groups**

Find the per-apm rendering loop. The current structure (around lines 52-111 of `helpers.php`) is:

```php
                    $methods_by_category = [];
                    foreach ($result['available_payment_methods'] as $apm) {
                        if ($apm == 'razer') {
                            continue;
                        }

                        $found_cat = 'Others';
                        foreach ($categories as $cat => $members) {
                            if (in_array($apm, $members)) {
                                $found_cat = $cat;
                                break;
                            }
                        }
                        $methods_by_category[$found_cat][] = $apm;
                    }

                    foreach ($methods_by_category as $category => $apms) {
                        $is_first_in_cat = true;
                        foreach ($apms as $apm) {
                            $default = 'no';

                            // Logic for specific gateway defaults
                            if ($gateway_name == 'chip_cards' && in_array($apm, ['maestro', 'mastercard', 'visa'])) {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_fpx' && $apm == 'fpx') {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_fpxb2b1' && $apm == 'fpx_b2b1') {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_dnqr' && $apm == 'duitnow_qr') {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_ewallets' && in_array($apm, ['razer_atome', 'razer_grabpay', 'razer_maybankqr', 'razer_shopeepay', 'razer_tng'])) {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_crypto_coin' && $apm == 'crypto_coin') {
                                $default = 'yes';
                            }

                            $friendly_apm = str_replace('_', ' ', $apm);
                            $friendly_apm = ucwords($friendly_apm);
                            $friendly_apm = str_replace(['Fpx', 'B2b1', 'Qr'], ['FPX', 'B2B1', 'QR'], $friendly_apm);
                            $friendly_apm = str_replace(['Razer ', 'Mpgs '], '', $friendly_apm);

                            $description = 'Tick to enable ' . $friendly_apm;
                            if ($default == 'yes') {
                                $description .= ' (Default)';
                            }

                            $friendly_name_label = 'Whitelist ' . $friendly_apm;
                            if ($is_first_in_cat) {
                                $friendly_name_label = '<b>[' . strtoupper($category) . ']</b><br/>' . $friendly_name_label;
                                $is_first_in_cat = false;
                            }

                            $available_payment_method['payment_method_whitelist__' . $apm] = [
                                'FriendlyName' => $friendly_name_label,
                                'Type' => 'yesno',
                                'Default' => $default,
                                'Description' => $description,
                            ];
                        }
                    }
```

Replace it with this (note: `$methods_by_category` is built the same way; the change is in the per-apm loop, which now skips apms that belong to an alias group, and after the loop we render one tick per group that has at least one member present):

```php
                    $alias_groups = self::whitelist_alias_groups();
                    $alias_member_to_group = [];
                    foreach ($alias_groups as $group_key => $group) {
                        foreach ($group['members'] as $member) {
                            $alias_member_to_group[$member] = $group_key;
                        }
                    }

                    $methods_by_category = [];
                    foreach ($result['available_payment_methods'] as $apm) {
                        if ($apm == 'razer') {
                            continue;
                        }

                        $found_cat = 'Others';
                        foreach ($categories as $cat => $members) {
                            if (in_array($apm, $members)) {
                                $found_cat = $cat;
                                break;
                            }
                        }
                        $methods_by_category[$found_cat][] = $apm;
                    }

                    // Stash the full available list for the emit step.
                    $available_payment_method['_availablePaymentMethods'] = [
                        'FriendlyName' => 'Available Payment Methods (internal)',
                        'Type' => 'text',
                        'Size' => '255',
                        'Default' => implode(',', $result['available_payment_methods']),
                        'Description' => 'Hidden. Refreshed on every config save. Used by the emit step to resolve whitelist aliases.',
                    ];

                    // Build a set of alias groups that have at least one
                    // member present in the merchant's available list, so we
                    // don't render a tickbox for a group the merchant can't use.
                    $groups_with_members = [];
                    foreach ($result['available_payment_methods'] as $apm) {
                        if (isset($alias_member_to_group[$apm])) {
                            $groups_with_members[$alias_member_to_group[$apm]] = true;
                        }
                    }

                    foreach ($methods_by_category as $category => $apms) {
                        $is_first_in_cat = true;
                        foreach ($apms as $apm) {
                            // Skip apms that are members of an alias group;
                            // the group is rendered once below.
                            if (isset($alias_member_to_group[$apm])) {
                                continue;
                            }

                            $default = 'no';

                            // Logic for specific gateway defaults
                            if ($gateway_name == 'chip_fpx' && $apm == 'fpx') {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_fpxb2b1' && $apm == 'fpx_b2b1') {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_ewallets' && in_array($apm, ['razer_atome', 'razer_grabpay', 'razer_maybankqr', 'razer_tng'])) {
                                $default = 'yes';
                            } elseif ($gateway_name == 'chip_crypto_coin' && $apm == 'crypto_coin') {
                                $default = 'yes';
                            }

                            $friendly_apm = str_replace('_', ' ', $apm);
                            $friendly_apm = ucwords($friendly_apm);
                            $friendly_apm = str_replace(['Fpx', 'B2b1', 'Qr'], ['FPX', 'B2B1', 'QR'], $friendly_apm);
                            $friendly_apm = str_replace(['Razer ', 'Mpgs '], '', $friendly_apm);

                            $description = 'Tick to enable ' . $friendly_apm;
                            if ($default == 'yes') {
                                $description .= ' (Default)';
                            }

                            $friendly_name_label = 'Whitelist ' . $friendly_apm;
                            if ($is_first_in_cat) {
                                $friendly_name_label = '<b>[' . strtoupper($category) . ']</b><br/>' . $friendly_name_label;
                                $is_first_in_cat = false;
                            }

                            $available_payment_method['payment_method_whitelist__' . $apm] = [
                                'FriendlyName' => $friendly_name_label,
                                'Type' => 'yesno',
                                'Default' => $default,
                                'Description' => $description,
                            ];
                        }
                    }

                    // Render one tickbox per alias group that has at least
                    // one member present in the merchant's available list.
                    // Render groups in the same order the categories appear
                    // by walking the original available list in order.
                    $rendered_groups = [];
                    foreach ($result['available_payment_methods'] as $apm) {
                        if (!isset($alias_member_to_group[$apm])) {
                            continue;
                        }
                        $group_key = $alias_member_to_group[$apm];
                        if (isset($rendered_groups[$group_key])) {
                            continue;
                        }
                        $rendered_groups[$group_key] = true;

                        $group = $alias_groups[$group_key];
                        $default = 'no';

                        if ($gateway_name == 'chip_cards' && $group_key === 'card') {
                            $default = 'yes';
                        } elseif ($gateway_name == 'chip_dnqr' && $group_key === 'duitnow_qr') {
                            $default = 'yes';
                        } elseif ($gateway_name == 'chip_ewallets' && $group_key === 'shopee_pay') {
                            $default = 'yes';
                        }

                        $available_payment_method['payment_method_whitelist__' . $group_key] = [
                            'FriendlyName' => 'Whitelist ' . $group['label'],
                            'Type' => 'yesno',
                            'Default' => $default,
                            'Description' => 'Tick to enable ' . $group['label']
                                . ($default === 'yes' ? ' (Default)' : ''),
                        ];
                    }
```

- [ ] **Step 3: Run the linter**

Run: `composer lint`
Expected: `Found 0 of 20 files that can be fixed`. If anything is reported, fix it. The most common issue will be the `array_unique`/array shape linting rules — make sure each multi-line array has a trailing comma after its last element.

- [ ] **Step 4: Commit (still no — this is part of the squash)**

Continue to Task 3.

---

### Task 3: Update `get_whitelisted_methods()` to use the expander

**Files:**
- Modify: `modules/gateways/chip/helpers.php` — replace the body of `get_whitelisted_methods()` with a call to the expander

**Interfaces:**
- Consumes: `expand_whitelist_aliases()` (Task 1)
- Produces: updated `get_whitelisted_methods()` returning a deduplicated list of raw `payment_method` values

- [ ] **Step 1: Rewrite `get_whitelisted_methods()`**

Find the current method (around lines 212-230 of `helpers.php`):

```php
    public static function get_whitelisted_methods($params)
    {
        if ($params['paymentWhitelist'] != 'on') {
            return [];
        }

        $keys = array_keys($params);
        $result = preg_grep('/payment_method_whitelist__.*/', $keys);

        $configured_payment_methods = [];
        foreach ($result as $key) {
            if ($params[$key] == 'on') {
                $key_array = explode('__', $key);
                $configured_payment_methods[] = end($key_array);
            }
        }

        return $configured_payment_methods;
    }
```

Replace it with:

```php
    public static function get_whitelisted_methods($params)
    {
        if ($params['paymentWhitelist'] != 'on') {
            return [];
        }

        $keys = array_keys($params);
        $result = preg_grep('/payment_method_whitelist__.*/', $keys);

        $ticked = [];
        foreach ($result as $key) {
            if ($params[$key] == 'on') {
                $key_array = explode('__', $key);
                $ticked[] = end($key_array);
            }
        }

        $merchantAvailable = [];
        if (!empty($params['_availablePaymentMethods'])) {
            $merchantAvailable = array_values(array_filter(
                array_map('trim', explode(',', (string) $params['_availablePaymentMethods'])),
                'strlen'
            ));
        }

        return self::expand_whitelist_aliases($ticked, $merchantAvailable);
    }
```

- [ ] **Step 2: Run the linter**

Run: `composer lint`
Expected: `Found 0 of 20 files that can be fixed`.

- [ ] **Step 3: Continue to Task 4**

---

### Task 4: Replace the inline whitelist loop in `redirect()` with a call to the expander

**Files:**
- Modify: `modules/gateways/chip/gateway.php` — replace the inline block at lines ~479-491 with a call to `ChipHelpers::expand_whitelist_aliases()`

**Interfaces:**
- Consumes: `ChipHelpers::expand_whitelist_aliases()` (Task 1)
- Produces: same `payment_method_whitelist` value on the wire, but now driven by the alias registry

- [ ] **Step 1: Replace the inline block**

Find this block in `gateway.php` (around lines 479-491):

```php
        if (isset($params['paymentWhitelist']) and $params['paymentWhitelist'] == 'on') {
            $send_params['payment_method_whitelist'] = [];

            $keys = array_keys($params);
            $result = preg_grep('/payment_method_whitelist__.*/', $keys);

            foreach ($result as $key) {
                if ($params[$key] == 'on') {
                    $key_array = explode('__', $key);
                    $send_params['payment_method_whitelist'][] = end($key_array);
                }
            }
        }
```

Replace it with:

```php
        if (isset($params['paymentWhitelist']) and $params['paymentWhitelist'] == 'on') {
            $keys = array_keys($params);
            $result = preg_grep('/payment_method_whitelist__.*/', $keys);

            $ticked = [];
            foreach ($result as $key) {
                if ($params[$key] == 'on') {
                    $key_array = explode('__', $key);
                    $ticked[] = end($key_array);
                }
            }

            $merchantAvailable = [];
            if (!empty($params['_availablePaymentMethods'])) {
                $merchantAvailable = array_values(array_filter(
                    array_map('trim', explode(',', (string) $params['_availablePaymentMethods'])),
                    'strlen'
                ));
            }

            $send_params['payment_method_whitelist'] = \ChipHelpers::expand_whitelist_aliases($ticked, $merchantAvailable);
        }
```

- [ ] **Step 2: Run the linter**

Run: `composer lint`
Expected: `Found 0 of 20 files that can be fixed`.

- [ ] **Step 3: Continue to Task 5**

---

### Task 5: Add a `changelog.txt` entry and verify no remaining dead references

**Files:**
- Modify: `changelog.txt` — add a new entry at the top

- [ ] **Step 1: Read the current top of `changelog.txt`**

Run: `head -20 /root/chip-for-whmcs/changelog.txt`

The file follows the WHMCS convention. Add a new `## [version] - date` section above the most recent existing entry. Today's date is 2026-08-03. Bump version to `1.7.2`.

- [ ] **Step 2: Add the changelog entry**

Prepend (do not append) the following block to `changelog.txt`:

```
## [1.7.2] - 2026-08-03

### Changed
- Payment method whitelist: collapsed card networks (Visa, Mastercard, Maestro) into a single "Card" tickbox that expands to the three networks supported by the merchant's brand.
- Payment method whitelist: collapsed DuitNow QR variants into a single "DuitNow QR" tickbox. When the merchant's brand supports both `dnqr` and `duitnow_qr`, the emitted value is `dnqr`; otherwise the only supported value is emitted.
- Payment method whitelist: collapsed Shopee Pay variants into a single "Shopee Pay" tickbox. When the merchant's brand supports `shopee_pay`, that value is emitted; otherwise the fallback is `razer_shopeepay`.

### Removed
- "Update client information" admin toggle. The corresponding code path was removed in 1.7.1. Existing database rows with this setting are silently ignored.

### Migration notes
- Existing per-method whitelist keys (`payment_method_whitelist__visa`, `__mastercard`, `__maestro`, `__dnqr`, `__duitnow_qr`, `__razer_shopeepay`, `__shopee_pay`) saved in `tblpaymentgateways` from earlier versions are silently ignored. Re-tick the new group tickboxes (`__card`, `__duitnow_qr`, `__shopee_pay`) after upgrading to apply whitelisting.

```

- [ ] **Step 3: Verify zero remaining references to removed identifiers**

Run: `grep -rn "updateClientInfo" /root/chip-for-whmcs --include="*.php"`
Expected: no output (zero matches).

Run: `grep -rn "create_client\|get_client_by_email\|patch_client" /root/chip-for-whmcs --include="*.php"`
Expected: no output. (These were removed in commit `b7baac8`; double-check after the helpers changes.)

Run: `grep -rn "payment_method_whitelist__visa\|payment_method_whitelist__mastercard\|payment_method_whitelist__maestro\|payment_method_whitelist__dnqr\|payment_method_whitelist__duitnow_qr\|payment_method_whitelist__razer_shopeepay\|payment_method_whitelist__shopee_pay" /root/chip-for-whmcs --include="*.php"`
Expected: no output. (These per-method keys should no longer be emitted anywhere in PHP code. Legacy DB rows are an orthogonal concern documented in the changelog.)

- [ ] **Step 4: Continue to Task 6**

---

### Task 6: Verify, squash, push, and deploy

**Files:**
- Modify: git history (squash Tasks 1-5 into one commit on top of `b7baac8`)

- [ ] **Step 1: Run the linter one more time**

Run: `composer lint`
Expected: `Found 0 of 20 files that can be fixed`.

- [ ] **Step 2: Audit the staged diff**

Run: `git diff --stat`
Expected: only `modules/gateways/chip/helpers.php`, `modules/gateways/chip/gateway.php`, and `changelog.txt` show changes. No other files.

Run: `git diff -- modules/gateways/chip/`
Expected: a self-contained diff that adds the two helper methods to `ChipHelpers`, modifies `get_config_params()` to skip alias-group members, modifies `get_whitelisted_methods()` to use the expander, and modifies `redirect()` to use the expander. No `updateClientInfo` block remains. No `create_client`/`patch_client`/`get_client_by_email` references.

- [ ] **Step 3: Stage the three modified files**

Run:
```bash
git add modules/gateways/chip/helpers.php modules/gateways/chip/gateway.php changelog.txt
```

- [ ] **Step 4: Verify staged contents**

Run: `git diff --staged --stat`
Expected: 3 files changed, with the same line counts as the working diff.

- [ ] **Step 5: Commit (single squashed commit, no co-author trailer)**

Run:
```bash
git -c user.name="Wan Zulkarnain" -c user.email="wanzul@users.noreply.github.com" commit -m "Refactor: Collapse whitelist UX into Card/DuitNow QR/Shopee Pay groups

The admin config exposed every raw CHIP payment_method value as its
own tickbox. For groups where the values are interchangeable from
the merchant's perspective (cards, DuitNow QR, Shopee Pay), this was
both noisy and confusing.

This change:

- Adds ChipHelpers::whitelist_alias_groups() as the single source of
  truth for three groups: card (visa/mastercard/maestro),
  duitnow_qr (dnqr/duitnow_qr, prefers dnqr when both available),
  shopee_pay (shopee_pay preferred, razer_shopeepay fallback).
- Adds ChipHelpers::expand_whitelist_aliases() which resolves the
  ticked group keys to the right raw payment_method values against
  the merchant's available list, with documented preference rules.
- Updates get_config_params() to render one tickbox per group
  instead of one per member, with default-on matching the original
  per-method defaults.
- Updates redirect() in gateway.php and get_whitelisted_methods()
  in helpers.php to route through the expander, eliminating the
  duplicated preg_grep loop.
- Stashes the merchant's available payment methods in a hidden
  _availablePaymentMethods config field at schema-build time so the
  emit step has the data without re-calling /payment_methods/.

Also removes the 'Update client information' admin toggle, which
has been dead weight since the /clients/ API calls were dropped
in the previous refactor."
```

Run: `git log -1 --format='%B' | grep -i 'co-authored'`
Expected: no output. (If a co-author trailer was accidentally added, amend the commit with `git commit --amend --no-edit` and re-check.)

- [ ] **Step 6: Verify commit history on the branch**

Run: `git log --oneline -5`
Expected: top of log shows the new commit (something like `0f73ccd…b7baac8` plus the new commit), with the new commit on top and no fixup commits left over from Task 1-5.

- [ ] **Step 7: Force-push to the PR branch**

Run: `git push --force-with-lease origin feature/disable-recurring`
Expected: a single commit added, prior PR head replaced. If the push is rejected because of non-fast-forward (e.g. something was pushed to the branch concurrently), STOP and ask the user before using `--force` (without the `with-lease`).

- [ ] **Step 8: Trigger Dokploy redeploy**

Use the mcp dokploy tool:
```text
mcp__dokploy-mcp__compose-deploy composeId="-FKL3swMsAUQ1WX878aSs" title="chip-for-whmcs#<new-commit-sha>: collapse whitelist UX" description="Re-fetching chip-for-whmcs feature/disable-recurring so the entrypoint copies the new helpers.php, gateway.php, and the changelog."
```

Then monitor `/etc/dokploy/logs/chip-whmcs-5zzxsu/` for the new `Docker Compose Deployed:` marker. (Use the Bash Monitor tool to wait for it, e.g. `until grep -q "Docker Compose Deployed" /etc/dokploy/logs/chip-whmcs-5zzxsu/$(ls -1t /etc/dokploy/logs/chip-whmcs-5zzxsu/ | head -1); do sleep 5; done`.)

- [ ] **Step 9: Restart the container to force entrypoint re-run**

The whmcs-docker image doesn't change on this push, so Dokploy's compose up is a no-op. Force a container restart:

Run: `docker restart chip-whmcs-5zzxsu-whmcs_web-1`

Wait for Apache to come back: `until docker exec chip-whmcs-5zzxsu-whmcs_web-1 sh -c 'pgrep -f "apache2 -DFOREGROUND" >/dev/null'; do sleep 3; done`.

- [ ] **Step 10: Verify live**

Run inside the container:
```bash
docker exec chip-whmcs-5zzxsu-whmcs_web-1 sh -c 'echo "=== helpers.php mtime ==="; stat -c "%y %n" /var/www/html/modules/gateways/chip/helpers.php; echo; echo "=== gateway.php mtime ==="; stat -c "%y %n" /var/www/html/modules/gateways/chip/gateway.php; echo; echo "=== whitelist_alias_groups present ==="; grep -c "whitelist_alias_groups" /var/www/html/modules/gateways/chip/helpers.php; echo; echo "=== expand_whitelist_aliases used in both call sites ==="; grep -n "expand_whitelist_aliases" /var/www/html/modules/gateways/chip/helpers.php /var/www/html/modules/gateways/chip/gateway.php; echo; echo "=== updateClientInfo absent ==="; grep -c "updateClientInfo" /var/www/html/modules/gateways/chip/helpers.php; echo; echo "=== /clients/ methods absent (regression check) ==="; grep -c "get_client_by_email\|patch_client\|create_client" /var/www/html/modules/gateways/chip/gateway.php; echo; echo "=== PR #14 disableRecurring still present ==="; grep -n "disableRecurring" /var/www/html/modules/gateways/chip/helpers.php /var/www/html/modules/gateways/chip/action.php /var/www/html/modules/gateways/chip/gateway.php'
```

Expected:
- `helpers.php` mtime: today, after the restart
- `gateway.php` mtime: today, after the restart
- `whitelist_alias_groups`: count > 0
- `expand_whitelist_aliases` referenced in both `helpers.php` and `gateway.php`
- `updateClientInfo` count: 0
- `/clients/` count: 0
- `disableRecurring` markers: all 4 present (helpers.php:184, action.php:113, gateway.php:228-229)

- [ ] **Step 11: Document the user-facing change**

Tell the user: the live service is now showing the collapsed whitelist tickbox set. Ask them to log in to `https://whmcs-dev.wanzul-hosting.com/admin/configgateways.php` and verify that for each `chip_*` gateway:
- chip_cards shows one "Card" tickbox + the mpgs_apple_pay / mpgs_google_pay individual ticks
- chip_dnqr shows only the "DuitNow QR" tickbox
- chip_ewallets shows the "Shopee Pay" tickbox + the per-method Razer ticks
- "Update client information" is no longer in the form
- A hidden `_availablePaymentMethods` field is present (text input, can be ignored)

Note for the user: existing merchants with the old per-method ticks (e.g. `payment_method_whitelist__dnqr=on`) will have those rows orphaned in the DB after upgrade. They are harmless — the new code only reads the group keys. If the user wants to clean them up, that's a separate manual SQL migration, not in scope.
