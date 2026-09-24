# AGENTS.md

This file provides guidance to AI coding agents working in this repository.

## Project Overview

CHIP for WHMCS is a payment gateway module for WHMCS 8.0+ (PHP 7.1+, 7.4+ recommended) integrating the [CHIP](https://www.chip-in.asia/) Digital Finance Platform. It supports Cards, FPX, FPX B2B1, E-Wallets, DuitNow QR, and Crypto Coin payment methods, plus refunds, tokenization/recurring payments, and multi-currency handling.

Current module version: `1.7.1` (defined in `modules/gateways/chip/api.php` as `CHIP_MODULE_VERSION`).

## Common Commands

```bash
# Lint (dry-run, no changes) — runs the same check as CI
composer lint

# Auto-fix code style issues
composer fix

# Install dev dependencies (PHP CS Fixer v3)
composer install
```

There is no test suite in this repo — only `php-cs-fixer` for code style. Manual testing requires a working WHMCS installation and a CHIP Brand ID / Secret Key.

## Architecture: Thin Gateway / Delegation Pattern

The module centralizes all logic in `modules/gateways/chip/` and exposes it through thin per-method gateway wrappers. Individual gateway files contain **only function declarations** that delegate to the shared classes — no business logic.

### Layout

```
modules/gateways/
├── chip.php                  # Generic "all methods" gateway
├── chip_cards.php            # Visa / Mastercard
├── chip_fpx.php              # FPX
├── chip_fpxb2b1.php          # FPX B2B1
├── chip_ewallets.php         # E-Wallets
├── chip_dnqr.php             # DuitNow QR
├── chip_crypto_coin.php      # Crypto Coin
├── callback/                 # Webhook entry points (one per gateway)
│   ├── chip.php              # require + ChipGateway::callback('chip')
│   ├── chip_cards.php
│   └── ...                   # all mirror the same 2-line pattern
└── chip/                     # ← ALL business logic lives here
    ├── api.php               # ChipAPI class (Singleton Guzzle client)
    ├── action.php            # ChipAction class (WHMCS integration)
    ├── gateway.php           # ChipGateway class (entry points used by wrappers)
    ├── helpers.php           # ChipHelpers (config schema, whitelist parsing)
    ├── exceptions.php        # ChipAPIException
    ├── redirect.php          # require + ChipGateway::redirect()
    ├── logo.png
    └── whmcs.json
```

Per-method assets (gateway logos, `whmcs.json`) live in `modules/gateways/chip_<method>/`.

### Core classes

- **`ChipAPI`** (`chip/api.php`) — Singleton wrapper around `GuzzleHttp\Client` targeting `https://gate.chip-in.asia/api/v1/`. Methods: `create_payment`, `charge_payment`, `get_payment`, `payment_methods`, `payment_recurring_methods`, `refund_payment`, `create_client` / `get_client_by_email` / `patch_client`, `delete_token`, `public_key`, `account_balance`. All API errors raise `ChipAPIException` (defined in `chip/exceptions.php`).

- **`ChipAction`** (`chip/action.php`) — WHMCS business logic. `complete_payment()` is the critical race-safe path that writes transaction history, validates currency, applies `addInvoicePayment`, optionally stores `RemoteCreditCard` pay method, and triggers email. `retrieve_public_key()` caches the CHIP public key in WHMCS settings (`CHIP_PUBLIC_KEY_<first 10 chars of secret>`). Exposes a CLI/GET-only `clean_up_public_key()` endpoint.

- **`ChipGateway`** (`chip/gateway.php`) — Public entry points used by the thin gateway files: `link()`, `refund()`, `account_balance()`, `transaction_information()`, `capture()`, `store_remote()`, `callback()`, `redirect()`. Also handles the WHMCS `Convert To` multi-currency conversion for payments, captures, and refunds.

- **`ChipHelpers`** (`chip/helpers.php`) — `get_config_params()` builds the gateway admin config schema (calls CHIP `payment_methods`/`payment_recurring_methods` to dynamically build whitelist options). `get_whitelisted_methods()` reads `payment_method_whitelist__*` params.

### Gateway wrapper pattern

Every `chip_*.php` file follows the exact same shape — only the function prefix, display name, image, and (for `chip.php`) the whitelist validation differ:

```php
require_once __DIR__ . '/chip/api.php';
require_once __DIR__ . '/chip/action.php';
require_once __DIR__ . '/chip/helpers.php';
require_once __DIR__ . '/chip/gateway.php';

function chip_xxx_MetaData() { return ['DisplayName' => '...', 'APIVersion' => '1.1']; }
function chip_xxx_config($params = []) { return ChipHelpers::get_config_params('chip_xxx', '...', $params); }
function chip_xxx_link($params) { return ChipGateway::link($params, 'chip_xxx', 'xxx.png', 'Pay with ...'); }
function chip_xxx_refund($params) { return ChipGateway::refund($params); }
// ... same delegation pattern for account_balance, TransactionInformation, capture,
//     nolocalcc, storeremote, adminstatusmsg, deactivate
```

Callback files are even smaller: `require_once __DIR__ . '/../chip/gateway.php'; ChipGateway::callback('chip_xxx');`

When adding a new payment method, create the `chip_*.php` wrapper, a `chip_*/` asset directory, and a `callback/chip_*.php` — no business-logic duplication.

## Critical Implementation Details

- **Concurrency lock**: `complete_payment()` and `capture()` use MySQL `GET_LOCK('chip_payment_$payment_id', 15)` (or 10s in capture) to prevent double-application when redirect and webhook race. Always pair with `RELEASE_LOCK` in a `finally` block.
- **Webhook signature verification**: `ChipGateway::callback()` verifies `HTTP_X_SIGNATURE` via `openssl_verify` against the cached CHIP public key (sha256WithRSAEncryption). Mismatches return 403.
- **Multi-currency**: `convertCurrency()` (WHMCS helper) is invoked whenever `params['convertto']` is set. `complete_payment()` enforces that the CHIP transaction currency matches the configured `convertto` setting; mismatch throws `NotServicable`.
- **Strict types**: All PHP files use `declare(strict_types=1);`.
- **WHMCS guards**: Gateway files start with `if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }` — keep this.
- **Public key cleanup**: `ChipAction::clean_up_public_key()` requires admin auth (or CLI) and is exposed at `chip/action.php?clean_up_public_key=1`. Used to invalidate the cached public key after rotation.

## Code Style (enforced by CI)

`.php-cs-fixer.dist.php` enforces: `@PSR12`, short array syntax, alphabetically ordered imports, no unused imports, multiline trailing comma in arrays, blank line before `return`/`break`/`throw`/`continue`/`try`/`declare`, one blank line between methods, fully-multiline method args, single trait import per statement. CI (`.github/workflows/php-cs-fixer.yml`) runs `php-cs-fixer fix --dry-run --diff --ansi` on every push/PR to `main` using PHP 8.3. **Run `composer fix` before committing.**

## CI/CD

Three GitHub Actions workflows in `.github/workflows/`:

1. **`php-cs-fixer.yml`** — Lint on push/PR to `main`.
2. **`build-release.yml`** — On PR: builds `chip-for-whmcs-pr-<#>.zip` (modules/ + LICENSE) and uploads as workflow artifact. On tag push: creates a GitHub Release with the same archive using `softprops/action-gh-release`.
3. **`auto-generate-pr-summary.yml`** — On PR open/sync: uses `scripts/generate_pr_summary.py` (Ollama Cloud, default model `gemini-3-flash-preview:cloud`) to draft a PR body and overwrite the description via `gh pr edit`. Requires `OLLAMA_API_KEY` (var or secret).

`.gitattributes` excludes dev/release files (`.vscode`, `*.md`, `.gitignore`, `.gitattributes`, `composer.json`, `changelog.txt`) from the release zip via `export-ignore`.

## Repository Hygiene

- The repo has **no test suite** and no PHPStan/PHPUnit. Validation = lint + manual WHMCS install.
- `composer.lock` is git-ignored; only `composer.json` is committed.
- Versioning lives in `changelog.txt` (WHMCS convention) and the `CHIP_MODULE_VERSION` constant in `chip/api.php` — bump both together.
