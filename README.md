# CHIP for WHMCS

The official CHIP payment gateway module for WHMCS. This module provides a seamless integration with CHIP, supporting a wide range of Malaysian and international payment methods.

## 🚀 Features

- **Multiple Payment Methods**: Support for Cards, FPX, E-Wallets, DuitNow QR, and Crypto.
- **Automated Refunds**: Process refunds directly from the WHMCS admin interface.
- **Tokenization**: Support for recurring payments and "Save Card" functionality.
- **Centralized Logic**: Built with a "Thin Gateway" architecture for high performance and easy maintenance.
- **Internationalization**: Fully translatable client and admin interfaces.
- **Security**: RSA Signature verification and database-level concurrency locking.

## 📋 Requirements

- **PHP**: 7.1 or higher (PHP 7.4+ recommended)
- **WHMCS**: 8.0 or higher
- **CHIP Account**: A valid Brand ID and Secret Key from the [CHIP Portal](https://portal.chip-in.asia/).

## 📦 Installation

1. [Download the latest release](https://github.com/CHIPAsia/chip-for-whmcs/releases).
2. Extract the files and upload the `modules/` directory to your WHMCS root installation.
3. In WHMCS Admin, go to **System Settings > Payment Gateways**.
4. Activate **CHIP** (or specific methods like **CHIP Cards**, **CHIP FPX**, etc.).

## ⚙️ Configuration

Enter your **Brand ID** and **Secret Key** in the gateway settings. 

### Currency Handling
Please note that this module requires the final payment amount to be in **MYR**. If your store uses other currencies, ensure you have configured [Currency Conversion](https://docs.whmcs.com/Currencies) in WHMCS to "Convert To" MYR for the CHIP gateways.

## 🌐 Localization & Translations

We use standard WHMCS localization. To override or translate strings, create/edit `/lang/overrides/english.php` (or your language):

```php
<?php
// Transaction Information
$_ADMINLANG['transactions']['information']['chip_paid_on'] = "Paid On";

// Gateway Messages
$_LANG['This invoice is quoted in :currency, but CHIP only accepts payments in MYR.'] = "Your custom message here...";
$_LANG['Manual refund required: Automated refunds are only supported for MYR base currency. Please process the refund for Purchase ID :transid via the CHIP Dashboard.'] = "Manual action needed for :transid...";
```

## 🛠 Developer Information

This module follows a **Delegation Pattern**. All business logic is centralized in `modules/gateways/chip/gateway.php`. Individual gateway files act as thin wrappers, ensuring consistent behavior across all payment methods.

## 💬 Support

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
