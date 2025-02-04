<?php

require_once __DIR__ . '/../chip_cards/api.php';
require_once __DIR__ . '/../chip_cards/action.php';
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

if ( !isset($_GET['invoiceid']) ) {
  die('No invoiceid parameter is set');
}

if ( empty($content = file_get_contents('php://input')) ) {
  die('No input received');
}

if ( !isset($_SERVER['HTTP_X_SIGNATURE']) ) {
  die('No X Signature received from headers');
}

if ( empty($get_invoice_id = intval($_GET['invoiceid'])) ) {
  die('invoiceid parameter is empty');
}

$gatewayParams = getGatewayVariables('chip_cards');

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$invoice = new WHMCS\Invoice($get_invoice_id);
$params  = $invoice->getGatewayInvoiceParams();

if ( \openssl_verify( $content,  \base64_decode($_SERVER['HTTP_X_SIGNATURE']), \ChipActionCards::retrieve_public_key($params), 'sha256WithRSAEncryption' ) != 1 ) {
  \header( 'Forbidden', true, 403 );
  die('Invalid X Signature');
}

$payment = \json_decode($content, true);

if ($payment['status'] != 'paid') {
  die('Status is not paid');
}

\ChipActionCards::complete_payment($params, $payment);

echo 'Done';