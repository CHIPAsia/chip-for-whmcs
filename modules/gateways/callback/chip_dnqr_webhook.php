<?php

require_once __DIR__ . '/../chip/api.php';
require_once __DIR__ . '/../chip_dnqr/action.php';
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');
// App::load_function('cc');

if ( empty($content = file_get_contents('php://input')) ) {
  die('Copy this page URL to CHIP Collect dashboard --> Webhooks');
}

if ( !isset($_SERVER['HTTP_X_SIGNATURE']) ) {
  die('No X Signature received from headers');
}

$webhook = \json_decode($content, true);
$event_type = $webhook['event_type'];

if (!in_array($event_type, ['payment.refunded', 'purchase.recurring_token_deleted'])) {
  die('No supported event type');
}

$gatewayParams = getGatewayVariables('chip_dnqr');

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

if ( \openssl_verify( $content,  \base64_decode($_SERVER['HTTP_X_SIGNATURE']), $gatewayParams['publicKey'], 'sha256WithRSAEncryption' ) != 1 ) {
  \header( 'Forbidden', true, 403 );
  die('Invalid X Signature');
}

switch($event_type) {
  case 'payment.refunded': 
    /*
      The problem with refundInvoicePayment function is the transaction fees is inaccurate.
      This happened due to the fact refundInvoicePayment function does not accept
      fees in the passing parameter and instead it get from previous payment.
    */
  break;
  
  case 'purchase.recurring_token_deleted':
    /*
      The problem with deleting token in whmcs from webhooks is the token
      is stored in an encrypted format where it is not possible to lookup from database.

      It will a performance issue to iterate each records considering the records will
      only grows overtime
    */
  break;
}

echo 'Done';