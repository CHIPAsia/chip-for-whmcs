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

function chip_crypto_coin_MetaData()
{
  return array(
    'DisplayName' => 'CHIP Crypto Coin',
    'APIVersion' => '1.1',
  );
}

function chip_crypto_coin_config($params = array())
{
  $list_time_zones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

  $formatted_time_zones = array();
  foreach ($list_time_zones as $mtz) {
    $formatted_time_zones[$mtz] = str_replace("_", " ", $mtz);
  }

  // query available payment method
  $show_whitelist_option = false;
  $available_payment_method = array();

  if (empty($params['secretKey'] || empty($params['brandId']))) {
    // do nothing
  } else {
    $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);

    // List all payment methods
    $result = [
      'available_payment_methods' => [
        'fpx',
        'fpx_b2b1',
        'duitnow_qr',
        'maestro',
        'mastercard',
        'visa',
        'razer_atome',
        'razer_grabpay',
        'razer_maybankqr',
        'razer_shopeepay',
        'razer_tng',
        'mpgs_apple_pay',
        'mpgs_google_pay',
        'crypto_coin'
      ]
    ];

    if (is_array($result) && array_key_exists('available_payment_methods', $result) && !empty($result['available_payment_methods'])) {
      foreach ($result['available_payment_methods'] as $apm) {
        if (in_array($apm, ['crypto_coin'])) {
          $available_payment_method['payment_method_whitelist__' . $apm] = array(
            'FriendlyName' => 'Whitelist ' . ucfirst(str_replace('_', ' ', $apm)),
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Tick to enable ' . ucfirst(str_replace('_', ' ', $apm)),
          );
        } else {
          $available_payment_method['payment_method_whitelist__' . $apm] = array(
            'FriendlyName' => 'Whitelist ' . ucfirst(str_replace('_', ' ', $apm)),
            'Type' => 'yesno',
            'Description' => 'Tick to enable ' . ucfirst(str_replace('_', ' ', $apm)),
          );
        }
      }

      $show_whitelist_option = true;
    }
  }

  $config_params = array(
    'FriendlyName' => array(
      'Type' => 'System',
      'Value' => 'Crypto Coin',
    ),
    'brandId' => array(
      'FriendlyName' => 'Brand ID',
      'Type' => 'text',
      'Size' => '25',
      'Default' => '',
      'Description' => 'Enter your Brand ID here',
    ),
    'secretKey' => array(
      'FriendlyName' => 'Secret Key',
      'Type' => 'text',
      'Size' => '25',
      'Default' => '',
      'Description' => 'Enter secret key here',
    ),
    'paymentInformation' => array(
      'FriendlyName' => 'Payment Information',
      'Type' => 'textarea',
      'Rows' => '5',
      'Description' => 'This information will be displayed on the payment page.'
    ),
    'dueStrict' => array(
      'FriendlyName' => 'Due Strict',
      'Type' => 'yesno',
      'Description' => 'Tick to enforce due strict payment timeframe',
      'Default' => 'on',
    ),
    'dueStrictTiming' => array(
      'FriendlyName' => 'Due Strict Timing',
      'Type' => 'text',
      'Size' => '3',
      'Default' => '60', // 60 minutes
      'Description' => 'Enter due strict timing. Default 60 for 1 hour.',
    ),
    'purchaseSendReceipt' => array(
      'FriendlyName' => 'Purchase Send Receipt',
      'Type' => 'yesno',
      'Description' => 'Tick to ask CHIP to send receipt upon successful payment.',
      'Default' => 'on',
    ),
    'purchaseTimeZone' => array(
      'FriendlyName' => 'Time zone',
      'Type' => 'dropdown',
      'Description' => 'Time zone setting for receipt page.',
      'Default' => 'Asia/Kuala_Lumpur',
      'Options' => $formatted_time_zones
    ),
    'updateClientInfo' => array(
      'FriendlyName' => 'Update client information',
      'Type' => 'yesno',
      'Description' => 'Tick to update client information on purchase creation.',
      'Default' => 'on',
    ),
    'systemUrlHttps' => array(
      'FriendlyName' => 'System URL Mode',
      'Type' => 'dropdown',
      'Description' => 'Choose https if you are facing issue with payment status update due to http to https redirection',
      'Options' => array(
        'default' => 'System Default',
        'https' => 'Force HTTPS',
      )
    ),
  );

  if ($show_whitelist_option) {
    $config_params['paymentWhitelist'] = array(
      'FriendlyName' => 'Payment Method Whitelisting',
      'Type' => 'yesno',
      'Description' => 'Tick to enforce payment method whitelisting.',
      'Default' => 'yes',
    );

    $config_params += $available_payment_method;
  }

  return $config_params;
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
    . '<a href="' . $params['systemurl'] . 'modules/gateways/chip_crypto_coin/redirect.php?invoiceid=' . $params['invoiceid'] . '">'
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
