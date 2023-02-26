<?php

use WHMCS\ClientArea;
use WHMCS\Session;

use WHMCS\Module\Gateway\Balance;
use WHMCS\Module\Gateway\BalanceCollection;

use WHMCS\Billing\Payment\Transaction\Information;
use WHMCS\Carbon;

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

require_once __DIR__ . '/chip/api.php';
require_once __DIR__ . '/chip/action.php';

function chip_MetaData()
{
  return array(
    'DisplayName'   => 'CHIP',
    'APIVersion'    => '1.1',
    'supportedCurrencies' => array('MYR')
  );
}

function chip_config()
{
  $modified_time_zones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

  if (($key = array_search('Asia/Kuala_Lumpur', $modified_time_zones)) !== false) {
    unset($modified_time_zones[$key]);
    array_unshift($modified_time_zones, 'Asia/Kuala_Lumpur');
  }

  $time_zones = array();
  foreach ($modified_time_zones as $mtz) {
    $time_zones[$mtz] = $mtz;
  }

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
    'purchaseTimeZone' => array(
      'FriendlyName' => 'Time zone',
      'Type'         => 'dropdown',
      'Description'  => 'Tick to ask CHIP to send receipt upon successful payment.',
      'Options' => $time_zones
    ),
    'updateClientInfo' => array(
      'FriendlyName' => 'Update client information',
      'Type'         => 'yesno',
      'Description'  => 'Tick to update client information on purchase creation.',
    ),
    'A' => array(
      'FriendlyName' => '',
      'Description'  => '',
    ),
    'forceTokenization' => array(
      'FriendlyName' => 'Force Tokenization',
      'Type'         => 'yesno',
      'Description'  => 'Tick to force tokenization for card payment.',
    ),
    'paymentWhitelist' => array(
      'FriendlyName' => 'Payment Method Whitelisting',
      'Type'         => 'yesno',
      'Description'  => 'Tick to enforce payment method whitelisting.',
    ),
    'paymentWhiteVisa' => array(
      'FriendlyName' => 'Whitelist Visa',
      'Type'         => 'yesno',
      'Description'  => 'Tick to enable Visa card.',
    ),
    'paymentWhiteMaster' => array(
      'FriendlyName' => 'Whitelist Mastercard',
      'Type'         => 'yesno',
      'Description'  => 'Tick to enable Mastercard.',
    ),
    'paymentWhiteFpxb2c' => array(
      'FriendlyName' => 'Whitelist FPX B2C',
      'Type'         => 'yesno',
      'Description'  => 'Tick to enable FPX B2C.',
    ),
    'paymentWhiteFpxb2b1' => array(
      'FriendlyName' => 'Whitelist FPX B2B1',
      'Type'         => 'yesno',
      'Description'  => 'Tick to enable FPX B2B1.',
    ),
    'B' => array(
      'FriendlyName' => '',
      'Description'  => '',
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
  if ($params['currency'] != 'MYR') {
    return array();
  }

  $chip   = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
  $result = $chip->refund_payment($params['transid'], array( 'amount' => round($params['amount']  * 100)) );

  if ( array_key_exists('__all__', $result) ) {
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
    'fees'    => $result['payment']['fee_amount'] / 100,
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

function chip_TransactionInformation(array $params = []): Information
{
  $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
  $payment = $chip->get_payment($params['transactionId']);
  $information = new Information();

  if (array_key_exists('__all__', $payment)) {
    return $information;
  }

  $payment_fee = 0;
  foreach($payment['transaction_data']['attempts'] as $attempt) {
    if (in_array($attempt['type'], ['execute', 'recurring_execute']) AND $attempt['successful'] AND empty($attempt['error'])) {
      $payment_fee = $attempt['fee_amount'];
      break;
    }
  }

  return $information
        ->setTransactionId($payment['id'])
        ->setAmount($payment['payment']['amount'] / 100)
        ->setCurrency($payment['payment']['currency'])
        ->setType($payment['type'])
        ->setAvailableOn(Carbon::parse($payment['paid_on']))
        ->setCreated(Carbon::parse($payment['created_on']))
        ->setDescription($payment['payment']['description'])
        ->setFee($payment_fee / 100)
        ->setStatus($payment['status']);
}

// $params = https://pastebin.com/vz16pSJV
function chip_capture($params)
{
  if ( $params['currency'] != 'MYR' ) {
    return array("status" => "declined", 'declinereason' => 'Unsupported currency');
  }

  $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);

  $get_client = $chip->get_client_by_email($params['clientdetails']['email']);
  $client = $get_client['results'][0];

  $purchase_params = array(
    'success_callback' => $params['systemurl'] . '/modules/gateways/callback/chip.php?invoiceid=' . $params['invoiceid'],
    'creator_agent'    => 'WHMCS: 1.1.0',
    'reference'        => $params['invoiceid'],
    'client_id'        => $client['id'],
    'platform'         => 'whmcs',
    'send_receipt'     => $params['purchaseSendReceipt'] == 'on',
    'due'              => time() + (abs( (int)$params['dueStrictTiming'] ) * 60),
    'brand_id'         => $params['brandId'],
    'purchase'         => array(
      'timezone'   => $params['purchaseTimeZone'],
      'currency'   => $params['currency'],
      'due_strict' => $params['dueStrict'] == 'on',
      'products'   => array([
        'name'     => substr($params['description'], 0, 256),
        'price'    => round($params['amount'] * 100),
      ]),
    ),
  );

  $create_payment = $chip->create_payment( $purchase_params );

  $charge_payment = $chip->charge_payment($create_payment['id'], array('recurring_token' => $params["gatewayid"]));

  $payment_id = $create_payment['id'];

  Capsule::select("SELECT GET_LOCK('chip_payment_$payment_id', 10);");

  $account = Capsule::table('tblaccounts')
      ->where('transid', $payment_id)
      ->take(1)
      ->first();

  if ($account) {
    return array();
  }

  if ($charge_payment['status'] == 'paid') {
    return array("status" => "success", "transid" => $create_payment['id'], "rawdata" => $charge_payment, 'fee' => $charge_payment['transaction_data']['attempts'][0]['fee_amount'] / 100);
  } elseif ($charge_payment['status'] == 'pending_charge') {
    return array("status" => "pending", "transid" => $create_payment['id'], "rawdata" => $charge_payment);
  }

  return array("status" => "declined", "transid" => $create_payment['id'], "rawdata" => $charge_payment);
}

/**
 * Log activity.
 *
 * @param string $message The message to log
 * @param int $userId An optional user id to which the log entry relates
 */
// logActivity('Message goes here', 0);