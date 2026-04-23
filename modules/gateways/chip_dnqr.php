<?php

declare(strict_types=1);

use WHMCS\Billing\Payment\Transaction\Information;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/chip/api.php';
require_once __DIR__ . '/chip/action.php';
require_once __DIR__ . '/chip/helpers.php';
require_once __DIR__ . '/chip/gateway.php';

function chip_dnqr_MetaData()
{
    return [
        'DisplayName' => 'CHIP DNQR',
        'APIVersion' => '1.1',
    ];
}

function chip_dnqr_config($params = [])
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
