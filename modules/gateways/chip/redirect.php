<?php

use WHMCS\Session;
use WHMCS\Invoice;
use WHMCS\Authentication\CurrentUser;

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/action.php';
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

if (!isset($_GET['invoiceid'])) {
  logActivity('CHIP Redirect: Missing invoiceid');
  exit;
}

$gateway_module = isset($_GET['gateway']) ? $_GET['gateway'] : 'chip';

// In redirect files, validate invoice ID
$get_invoice_id = filter_var($_GET['invoiceid'], FILTER_VALIDATE_INT);
if (!$get_invoice_id || $get_invoice_id <= 0) {
    logActivity('CHIP Redirect: Invalid invoiceid ' . $_GET['invoiceid']);
    header('Location: ' . $CONFIG['SystemURL']);
    exit;
}

$invoice = new Invoice($get_invoice_id);
$params = $invoice->getGatewayInvoiceParams();

if ($params['paymentmethod'] != $gateway_module) {
  logActivity("CHIP Redirect: Gateway mismatch. Expected $gateway_module, got " . $params['paymentmethod']);
  header('Location: ' . $params['returnurl']);
  exit;
}

// Note: https://classdocs.whmcs.com/8.0/WHMCS/Authentication/CurrentUser.html
$currentUser = new CurrentUser;
$user = $currentUser->user();
$admin = $currentUser->isAuthenticatedAdmin();

if ($admin) {
  // The request is made by admin. No further check required.
} elseif ($user) {
  // Take client() because it means to get active client for management.
  $current_user_client_id = $currentUser->client()->id;
  $param_client_id = $params['clientdetails']['client_id'];

  if ($current_user_client_id != $param_client_id) {
    logActivity('CHIP Redirect: Attempt to access other client invoice with number #' . $get_invoice_id, $current_user_client_id);
    header('Location: ' . $CONFIG['SystemURL']);
    exit;
  }
} else {
  logActivity('CHIP Redirect: Unauthenticated access attempt for invoice #' . $get_invoice_id);
  header('Location: ' . $CONFIG['SystemURL']);
  exit;
}

$phone_a = explode('.', $params['clientdetails']['phonenumberformatted']);
$phone = implode(' ', $phone_a);

$system_url = $params['systemurl'];

if ($params['systemUrlHttps'] == 'https') {
  $system_url = preg_replace("/^http:/i", "https:", $system_url);
}

$send_params = array(
  'success_callback' => $system_url . 'modules/gateways/callback/' . $gateway_module . '.php?invoiceid=' . $get_invoice_id,
  'success_redirect' => $params['returnurl'] . '&success=true',
  'failure_redirect' => $params['returnurl'],
  'cancel_redirect' => $params['returnurl'],
  'creator_agent' => 'WHMCS: ' . CHIP_MODULE_VERSION,
  'reference' => $params['invoiceid'],
  'platform' => 'whmcs',
  'send_receipt' => $params['purchaseSendReceipt'] == 'on',
  'due' => time() + (abs((int)$params['dueStrictTiming']) * 60),
  'brand_id' => $params['brandId'],
  'client' => [
    'email' => $params['clientdetails']['email'],
    'phone' => $phone,
    'full_name' => substr($params['clientdetails']['fullname'], 0, 30),
    'street_address' => substr($params['clientdetails']['address1'] . ' ' . $params['clientdetails']['address2'], 0, 128),
    'country' => $params['clientdetails']['countrycode'],
    'city' => $params['clientdetails']['city'],
    'zip_code' => $params['clientdetails']['postcode']
  ],
  'purchase' => array(
    'timezone' => $params['purchaseTimeZone'],
    'currency' => $params['currency'],
    'due_strict' => $params['dueStrict'] == 'on',
    'products' => array(
      [
        'name' => substr($params['description'], 0, 256),
        'price' => round($params['amount'] * 100),
        'quantity' => '1',
      ]
    ),
  ),
);

if (isset($params['paymentWhitelist']) and $params['paymentWhitelist'] == 'on') {
  $send_params['payment_method_whitelist'] = array();

  $keys = array_keys($params);
  $result = preg_grep('/payment_method_whitelist__.*/', $keys);

  foreach ($result as $key) {
    if ($params[$key] == 'on') {
      $key_array = explode('__', $key);
      $send_params['payment_method_whitelist'][] = end($key_array);
    }
  }
}

if (isset($params['forceTokenization']) and $params['forceTokenization'] == 'on') {
  $send_params['force_recurring'] = true;
}

logActivity("CHIP Redirect: Creating payment for Invoice #$get_invoice_id via $gateway_module");

$chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);

$get_client = $chip->get_client_by_email($params['clientdetails']['email']);

if (!empty($get_client['results']) && is_array($get_client['results'])) {
  $client = $get_client['results'][0];

  if ($params['updateClientInfo'] == 'on') {
    $chip->patch_client($client['id'], $send_params['client']);
  }
} else {
  $client = $chip->create_client($send_params['client']);
}

unset($send_params['client']);
$send_params['client_id'] = $client['id'];

$payment = $chip->create_payment($send_params);

if (!is_array($payment) || !array_key_exists('checkout_url', $payment)) {
  logActivity("CHIP Redirect: Failed to create payment for Invoice #$get_invoice_id. Response: " . json_encode($payment));
  echo "Failed to create payment. Please contact administrator.";
  exit;
}

Session::set('chip_' . $get_invoice_id, $payment['id']);
Session::set($gateway_module . '_' . $get_invoice_id, $payment['id']);

header('Location: ' . $payment['checkout_url']);

