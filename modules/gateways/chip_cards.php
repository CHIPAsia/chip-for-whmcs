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

function chip_cards_MetaData()
{
    return [
        'DisplayName' => 'CHIP Cards',
        'APIVersion' => '1.1',
    ];
}

function chip_cards_config($params = [])
{
    return ChipHelpers::get_config_params('chip_cards', 'Visa / Mastercard', $params);
}

function chip_cards_config_validate(array $params)
{
}

function chip_cards_link($params)
{
    return ChipGateway::link($params, 'chip_cards', 'paywithcard.png', 'Pay with Visa / Mastercard');
}

function chip_cards_refund($params)
{
    return ChipGateway::refund($params);
}

function chip_cards_account_balance($params)
{
    return ChipGateway::account_balance($params);
}

function chip_cards_TransactionInformation(array $params = []): Information
{
    return ChipGateway::transaction_information($params);
}

function chip_cards_capture($params)
{
    return ChipGateway::capture($params, 'chip_cards');
}

/**
 * Log activity.
 *
 * @param string $message The message to log
 * @param int $userId An optional user id to which the log entry relates
 */
// logActivity('Message goes here', 0);

function chip_cards_nolocalcc()
{
    // this method must exists to hide card credit input displaying in checkout page
}

function chip_cards_storeremote($params)
{
    return ChipGateway::store_remote($params);
}

function chip_cards_adminstatusmsg($params)
{
    return false;
}

function chip_cards_deactivate()
{
    // remove database table. but make it remains commented
}
