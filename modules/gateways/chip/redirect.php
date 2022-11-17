<?php

use WHMCS\Session;
use WHMCS\Invoice;

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/action.php';
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

if ( !isset($_GET['invoiceid']) ) {
  exit;
}

$chip_invoice_id = intval(Session::getAndDelete( 'chip_invoice_id' ));
$get_invoice_id  = intval($_GET['invoiceid']);

if ( empty($chip_invoice_id) || empty($get_invoice_id) ) {
  header( 'Location: ' . $CONFIG['SystemURL'] );
  exit;
}

if ( $get_invoice_id != $chip_invoice_id ) {
  header( 'Location: ' . $CONFIG['SystemURL'] . '/viewinvoice.php?id=' . $get_invoice_id );
  exit;
}

$invoice = new Invoice($chip_invoice_id);
$params  = $invoice->getGatewayInvoiceParams();

if ( $params['paymentmethod'] != 'chip' ) {
  header( 'Location: ' . $params['returnurl'] );
}

$send_params = array(
  'success_callback' => $params['systemurl'] . '/modules/gateways/callback/chip.php?invoiceid=' . $get_invoice_id,
  'success_redirect' => $params['returnurl'] . '&success=true',
  'failure_redirect' => $params['returnurl'],
  'creator_agent'    => 'WHMCS: 1.0.1',
  'reference'        => $params['invoiceid'],
  'platform'         => 'whmcs',
  'send_receipt'     => $params['purchaseSendReceipt'] == 'on',
  'due'              => time() + (abs( (int)$params['dueStrictTiming'] ) * 60),
  'brand_id'         => $params['brandId'],
  'client'           => [
    'email'          => $params['clientdetails']['email'],
    'phone'          => $params['clientdetails']['phonenumber'],
    'full_name'      => substr($params['clientdetails']['fullname'], 0, 30),
    'street_address' => substr($params['clientdetails']['address1'] . ' ' . $params['clientdetails']['address2'], 0, 128),
    'country'        => $params['clientdetails']['countrycode'],
    'city'           => $params['clientdetails']['city'],
    'zip_code'       => $params['clientdetails']['postcode']
  ],
  'purchase'         => array(
    'timezone'   => date_default_timezone_get(),
    'currency'   => $params['currency'],
    'due_strict' => $params['dueStrict'] == 'on',
    'products'   => array([
      'name'     => substr($params['description'], 0, 256),
      'price'    => round($params['amount'] * 100),
      'quantity' => '1',
    ]),
  ),
);

$chip    = \ChipAPI::get_instance( $params['secretKey'], $params['brandId'] );
$payment = $chip->create_payment( $send_params );

if ( !array_key_exists('id', $payment) ) {
  throw new Exception( 'Failed to create purchase. Errors: ' . print_r($payment, true) ) ;
}

Session::set( 'chip_' . $params['invoiceid'], $payment['id'] );

header( 'Location: ' . $payment['checkout_url'] );