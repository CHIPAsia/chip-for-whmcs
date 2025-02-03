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

require_once __DIR__ . '/chip_ewallets/api.php';
require_once __DIR__ . '/chip_ewallets/action.php';

function chip_ewallets_MetaData()
{
  return array(
    'DisplayName'   => 'CHIP E-Wallets',
    'APIVersion'    => '1.1',
    // Commented to allow Convert to for Processing
    // 'supportedCurrencies' => array('MYR')
  );
}

function chip_ewallets_config($params = array())
{
  $list_time_zones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

  $formatted_time_zones = array();
  foreach ($list_time_zones as $mtz) {
    $formatted_time_zones[$mtz] = str_replace("_"," ",$mtz);;
  }

  // query available payment method
  $show_whitelist_option = false;
  $show_force_token_option = false;
  $available_payment_method = array();

  if (empty($params['secretKey'] || empty($params['brandId']))) {
    // do nothing
  } else {
    $chip   = \ChipAPIEwallets::get_instance($params['secretKey'], $params['brandId']);
    // $result = $chip->payment_methods('MYR');

    // List all payment methods
    $result = [
        'available_payment_methods' => [
              "fpx",
              "fpx_b2b1",
              "duitnow_qr",
              "maestro",
              "mastercard",
              "visa",
              "razer_atome",
              "razer_grabpay",
              "razer_maybankqr",
              "razer_shopeepay",
              "razer_tng"
            ]
        ];

    if ( array_key_exists('available_payment_methods', $result) AND !empty($result['available_payment_methods'])) {
      foreach( $result['available_payment_methods'] as $apm) {
        

        // Set yes to E-wallets by default
        if (in_array($apm, ['razer_grabpay', 'razer_maybankqr', 'razer_shopeepay', 'razer_tng'])) {
          $available_payment_method['payment_method_whitelist_' . $apm] = array(
            'FriendlyName' => 'Whitelist ' . ucfirst($apm),
            'Type'         => 'yesno',
            'Default'      => 'yes',
            'Description'  => 'Tick to enable ' . ucfirst($apm),
          );
        } else {
          $available_payment_method['payment_method_whitelist_' . $apm] = array(
            'FriendlyName' => 'Whitelist ' . ucfirst($apm),
            'Type'         => 'yesno',
            // 'Default'      => 'yes',
            'Description'  => 'Tick to enable ' . ucfirst($apm),
          );
        }
      }

      $show_whitelist_option = true;
    }

    // Recurring payment methods
    // $result = $chip->payment_recurring_methods('MYR');
    $result = [
        'available_payment_methods' => [
              "maestro",
              "mastercard",
              "visa"
            ]
        ];

    if ( array_key_exists('available_payment_methods', $result) AND !empty($result['available_payment_methods'])) {
      $show_force_token_option = true;
    }
  }

  $config_params =  array(
    'FriendlyName' => array(
      'Type'  => 'System',
      'Value' => 'CHIP Ewallets',
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
      'Default'      => 'on',
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
      'Default'      => 'on',
    ),
    'purchaseTimeZone' => array(
      'FriendlyName' => 'Time zone',
      'Type'         => 'dropdown',
      'Description'  => 'Time zone setting for receipt page.',
      'Default'      => 'Asia/Kuala_Lumpur',
      'Options'      => $formatted_time_zones
    ),
    'updateClientInfo' => array(
      'FriendlyName' => 'Update client information',
      'Type'         => 'yesno',
      'Description'  => 'Tick to update client information on purchase creation.',
      'Default'      => 'on',
    ),
    'systemUrlHttps' => array(
      'FriendlyName' => 'System URL Mode',
      'Type'         => 'dropdown',
      'Description'  => 'Choose https if you are facing issue with payment status update due to http to https redirection',
      'Options'      => array(
        'default' => 'System Default',
        'https'   => 'Force HTTPS',
      )
    ),
    'additionalCharge' => array(
      'FriendlyName' => 'Additional Charges',
      'Type'         => 'yesno',
      'Description'  => 'Tick to activate additional charges.',
    ),
    'fixedCharges' => array(
      'FriendlyName' => 'Fixed Charges (cents)',
      'Type'         => 'text',
      'Size'         => '3',
      'Default'      => '0',
      'Description'  => 'Fixed charges in cents. Default to: 100. This will only be applied when additional charges are activated..',
    ),
    'percentageCharges' => array(
      'FriendlyName' => 'Percentage Charges (%)',
      'Type'         => 'text',
      'Size'         => '3',
      'Default'      => '0',
      'Description'  => 'Percentage charges. Input 100 for 1%. Default to: 0. This will only be applied when additional charges are activated.',
    ),
    'A' => array(
      'FriendlyName' => '',
      'Description'  => '',
    ),
  );

  if ($show_force_token_option) {
    $config_params['forceTokenization'] = array(
      'FriendlyName' => 'Force Tokenization',
      'Type'         => 'yesno',
      'Description'  => 'Tick to force tokenization for card payment.',
    );
  }

  if ($show_whitelist_option) {
    // logActivity('Message goes here', 0);
    $config_params['paymentWhitelist'] = array(
      'FriendlyName' => 'Payment Method Whitelisting',
      'Type'         => 'yesno',
      'Description'  => 'Tick to enforce payment method whitelisting.',
    );

    $config_params += $available_payment_method;
  }

  return $config_params;
}

function chip_ewallets_config_validate(array $params) {
  $chip   = \ChipAPIEwallets::get_instance($params['secretKey'], $params['brandId']);

//   Get all payment methods available
//   $payment_methods = $chip->payment_methods('MYR');
$payment_methods = [
    'available_payment_methods' => [
          "fpx",
          "fpx_b2b1",
          "duitnow_qr",
          "maestro",
          "mastercard",
          "visa",
          "razer_atome",
          "razer_grabpay",
          "razer_maybankqr",
          "razer_shopeepay",
          "razer_tng"
        ]
    ];

  $payment_method_configuration_error = false;
  // logActivity(print_r($params, true), 0);

  if ($params['paymentWhitelist'] == 'on') {
    $payment_method_configuration_error = true;
    $keys = array_keys($params);
    logActivity('CHIP Payment Whitelist ON', 0);
    // logActivity(getGatewayVariables('chip'));
    $result = preg_grep('/payment_method_whitelist_.*/', $keys);

    $configured_payment_methods = array();
    foreach ($result as $key) {
      if ($params[$key] == 'on') {
        $key_array = explode('_', $key);
        
        if (end($key_array) == 'b2b1') {
          $configured_payment_methods[] = 'fpx_b2b1';
        } elseif (end($key_array) == 'qr') {
          $configured_payment_methods[] = 'duitnow_qr';
        }
        else {
          $configured_payment_methods[] = end($key_array);
        }
      }
    }

    // logActivity(print_r($configured_payment_methods, true));

    // Check if configured payment methods available
    foreach ($configured_payment_methods as $cpm) {
      if (in_array($cpm, $payment_methods['available_payment_methods'])) {
        $payment_method_configuration_error = false;
        break;
      }
    }

    // logActivity(print_r($configured_payment_methods, true), 0);
  }

  // logActivity('error? - ' . $payment_method_configuration_error, 0);

  if ($payment_method_configuration_error) {
    throw new NotServicable("Invalid settings for payment method whitelisting.");
  }
}

function chip_ewallets_link($params)
{
  if ( $params['currency'] != 'MYR' ) {
    $html = '<p>The invoice was quoted in ' . $params['currency'] . ' and CHIP only accept payment in MYR.';

    if ( ClientArea::isAdminMasqueradingAsClient() ) {
      $html .= "\nAdministrator can set convert to processing MYR to enable the payment.";
    }

    return $html . '</p>';
  }

  $chip   = \ChipAPIEwallets::get_instance($params['secretKey'], $params['brandId']);
  // $payment_methods = $chip->payment_methods($params['currency']);
  $payment_methods = [
    'available_payment_methods' => [
          "fpx",
          "fpx_b2b1",
          "duitnow_qr",
          "maestro",
          "mastercard",
          "visa",
          "razer_atome",
          "razer_grabpay",
          "razer_maybankqr",
          "razer_shopeepay",
          "razer_tng"
        ]
    ];

  $payment_method_configuration_error = false;

  if ($params['paymentWhitelist'] == 'on') {
    $payment_method_configuration_error = true;
    $keys = array_keys($params);
    $result = preg_grep('/payment_method_whitelist_.*/', $keys);

    $configured_payment_methods = array();
    foreach ($result as $key) {
      if ($params[$key] == 'on') {
        $key_array = explode('_', $key);

        if (end($key_array) == 'b2b1') {
          $configured_payment_methods[] = 'fpx_b2b1';
        } elseif (end($key_array) == 'qr') {
            $configured_payment_methods[] = 'duitnow_qr';
        } else {
          $configured_payment_methods[] = end($key_array);
        }
      }
    }

    foreach ($configured_payment_methods as $cpm) {
      if (in_array($cpm, $payment_methods['available_payment_methods'])) {
        // logActivity('Payment methods pass');
        $payment_method_configuration_error = false;
        break;
      }
    }
  }

  if ($payment_method_configuration_error) {
    return '<p>Payment method whitelisting error. Please disable payment method whitelisting</p>';
  }

  if ( empty($params['secretKey']) OR empty($params['brandId']) ) {
    return '<p>Secret Key and Brand ID not set</p>';
  }

  if ( isset($_GET['success']) && !empty(Session::get('chip_ewallets_' . $params['invoiceid'])) ) {
    $payment_id = Session::getAndDelete('chip_ewallets_' . $params['invoiceid']);

    if ( \ChipActionEwallets::complete_payment($params, $payment_id) ) {
      return '<script>window.location.reload();</script>';
    }
  }

  $html = '<p>'
        . nl2br($params['paymentInformation'])
        . '<br />'
        . '<a href="' . $params['systemurl'] . 'modules/gateways/chip_ewallets/redirect.php?invoiceid=' . $params['invoiceid'] . '">'
        . '<img src="' . $params['systemurl'] . 'modules/gateways/chip_ewallets/logo.png" title="' . Lang::trans('Pay with CHIP') . '">'
        . '</a>'
        . '<br />'
        . Lang::trans('invoicerefnum')
        . ': '
        . $params['invoicenum']
        . '</p>';

  logActivity(print_r($html, true));

  return $html;
}

function chip_ewallets_refund( $params )
{
  if ($params['currency'] != 'MYR') {
    return array(
      'status'  => 'error',
      'rawdata' => 'Currency is not MYR!',
      'transid' => $params['transid'],
    );
  }

  if ($params['basecurrency'] != 'MYR') {
    return array(
      'status'  => 'error',
      'rawdata' => 'Refund for Purchase ID ' . $params['transid'] . ' needs to be done through CHIP Dashboard.',
      'transid' => $params['transid'],
    );
  }

  $chip   = \ChipAPIEwallets::get_instance($params['secretKey'], $params['brandId']);
  $result = $chip->refund_payment($params['transid'], array( 'amount' => round($params['amount']  * 100)) );

  if ( !array_key_exists( 'id', $result ) OR $result['status'] != 'success') {
    return array(
      'status'  => 'error',
      'rawdata' => json_encode($result),
      'transid' => $params['transid'],
    );
  }

  return array(
    'status'  => 'success',
    'rawdata' => json_encode($result),
    'transid' => $result['id'],
    'fees'    => $result['payment']['fee_amount'] / 100,
  );
}

function chip_ewallets_account_balance( $params )
{
  $balanceInfo = [];

  // Connect to gateway to retrieve balance information.
  $chip        = \ChipAPIEwallets::get_instance($params['secretKey'], $params['brandId']);
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

function chip_ewallets_TransactionInformation(array $params = []): Information
{
  $chip = \ChipAPIEwallets::get_instance($params['secretKey'], $params['brandId']);
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
function chip_ewallets_capture($params)
{

  logActivity('Running card capture');

  if ( $params['currency'] != 'MYR' ) {
    return array("status" => "declined", 'declinereason' => 'Unsupported currency');
  }

  $chip = \ChipAPIEwallets::get_instance($params['secretKey'], $params['brandId']);

  $get_client = $chip->get_client_by_email($params['clientdetails']['email']);
  $client = $get_client['results'][0];

  $system_url = $params['systemurl'];

  if ($params['systemUrlHttps'] == 'https') {
    $system_url = preg_replace("/^http:/i", "https:", $system_url);
  }

  // If additional charge is on, then
  if ($params['additionalCharge']) {
    
    $total_charge = 0;

    // Check if both are set 

    // Check if fixed or percentage
    if ( $params['fixedCharges'] > 0 ) {
      // Add amount with fixed charge (RM1.00 = 100)
      $charge = ($params['fixedCharges'] / 100);
      $total_charge = $total_charge + $charge;
    }

    if ( $params['percentageCharges'] > 0 ) {
      // 1% = 100
      $charge = $params['amount'] * ( $params['percentageCharges'] / 100 ) / 100;
      $total_charge = $total_charge + $charge;
    }

    $params['amount'] = $params['amount'] + $total_charge;
  }

  $purchase_params = array(
    'success_callback' => $system_url . 'modules/gateways/callback/chip_ewallets.php?capturecallback=true&invoiceid=' . $params['invoiceid'],
    'creator_agent'    => 'WHMCS: 1.2.0',
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

  // this to prevent from callback being run in 10 seconds from now
  Capsule::select("SELECT GET_LOCK('chip_ewallets_payment_$payment_id', 10);");

  $account = Capsule::table('tblaccounts')
      ->where('transid', $payment_id)
      ->take(1)
      ->first();

  if ($account) {
    return 'success';
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

function chip_ewallets_nolocalcc() {
  // this method must exists to hide card credit input displaying in checkout page
}

function chip_ewallets_storeremote($params) {
  $action = $params['action'];
  $token = $params['gatewayid'];

  switch ($action) {
    case 'delete':
      $chip = \ChipAPIEwallets::get_instance($params['secretKey'], '');
      $chip->delete_token($token);
    break;
  }

  return [
    'status' => 'success',
  ];
}

function chip_ewallets_adminstatusmsg($params) {
  return false;
}

function chip_ewallets_deactivate() {
  // remove database table. but make it remains commented
}
