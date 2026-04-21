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

function chip_crypto_coin_MetaData()
{
  return array(
    'DisplayName' => 'CHIP Crypto Coin',
    'APIVersion' => '1.1',
  );
}

function chip_crypto_coin_config($params = array())
{
  return ChipHelpers::get_config_params('chip_crypto_coin', 'Crypto Coin', $params);
}

function chip_crypto_coin_config_validate(array $params)
{
}

function chip_crypto_coin_link($params)
{
  return ChipGateway::link($params, 'chip_crypto_coin', 'paywithcrypto.png', 'Pay with Crypto Coin');
}

function chip_crypto_coin_refund($params)
{
  return ChipGateway::refund($params);
}

function chip_crypto_coin_account_balance($params)
{
  return ChipGateway::account_balance($params);
}

function chip_crypto_coin_TransactionInformation(array $params = []): Information
{
  return ChipGateway::transaction_information($params);
}

// $params = https://pastebin.com/vz16pSJV
function chip_crypto_coin_capture($params)
{
  return ChipGateway::capture($params, 'chip_crypto_coin');
}

/**
 * Log activity.
 *
 * @param string $message The message to log
 * @param int $userId An optional user id to which the log entry relates
 */
// logActivity('Message goes here', 0);

function chip_crypto_coin_nolocalcc()
{
  // this method must exists to hide card credit input displaying in checkout page
}

function chip_crypto_coin_storeremote($params)
{
  return ChipGateway::store_remote($params);
}

function chip_crypto_coin_adminstatusmsg($params)
{
  return false;
}

function chip_crypto_coin_deactivate()
{
  // remove database table. but make it remains commented
}
