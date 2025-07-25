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
require_once __DIR__ . '/chip_dnqr/action.php';

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
  $list_time_zones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

  $formatted_time_zones = array();
  foreach ($list_time_zones as $mtz) {
    $formatted_time_zones[$mtz] = str_replace("_", " ", $mtz);
    ;
  }

  // query available payment method
  $show_whitelist_option = false;
  $show_force_token_option = false;
  $available_payment_method = array();

  if (empty($params['secretKey'] || empty($params['brandId']))) {
    // do nothing
  } else {
    $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
    // $result = $chip->payment_methods('MYR');

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
        'mpgs_google_pay'
      ]
    ];

    if (is_array($result) && array_key_exists('available_payment_methods', $result) and !empty($result['available_payment_methods'])) {
      foreach ($result['available_payment_methods'] as $apm) {


        // Set yes to DNQR by default
        if ($apm == 'duitnow_qr') {
          $available_payment_method['payment_method_whitelist__' . $apm] = array(
            'FriendlyName' => 'Whitelist ' . ucfirst($apm),
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Tick to enable ' . ucfirst($apm),
          );
        } else {
          $available_payment_method['payment_method_whitelist__' . $apm] = array(
            'FriendlyName' => 'Whitelist ' . ucfirst($apm),
            'Type' => 'yesno',
            'Description' => 'Tick to enable ' . ucfirst($apm),
          );
        }
      }

      $show_whitelist_option = true;
    }

    $result = $chip->payment_recurring_methods('MYR');

    if (is_array($result) && array_key_exists('available_payment_methods', $result) and !empty($result['available_payment_methods'])) {
      $show_force_token_option = true;
    }
  }

  $config_params = array(
    'FriendlyName' => array(
      'Type' => 'System',
      'Value' => 'Duitnow QR (E-Wallet: MAE, GrabPay, ShopeePay, TnG)',
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
    'A' => array(
      'FriendlyName' => '',
      'Description' => '',
    ),
  );

  if ($show_force_token_option) {
    $config_params['forceTokenization'] = array(
      'FriendlyName' => 'Force Tokenization',
      'Type' => 'yesno',
      'Description' => 'Tick to force tokenization for card payment.',
    );
  }

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

function chip_dnqr_config_validate(array $params)
{
}

function chip_dnqr_link($params)
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

  if (isset($_GET['success']) && !empty(Session::get('chip_dnqr_' . $params['invoiceid']))) {
    $payment_id = Session::getAndDelete('chip_dnqr_' . $params['invoiceid']);

    if (\ChipActionDNQR::complete_payment($params, $payment_id)) {
      return '<script>window.location.reload();</script>';
    }
  }

  $html = '<p>'
    . nl2br($params['paymentInformation'])
    . '<br />'
    . '<a href="' . $params['systemurl'] . 'modules/gateways/chip_dnqr/redirect.php?invoiceid=' . $params['invoiceid'] . '">'
    . '<img height="44px" src="' . $params['systemurl'] . 'modules/gateways/chip_dnqr/paywithdnqr.png" title="' . Lang::trans('Pay with Duitnow QR') . '">'
    . '</a>'
    . '<br />'
    . Lang::trans('invoicerefnum')
    . ': '
    . $params['invoicenum']
    . '</p>';

  return $html;
}

function chip_dnqr_refund($params)
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

function chip_dnqr_account_balance($params)
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

  //... splat operator. it will explode the array and send it as individual variable
  return BalanceCollection::factoryFromItems(...$balanceInfo);
}

function chip_dnqr_TransactionInformation(array $params = []): Information
{
  $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
  $payment = $chip->get_payment($params['transactionId']);
  $information = new Information();

  if (!is_array($payment) || array_key_exists('__all__', $payment)) {
    return $information;
  }

  $payment_fee = 0;
  foreach ($payment['transaction_data']['attempts'] as $attempt) {
    if (in_array($attempt['type'], ['execute', 'recurring_execute']) and $attempt['successful'] and empty($attempt['error'])) {
      $payment_fee = $attempt['fee_amount'];
      break;
    }
  }

  $currency = WHMCS\Billing\Currency::where("code", $payment['payment']['currency'])->firstOrFail();

  return $information
    ->setTransactionId($payment['id'])
    ->setAmount($payment['payment']['amount'] / 100, $currency)
    ->setCurrency($currency)
    ->setFeeCurrency($currency)
    ->setMerchantCurrency($currency)
    ->setMerchantAmount(($payment['payment']['amount'] - $payment_fee) / 100, $currency)
    ->setType($payment['type'])
    ->setAdditionalDatum('chip_paid_on', Carbon::createFromTimestampUTC($payment['payment']['paid_on'])->setTimezone('Asia/Kuala_Lumpur')->format('d/m/Y H:i'))
    ->setCreated(Carbon::createFromTimestampUTC($payment['created_on'])->setTimezone('Asia/Kuala_Lumpur'))
    ->setDescription($payment['payment']['description'])
    ->setFee($payment_fee / 100)
    ->setStatus($payment['status']);
}

// $params = https://pastebin.com/vz16pSJV
function chip_dnqr_capture($params)
{
  if ($params['currency'] != 'MYR') {
    return array("status" => "declined", 'declinereason' => 'Unsupported currency');
  }

  $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);

  $get_client = $chip->get_client_by_email($params['clientdetails']['email']);
  $client = $get_client['results'][0];

  $system_url = $params['systemurl'];

  if ($params['systemUrlHttps'] == 'https') {
    $system_url = preg_replace("/^http:/i", "https:", $system_url);
  }

  $purchase_params = array(
    'success_callback' => $system_url . 'modules/gateways/callback/chip_dnqr.php?capturecallback=true&invoiceid=' . $params['invoiceid'],
    'creator_agent' => 'WHMCS: 1.5.1',
    'reference' => $params['invoiceid'],
    'client_id' => $client['id'],
    'platform' => 'whmcs',
    'send_receipt' => $params['purchaseSendReceipt'] == 'on',
    'due' => time() + (abs((int)$params['dueStrictTiming']) * 60),
    'brand_id' => $params['brandId'],
    'purchase' => array(
      'timezone' => $params['purchaseTimeZone'],
      'currency' => $params['currency'],
      'due_strict' => $params['dueStrict'] == 'on',
      'products' => array(
        [
          'name' => substr($params['description'], 0, 256),
          'price' => round($params['amount'] * 100),
        ]
      ),
    ),
  );

  $create_payment = $chip->create_payment($purchase_params);

  $charge_payment = $chip->charge_payment($create_payment['id'], array('recurring_token' => $params["gatewayid"]));

  $payment_id = $create_payment['id'];

  // this to prevent from callback being run in 10 seconds from now
  Capsule::select("SELECT GET_LOCK('chip_dnqr_payment_$payment_id', 10);");

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

function chip_dnqr_nolocalcc()
{
  // this method must exists to hide card credit input displaying in checkout page
}

function chip_dnqr_storeremote($params)
{
  $action = $params['action'];
  $token = $params['gatewayid'];

  switch ($action) {
    case 'delete':
      $chip = \ChipAPI::get_instance($params['secretKey'], '');
      $chip->delete_token($token);
      break;
  }

  return [
    'status' => 'success',
  ];
}

function chip_dnqr_adminstatusmsg($params)
{
  return false;
}

function chip_dnqr_deactivate()
{
  // remove database table. but make it remains commented
}
