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
  if ($params['currency'] != 'MYR') {
    $html = '<p>The invoice was quoted in ' . $params['currency'] . ' and CHIP only accept payment in MYR.';

    if (ClientArea::isAdminMasqueradingAsClient()) {
      $html .= "\nAdministrator can set convert to processing MYR to enable the payment.";
    }

    return $html . '</p>';
  }

  if (empty($params['secretKey']) or empty($params['brandId'])) {
    return '<p>Secret Key and Brand ID not set</p>';
  }

  if (isset($_GET['success']) && !empty(Session::get('chip_crypto_coin_' . $params['invoiceid']))) {
    $payment_id = Session::getAndDelete('chip_crypto_coin_' . $params['invoiceid']);

    if (\ChipAction::complete_payment($params, $payment_id)) {
      return '<script>window.location.reload();</script>';
    }
  }

  $html = '<p>'
    . nl2br($params['paymentInformation'])
    . '<br />'
    . '<a href="' . $params['systemurl'] . 'modules/gateways/chip/redirect.php?invoiceid=' . $params['invoiceid'] . '&gateway=chip_crypto_coin">'
    . '<img height="44px" src="' . $params['systemurl'] . 'modules/gateways/chip_crypto_coin/paywithcrypto.png" title="' . Lang::trans('Pay with Crypto Coin') . '">'
    . '</a>'
    . '<br />'
    . Lang::trans('invoicerefnum')
    . ': '
    . $params['invoicenum']
    . '</p>';

  return $html;
}

function chip_crypto_coin_refund($params)
{
  if ($params['currency'] != 'MYR') {
    return array(
      'status' => 'error',
      'rawdata' => 'Currency is not MYR!',
      'transid' => $params['transid'],
    );
  }

  if ($params['basecurrency'] != 'MYR') {
    return array(
      'status' => 'error',
      'rawdata' => 'Refund for Purchase ID ' . $params['transid'] . ' needs to be done through CHIP Dashboard.',
      'transid' => $params['transid'],
    );
  }

  $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
  $result = $chip->refund_payment($params['transid'], array('amount' => round($params['amount'] * 100)));

  if (!is_array($result) || !array_key_exists('id', $result) or $result['status'] != 'success') {
    return array(
      'status' => 'error',
      'rawdata' => json_encode($result),
      'transid' => $params['transid'],
    );
  }

  return array(
    'status' => 'success',
    'rawdata' => json_encode($result),
    'transid' => $result['id'],
    'fees' => $result['payment']['fee_amount'] / 100,
  );
}

function chip_crypto_coin_account_balance($params)
{
  $balanceInfo = [];

  // Connect to gateway to retrieve balance information.
  $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
  $balanceData = $chip->account_balance();

  foreach ($balanceData as $currency => $value) {
    $balanceInfo[] = Balance::factory(
      ($value['balance'] / 100),
      $currency
    );
  }

  return BalanceCollection::factory($balanceInfo);
}
