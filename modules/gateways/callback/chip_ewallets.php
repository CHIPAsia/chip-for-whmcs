<?php

require_once __DIR__ . '/../chip/api.php';
require_once __DIR__ . '/../chip_ewallets/action.php';
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

if (!isset($_GET['invoiceid'])) {
  die('No invoiceid parameter is set');
}

if (empty($content = file_get_contents('php://input'))) {
  die('No input received');
}

if (!isset($_SERVER['HTTP_X_SIGNATURE'])) {
  die('No X Signature received from headers');
}

// In redirect files, validate invoice ID
$get_invoice_id = filter_var($_GET['invoiceid'], FILTER_VALIDATE_INT);
if (!$get_invoice_id || $get_invoice_id <= 0) {
    die('Invalid invoice ID');
}

$gatewayParams = getGatewayVariables('chip_ewallets');

if (!$gatewayParams['type']) {
  die('Module Not Activated');
}

$invoice = new WHMCS\Invoice($get_invoice_id);
$params = $invoice->getGatewayInvoiceParams();

if (\openssl_verify($content, \base64_decode($_SERVER['HTTP_X_SIGNATURE']), \ChipActionEwallets::retrieve_public_key($params), 'sha256WithRSAEncryption') != 1) {
  \header('Forbidden', true, 403);
  die('Invalid X Signature');
}

$payment = \json_decode($content, true);

if ($payment['status'] != 'paid') {
  die('Status is not paid');
}

\ChipActionEwallets::complete_payment($params, $payment);

echo 'Done';