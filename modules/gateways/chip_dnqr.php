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

function chip_dnqr_MetaData()
{
  return array(
    'DisplayName' => 'CHIP DNQR',
    'APIVersion' => '1.1',
    // Commented to allow Convert to for Processing
    // 'supportedCurrencies' => array('MYR')
  );
}

function chip_dnqr_config($params = array())
{
  return ChipHelpers::get_config_params('chip_dnqr', 'Duitnow QR (E-Wallet: MAE, GrabPay, ShopeePay, TnG)', $params);
}

function chip_dnqr_config_validate(array $params)
{
}

function chip_dnqr_link($params)
{
  return ChipGateway::link($params, 'chip_dnqr', 'paywithdnqr.png', 'Pay with Duitnow QR');
}

function chip_dnqr_refund($params)
{
  return ChipGateway::refund($params);
}

function chip_dnqr_account_balance($params)
{
  return ChipGateway::account_balance($params);
}

function chip_dnqr_TransactionInformation(array $params = []): Information
{
  return ChipGateway::transaction_information($params);
}

// $params = https://pastebin.com/vz16pSJV
function chip_dnqr_capture($params)
{
  return ChipGateway::capture($params, 'chip_dnqr');
}

/**
 * Log activity.
 *
 * @param string $message The message to log
 * @param int $userId An optional user id to which the log entry relates
 */
// logActivity('Message goes here', 0);

function chip_dnqr_nolocalcc()
{
  // this method must exists to hide card credit input displaying in checkout page
}

function chip_dnqr_storeremote($params)
{
  return ChipGateway::store_remote($params);
}

function chip_dnqr_adminstatusmsg($params)
{
  return false;
}

function chip_dnqr_deactivate()
{
  // remove database table. but make it remains commented
}
