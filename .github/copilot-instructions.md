# CHIP for WHMCS Project Guidelines

## Project Overview
This is a WHMCS payment gateway module for CHIP. It supports multiple payment methods (Cards, FPX, E-wallets, etc.) through individual gateway files and a shared core logic.

## Architecture
- **Shared Core**: [modules/gateways/chip/](modules/gateways/chip/) contains the core logic.
    - `api.php`: `ChipAPI` class (Singleton) for CHIP REST API interactions.
    - `action.php`: `ChipAction` class for WHMCS business logic (payment completion, invoice updates).
- **Gateway Entry Points**: [modules/gateways/](modules/gateways/) (e.g., `chip_cards.php`, `chip_fpx.php`).
- **Callbacks/Webhooks**: [modules/gateways/callback/](modules/gateways/callback/) handles incoming payment notifications.

## Code Style & Conventions
- **Language**: PHP.
- **WHMCS Integration**: Use WHMCS internal functions and the `Capsule` (Eloquent) DAO for database operations.
- **Naming**: Gateway files follow `chip_<method>.php`. Supporting files for a method are in `modules/gateways/chip_<method>/`.
- **Concurrency**: Use MySQL `GET_LOCK` (as seen in [modules/gateways/chip/action.php](modules/gateways/chip/action.php)) to prevent race conditions between redirects and webhooks.
- **Error Handling**: Log errors using WHMCS `logTransaction`.

## Build and Deployment
- **Deployment**: Use [staging_deploy.sh](staging_deploy.sh) to package and upload the module to a staging server.
- **Dependencies**: Managed via `composer.json`.

## Key Files
- [modules/gateways/chip.php](modules/gateways/chip.php): Main gateway registration.
- [modules/gateways/chip/api.php](modules/gateways/chip/api.php): API client implementation.
- [modules/gateways/chip/action.php](modules/gateways/chip/action.php): Core business logic.

## Pitfalls & Gotchas
- **Currency**: Supports multiple currencies with automatic conversion via WHMCS "Convert to For Processing" settings.
- **Translations**: Requires manual translation overrides in WHMCS for full localized support. See [README.md](README.md) for details.
- **Environment**: Requires a functional WHMCS installation for testing.
