# CHIP for WHMCS (Web Hosting Billing & Automation Platform)

This module adds CHIP payment method option to your WHMCS.

## Installation

* [Download zip file of WHMCS plugin.](https://github.com/CHIPAsia/chip-for-whmcs/archive/main.zip)
* Extract the dowloaded zip files
* Upload folder modules to WHMCS installation directory

## Configuration

Set the **Brand ID** and **Secret Key** in the plugins settings.

### Multiple Currencies

Kindly noted that this plugin will only accept final amount to be paid in MYR. You need to ensure that currency conversion are properly configured as per [WHMCS documentation](https://docs.whmcs.com/Currencies).

## Missing Translation For Transaction Information

1. Create a directory named `overrides` within the `/path/to/whmcs/admin/lang/` directory.
1. Create file `english.php`: `/path/to/whmcs/admin/lang/overrides/english.php`
1. Start the file with a PHP tag `<?php` indicating PHP code is to be used.
1. Enter the variable below:

  ```php
    $_ADMINLANG['transactions']['information']['chip_paid_on'] = "Paid On";
  ```

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)