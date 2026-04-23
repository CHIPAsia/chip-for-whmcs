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

function chip_fpxb2b1_MetaData()
{
    return array(
        'DisplayName' => 'CHIP FPX B2B1',
        'APIVersion' => '1.1',
        // Commented to allow Convert to for Processing
        // 'supportedCurrencies' => array('MYR')
    );
}

function chip_fpxb2b1_config($params = array())
{
    return ChipHelpers::get_config_params('chip_fpxb2b1', 'FPX B2B (Corporate Online Banking)', $params);
}

function chip_fpxb2b1_config_validate(array $params)
{
}

function chip_fpxb2b1_link($params)
{
    return ChipGateway::link($params, 'chip_fpxb2b1', 'paywithfpx.png', 'Pay with FPX B2B (Corporate Online Banking)');
}

function chip_fpxb2b1_refund($params)
{
    return ChipGateway::refund($params);
}

function chip_fpxb2b1_account_balance($params)
{
    return ChipGateway::account_balance($params);
}

function chip_fpxb2b1_TransactionInformation(array $params = []): Information
{
    return ChipGateway::transaction_information($params);
}

// $params = https://pastebin.com/vz16pSJV
function chip_fpxb2b1_capture($params)
{
    return ChipGateway::capture($params, 'chip_fpxb2b1');
}

/**
 * Log activity.
 *
 * @param string $message The message to log
 * @param int $userId An optional user id to which the log entry relates
 */
// logActivity('Message goes here', 0);

function chip_fpxb2b1_nolocalcc()
{
    // this method must exists to hide card credit input displaying in checkout page
}

function chip_fpxb2b1_storeremote($params)
{
    return ChipGateway::store_remote($params);
}

function chip_fpxb2b1_adminstatusmsg($params)
{
    return false;
}

function chip_fpxb2b1_deactivate()
{
    // remove database table. but make it remains commented
}
