<?php

use WHMCS\ClientArea;
use WHMCS\Session;

use WHMCS\Module\Gateway\Balance;
use WHMCS\Module\Gateway\BalanceCollection;

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

require_once __DIR__ . '/chip/api.php';
require_once __DIR__ . '/chip/action.php';

function chip_MetaData()
{
  return array(
    'DisplayName'                 => 'CHIP',
    'APIVersion'                  => '1.1',
    'DisableLocalCreditCardInput' => true,
    'TokenisedStorage'            => false,
  );
}

function chip_config()
{
  return array(
    'FriendlyName' => array(
      'Type'  => 'System',
      'Value' => 'CHIP',
    ),
    'brandId' => array(
      'FriendlyName' => 'Brand ID',
      'Type'         => 'text',
      'Size'         => '25',
      'Default'      => '',
      'Description'  => 'Enter your Brand ID here',
    ),
    'secretKey' => array(
      'FriendlyName' => 'Secret Key',
      'Type'         => 'text',
      'Size'         => '25',
      'Default'      => '',
      'Description'  => 'Enter secret key here',
    ),
    'paymentInformation' => array(
      'FriendlyName' => 'Payment Information',
      'Type' => 'textarea',
      'Rows' => '5',
      'Description' => 'This information will be displayed on the payment page.'
    ),
    'dueStrict' => array(
      'FriendlyName' => 'Due Strict',
      'Type'         => 'yesno',
      'Description'  => 'Tick to enforce due strict payment timeframe',
    ),
    'dueStrictTiming' => array(
      'FriendlyName' => 'Due Strict Timing',
      'Type'         => 'text',
      'Size'         => '3',
      'Default'      => '60', // 60 minutes
      'Description'  => 'Enter due strict timing. Default 60 for 1 hour.',
    ),
    'purchaseSendReceipt' => array(
      'FriendlyName' => 'Purchase Send Receipt',
      'Type'         => 'yesno',
      'Description'  => 'Tick to ask CHIP to send receipt upon successful payment.',
    ),
  );
}

function chip_link($params)
{
  if ( $params['currency'] != 'MYR' ) {
    $html = '<p>The invoice was quoted in ' . $params['currency'] . ' and CHIP only accept payment in MYR.';

    if ( ClientArea::isAdminMasqueradingAsClient() ) {
      $html .= "\nAdministrator can set convert to processing MYR to enable the payment.";
    }

    return $html . '</p>';
  }

  if ( empty($params['secretKey']) OR empty($params['brandId']) ) {
    return '<p>Secret Key and Brand ID not set';
  }

  if ( isset($_GET['success']) && $_GET['success'] == 'true' && !empty(Session::get('chip_' . $params['invoiceid'])) ) {
    $payment_id = Session::getAndDelete('chip_' . $params['invoiceid']);
    
    if ( \ChipAction::complete_payment($params, $payment_id) ) {
      return '<script>window.location.reload();</script>';
    }
  }

  Session::set( 'chip_invoice_id' , $params['invoiceid'] );

  $html = '<p>'
        . nl2br($params['paymentInformation'])
        . '<br />'
        . '<a href="' . $params['systemurl'] . '/modules/gateways/chip/redirect.php?invoiceid=' . $params['invoiceid'] . '">'
        . '<img src="' . $params['systemurl'] . '/modules/gateways/chip/logo.png" title="' . Lang::trans('Pay with CHIP') . '">'
        . '</a>'
        . '<br />'
        . Lang::trans('invoicerefnum')
        . ': '
        . $params['invoicenum']
        . '</p>';

  return $html;
}

function chip_refund( $params )
{
  $chip   = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
  $result = $chip->refund_payment($params['transid'], array( 'amount' => round($params['amount']  * 100)) );

  if ( !array_key_exists('id', $result) ) {
    return array(
      'status'  => 'error',
      'rawdata' => json_encode($result),
      'transid' => '',
    );
  }

  return array(
    'status'  => 'success',
    'rawdata' => json_encode($result),
    'transid' => $result['id'],
    // 'fees' => number_format($result['payment']['fee_amount'] / 100, 2),
  );
}

function chip_account_balance( $params )
{
  $balanceInfo = [];

  // Connect to gateway to retrieve balance information.
  $chip        = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
  $balanceData = $chip->account_balance();

  foreach( $balanceData as $currency => $value ) {
    $balanceInfo[] = Balance::factory(
      ($value['balance'] / 100),
      $currency
    );
  }

  //... splat operator. it will explode the array and send it as individual variable
  return BalanceCollection::factoryFromItems(...$balanceInfo );
}