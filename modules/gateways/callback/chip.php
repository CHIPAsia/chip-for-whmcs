<?php

use WHMCS\Invoice;

require_once __DIR__ . '/../chip/api.php';
require_once __DIR__ . '/../chip/action.php';
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

if ( !isset($_GET['invoiceid']) ) {
  exit;
}

if ( empty($content = file_get_contents('php://input')) ) {
  exit;
}

if ( !isset($_SERVER['HTTP_X_SIGNATURE']) ) {
  exit;
}

if ( empty($get_invoice_id = intval($_GET['invoiceid'])) ) {
  exit;
}

$invoice = new Invoice($get_invoice_id);
$params  = $invoice->getGatewayInvoiceParams();

if ( \openssl_verify( $content,  \base64_decode($_SERVER['HTTP_X_SIGNATURE']), \ChipAction::retrieve_public_key($params), 'sha256WithRSAEncryption' ) != 1 ) {
  \header( 'Forbidden', true, 403 );
  exit;
}

$payment = \json_decode($content, true);

if ($payment['status'] != 'paid') {
  exit;
}

\ChipAction::complete_payment($params, $payment);