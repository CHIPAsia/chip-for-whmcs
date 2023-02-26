<?php

use WHMCS\Database\Capsule;
use WHMCS\User\Client;
use WHMCS\Billing\Invoice;
use WHMCS\Payment\PayMethod\Adapter\RemoteCreditCard;
use WHMCS\Module\Gateway;
use WHMCS\Carbon;
use WHMCS\Exception\Module\NotServicable;
use WHMCS\Config\Setting as WHMCSSetting;

class ChipAction {
  public static function complete_payment( $params, $payment ) {
    if (\is_array($payment)) { // success callback
      $payment_id = $payment['id'];
    } elseif (\is_string($payment)) { // success redirect
      $chip       = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
      $payment_id = $payment;
      $payment    = $chip->get_payment($payment);
    } else {
      return false;
    }

    if ( $payment['status'] != 'paid' ) {
      return false;
    }

    // https://whmcs.community/topic/296566-raw-complex-query/
    Capsule::select("SELECT GET_LOCK('chip_payment_$payment_id', 15);");

    $account = Capsule::table('tblaccounts')
      ->where('transid', $payment_id)
      ->take(1)
      ->first();

    if ($account) {
      return true;
    }

    $transaction_currency = Capsule::table("tblcurrencies")->where("code", "=", strtoupper($payment['payment']['currency']))->first(array("id"));
    $client = Client::find($params["clientdetails"]["id"]);
    $transactionFee = convertCurrency($payment['transaction_data']['attempts'][0]['fee_amount'] / 100, $transaction_currency->id, $client->currencyId);
    $payment_amount = convertCurrency($payment['payment']['amount'] / 100, $transaction_currency->id, $client->currencyId);

    $invoice = Invoice::findOrFail($params['invoiceid']);
    $amount = $invoice->balance;

    if ($payment_amount != $amount) {
      throw new NotServicable("Invoice Amount Invalid");
    }

    \addInvoicePayment(
      $params['invoiceid'],
      $payment_id,
      $payment_amount,
      $transactionFee,
      $params['paymentmethod']
    );

    Capsule::select("SELECT RELEASE_LOCK('chip_payment_$payment_id');");

    \logTransaction( $params['name'], $payment, $payment['status'] );

    if ($payment['is_recurring_token']) {
      $payMethod = RemoteCreditCard::factoryPayMethod($client, $client->billingContact);
      $gateway = Gateway::factory("chip");
      $payMethod->setGateway($gateway);
      $payMethod_payment = $payMethod->payment;
      $masked_pan = $payment['transaction_data']['extra']['masked_pan'];

      $payMethod_payment->setCardNumber($card_number);
      $payMethod_payment->setLastFour(substr($masked_pan, -4));
      $expiry_date = sprintf("%02d", $payment['transaction_data']['extra']['expiry_month']).'/'.$payment['transaction_data']['extra']['expiry_year'];
      $payMethod_payment->setCardType(ucwords($payment['transaction_data']['extra']['card_brand']));
      $payMethod_payment->setExpiryDate(Carbon::createFromCcInput($expiry_date));
      $payMethod_payment->setRemoteToken($payment['id']);
      $payMethod_payment->save();
      $payMethod->save();
    }

    return true;
  }

  public static function retrieve_public_key($params) {
    $ten_secret_key = substr($params['secretKey'], 0, 10);

    if ( $public_key = WHMCSSetting::getValue("CHIP_PUBLIC_KEY_" . $ten_secret_key) ) {
      return $public_key;
    }

    $chip       = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
    $public_key = \str_replace( '\n', "\n", $chip->public_key() );

    WHMCSSetting::setValue( "CHIP_PUBLIC_KEY_" . $ten_secret_key, $public_key );

    return $public_key;
  }

  public static function clean_up_public_key() {
    require __DIR__ . '/../../../init.php';
    WHMCSSetting::where('setting', 'LIKE', "CHIP_PUBLIC_KEY_%")->delete();

    echo 'Public Key clean up success';
  }
}

if (isset($_GET['clean_up_public_key'])) {
  ChipAction::clean_up_public_key();
}