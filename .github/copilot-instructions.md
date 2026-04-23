# CHIP for WHMCS Project Guidelines

## Project Overview
This is a WHMCS payment gateway module for CHIP. It supports multiple payment methods (Cards, FPX, E-wallets, etc.) through individual gateway files and a shared core logic.

## Architecture
- **Shared Core**: [modules/gateways/chip/](modules/gateways/chip/) contains the core logic.
    - `gateway.php`: `ChipGateway` class (Delegation Pattern). Centralizes all logic for link, refund, capture, and callbacks.
    - `api.php`: `ChipAPI` class (Singleton) for CHIP REST API interactions.
    - `action.php`: `ChipAction` class for WHMCS business logic (payment completion, invoice updates).
- **Gateway Entry Points**: [modules/gateways/](modules/gateways/) (e.g., `chip_cards.php`, `chip_fpx.php`). These are thin wrappers delegating to `ChipGateway`.
- **Callbacks/Webhooks**: [modules/gateways/callback/](modules/gateways/callback/) handles incoming payment notifications via `ChipGateway::callback()`.

## Code Style & Conventions
- **Language**: PHP (Strict types enabled).
- **WHMCS Integration**: Use WHMCS internal functions and the `Capsule` (Eloquent) DAO for database operations.
- **Naming**: Gateway files follow `chip_<method>.php`. Supporting files for a method are in `modules/gateways/chip_<method>/`.
- **Concurrency**: Use MySQL `GET_LOCK('chip_payment_$payment_id', ...)` to prevent race conditions between redirects and webhooks.
- **Error Handling**: Use custom `ChipAPIException` for API errors and log via WHMCS `logTransaction`.

## Build and Deployment
- **Dependencies**: Managed via `composer.json`.
- **Distribution**: Packaged as a standard WHMCS module structure.

## Key Files
- [modules/gateways/chip/gateway.php](modules/gateways/chip/gateway.php): Central logic handler.
- [modules/gateways/chip/api.php](modules/gateways/chip/api.php): API client implementation.
- [modules/gateways/chip/action.php](modules/gateways/chip/action.php): Core business logic.

## Pitfalls & Gotchas
- **Currency**: Requires WHMCS "Convert to For Processing" settings for currencies not directly supported by the merchant's CHIP account.
- **Translations**: Requires manual translation overrides in WHMCS for full localized support. See [README.md](README.md) for details.
- **Environment**: Requires a functional WHMCS installation for testing.
