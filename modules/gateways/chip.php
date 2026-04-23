<?php

declare(strict_types=1);

use WHMCS\ClientArea;
use WHMCS\Session;

use WHMCS\Module\Gateway\Balance;
use WHMCS\Module\Gateway\BalanceCollection;

use WHMCS\Billing\Payment\Transaction\Information;
use WHMCS\Carbon;

use WHMCS\Database\Capsule;
use WHMCS\Exception\Module\NotServicable;

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

require_once __DIR__ . '/chip/api.php';
require_once __DIR__ . '/chip/action.php';
require_once __DIR__ . '/chip/helpers.php';
require_once __DIR__ . '/chip/gateway.php';

function chip_MetaData()
{
  return array(
    'DisplayName' => 'CHIP',
    'APIVersion' => '1.1',
    // Commented to allow Convert to for Processing
    // 'supportedCurrencies' => array('MYR')
  );
}

function chip_config($params = array())
{
  return ChipHelpers::get_config_params('chip', 'CHIP', $params);
}

function chip_config_validate(array $params)
{
  $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);

  $base_currency = Capsule::table('tblcurrencies')->where('default', '1')->first();
  $currency_code = $base_currency ? $base_currency->code : 'MYR';

  $convertto = Capsule::table('tblpaymentgateways')
    ->where('gateway', 'chip')
    ->where('setting', 'convertto')
    ->first();

  if ($convertto && $convertto->value) {
    $convertto_currency = Capsule::table('tblcurrencies')->where('id', $convertto->value)->first();
    if ($convertto_currency) {
      $currency_code = $convertto_currency->code;
    }
  }

  $payment_methods = $chip->payment_methods($currency_code);

  $payment_method_configuration_error = false;

  if ($params['paymentWhitelist'] == 'on') {
    $payment_method_configuration_error = true;
    $configured_payment_methods = ChipHelpers::get_whitelisted_methods($params);

    foreach ($configured_payment_methods as $cpm) {
      if (in_array($cpm, $payment_methods['available_payment_methods'])) {
        $payment_method_configuration_error = false;
        break;
      }
    }
  }

  if ($payment_method_configuration_error) {
    throw new NotServicable("Invalid settings for payment method whitelisting.");
  }
}

function chip_link($params)
{
  return ChipGateway::link($params, 'chip', 'logo.png', 'Pay with CHIP');
}

function chip_refund($params)
{
  return ChipGateway::refund($params);
}

function chip_account_balance($params)
{
  return ChipGateway::account_balance($params);
}

function chip_TransactionInformation(array $params = []): Information
{
  return ChipGateway::transaction_information($params);
}

// $params = https://pastebin.com/vz16pSJV
function chip_capture($params)
{
  return ChipGateway::capture($params, 'chip');
}

/**
 * Log activity.
 *
 * @param string $message The message to log
 * @param int $userId An optional user id to which the log entry relates
 */
// logActivity('Message goes here', 0);

function chip_nolocalcc()
{
  // this method must exists to hide card credit input displaying in checkout page
}

function chip_storeremote($params)
{
  return ChipGateway::store_remote($params);
}

function chip_adminstatusmsg($params)
{
  return false;
}

function chip_deactivate()
{
  // remove database table. but make it remains commented
}