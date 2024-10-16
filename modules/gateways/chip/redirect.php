<?php

use WHMCS\Session;
use WHMCS\Invoice;
use WHMCS\Authentication\CurrentUser;

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/action.php';
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

if ( !isset($_GET['invoiceid']) ) {
  exit;
}

$get_invoice_id  = intval($_GET['invoiceid']);

if ( empty($get_invoice_id) ) {
  header( 'Location: ' . $CONFIG['SystemURL'] );
  exit;
}

$invoice = new Invoice($get_invoice_id);
$params  = $invoice->getGatewayInvoiceParams();

$currentUser = new CurrentUser;
$user = $currentUser->user();
$admin = $currentUser->isAuthenticatedAdmin();

if ($admin) {
  // The request is made by admin. No further check required.
} elseif($user) {
  $current_user_client_id = $currentUser->client()->id;
  $param_client_id = $params['clientdetails']['client_id'];

  if ($current_user_client_id != $param_client_id) {
    exit('Invoice belongs to other client');
  }
} else {
  exit('Invalid invoice permission');
}

if ( $params['paymentmethod'] != 'chip' ) {
  header( 'Location: ' . $params['returnurl'] );
}

$phone_a = explode('.', $params['clientdetails']['phonenumberformatted']);
$phone = implode(' ', $phone_a);

$system_url = $params['systemurl'];

if ($params['systemUrlHttps'] == 'https') {
  $system_url = preg_replace("/^http:/i", "https:", $system_url);
}

$send_params = array(
  'success_callback' => $system_url . 'modules/gateways/callback/chip.php?invoiceid=' . $get_invoice_id,
  'success_redirect' => $params['returnurl'] . '&success=true',
  'failure_redirect' => $params['returnurl'],
  'cancel_redirect'  => $params['returnurl'],
  'creator_agent'    => 'WHMCS: 1.3.0',
  'reference'        => $params['invoiceid'],
  'platform'         => 'whmcs',
  'send_receipt'     => $params['purchaseSendReceipt'] == 'on',
  'due'              => time() + (abs( (int)$params['dueStrictTiming'] ) * 60),
  'brand_id'         => $params['brandId'],
  'client'           => [
    'email'          => $params['clientdetails']['email'],
    'phone'          => $phone,
    'full_name'      => substr($params['clientdetails']['fullname'], 0, 30),
    'street_address' => substr($params['clientdetails']['address1'] . ' ' . $params['clientdetails']['address2'], 0, 128),
    'country'        => $params['clientdetails']['countrycode'],
    'city'           => $params['clientdetails']['city'],
    'zip_code'       => $params['clientdetails']['postcode']
  ],
  'purchase'         => array(
    'timezone'   => $params['purchaseTimeZone'],
    'currency'   => $params['currency'],
    'due_strict' => $params['dueStrict'] == 'on',
    'products'   => array([
      'name'     => substr($params['description'], 0, 256),
      'price'    => round($params['amount'] * 100),
      'quantity' => '1',
    ]),
  ),
);

if (isset($params['paymentWhitelist']) AND $params['paymentWhitelist'] == 'on') {
  $send_params['payment_method_whitelist'] = array();

  $keys = array_keys($params);
  $result = preg_grep('/payment_method_whitelist_.*/', $keys);


  foreach ($result as $key) {
    if ($params[$key] == 'on') {
      $key_array = explode('_', $key);

      if (end($key_array) == 'b2b1') {
        $send_params['payment_method_whitelist'][] = 'fpx_b2b1';
      } else {
        $send_params['payment_method_whitelist'][] = end($key_array);
      }
    }
  }
}

if (isset($params['forceTokenization']) AND $params['forceTokenization'] == 'on') {
  $send_params['force_recurring'] = true;
}

$chip    = \ChipAPI::get_instance( $params['secretKey'], $params['brandId'] );

$get_client = $chip->get_client_by_email($params['clientdetails']['email']);

if (array_key_exists('__all__', $get_client)) {
  throw new Exception( 'Failed to create purchase. Errors: ' . print_r($get_client, true) ) ;
}

if (is_array($get_client['results']) AND !empty($get_client['results'])) {
  $client = $get_client['results'][0];

  if ($params['updateClientInfo'] == 'on') {
    $chip->patch_client($client['id'], $send_params['client']);
  }
} else {
  $client = $chip->create_client($send_params['client']);
}

unset($send_params['client']);
$send_params['client_id'] = $client['id'];

$payment = $chip->create_payment( $send_params );

if ( !array_key_exists('id', $payment) ) {
  throw new Exception( 'Failed to create purchase. Errors: ' . print_r($payment, true) ) ;
}

Session::set( 'chip_' . $params['invoiceid'], $payment['id'] );

header( 'Location: ' . $payment['checkout_url'] );
