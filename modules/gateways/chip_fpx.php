<?php

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

function chip_fpx_MetaData()
{
  return array(
    'DisplayName' => 'CHIP FPX',
    'APIVersion' => '1.1',
    // Commented to allow Convert to for Processing
    // 'supportedCurrencies' => array('MYR')
  );
}

function chip_fpx_config($params = array())
{
  return ChipHelpers::get_config_params('chip_fpx', 'FPX (Online Banking)', $params);
}

function chip_fpx_config_validate(array $params)
{
}

function chip_fpx_link($params)
{
  return ChipGateway::link($params, 'chip_fpx', 'paywithfpx.png', 'Pay with FPX (Online Banking)');
}

function chip_fpx_refund($params)
{
  return ChipGateway::refund($params);
}

function chip_fpx_account_balance($params)
{
  return ChipGateway::account_balance($params);
}

function chip_fpx_TransactionInformation(array $params = []): Information
{
  return ChipGateway::transaction_information($params);
}

// $params = https://pastebin.com/vz16pSJV
function chip_fpx_capture($params)
{
  return ChipGateway::capture($params, 'chip_fpx');
}

/**
 * Log activity.
 *
 * @param string $message The message to log
 * @param int $userId An optional user id to which the log entry relates
 */
// logActivity('Message goes here', 0);

function chip_fpx_nolocalcc()
{
  // this method must exists to hide card credit input displaying in checkout page
}

function chip_fpx_storeremote($params)
{
  return ChipGateway::store_remote($params);
}

function chip_fpx_adminstatusmsg($params)
{
  return false;
}

function chip_fpx_deactivate()
{
  // remove database table. but make it remains commented
}
