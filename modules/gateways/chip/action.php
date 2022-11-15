<?php

use WHMCS\Database\Capsule;
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

    Capsule::beginTransaction();

    $account = Capsule::table('tblaccounts')
      ->where('transid', $payment_id)
      ->take(1)
      ->lockForUpdate()
      ->first();

    if ($account) {
      Capsule::commit();
      return true;
    }

    \addInvoicePayment(
      $params['invoiceid'],
      $payment_id,
      null, // payment amount. it will be added automatically from invoice
      null, // payment fee
      $params['paymentmethod']
    );

    Capsule::commit();

    \logTransaction( $params['name'], $payment, $payment['status'] );

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