# CHIP for WHMCS (Web Hosting Billing & Automation Platform)

This module adds CHIP payment method option to your WHMCS.

## Installation

* [Download zip file of WHMCS plugin.](https://github.com/CHIPAsia/chip-for-whmcs/archive/master.zip)
* Extract the dowloaded zip files
* Upload folder modules to WHMCS installation directory

### File Structure

The file must be extracted as follows:

```
 modules/gateways/
  |- callback/chip.php
  |- chip/action.php
  |- chip/api.php
  |- chip/logo.png
  |- chip/redirect.php
  |  chip.php
```

## Configuration

Set the **Brand ID** and **Secret Key** in the plugins settings.

### Multiple Currencies

Kindly noted that this plugin will only accept final amount to be paid in MYR. You need to ensure that currency conversion are properly configured as per [WHMCS documentation](https://docs.whmcs.com/Currencies).

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)