<?php

use WHMCS\ClientArea;
use WHMCS\Session;
use WHMCS\Module\Gateway\Balance;
use WHMCS\Module\Gateway\BalanceCollection;
use WHMCS\Billing\Payment\Transaction\Information;
use WHMCS\Carbon;
use WHMCS\Database\Capsule;

class ChipGateway
{
  public static function link($params, $gateway_name, $image_file, $image_title)
  {
    if ($params['currency'] != 'MYR') {
      $html = '<p>' . str_replace(':currency', $params['currency'], Lang::trans('This invoice is quoted in :currency, but CHIP only accepts payments in MYR.'));

      if (ClientArea::isAdminMasqueradingAsClient()) {
        $html .= "\n<br />" . Lang::trans("Administrators can enable 'Convert to For Processing' for MYR to allow this payment.");
      }

      return $html . '</p>';
    }

    if (empty($params['secretKey']) or empty($params['brandId'])) {
      return '<p>Secret Key and Brand ID not set</p>';
    }

    if ($gateway_name == 'chip') {
      $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
      $payment_methods = $chip->payment_methods($params['currency']);

      $payment_method_configuration_error = false;

      if ($params['paymentWhitelist'] == 'on') {
        $payment_method_configuration_error = true;
        $configured_payment_methods = \ChipHelpers::get_whitelisted_methods($params);

        foreach ($configured_payment_methods as $cpm) {
          if (in_array($cpm, $payment_methods['available_payment_methods'])) {
            $payment_method_configuration_error = false;
            break;
          }
        }
      }

      if ($payment_method_configuration_error) {
        return '<p>Payment method whitelisting error. Please disable payment method whitelisting</p>';
      }
    }

    if (isset($_GET['success']) && !empty(Session::get($gateway_name . '_' . $params['invoiceid']))) {
      $payment_id = Session::getAndDelete($gateway_name . '_' . $params['invoiceid']);

      if (\ChipAction::complete_payment($params, $payment_id)) {
        return '<script>window.location.reload();</script>';
      }
    }

    $html = '<p>'
      . nl2br($params['paymentInformation'])
      . '<br />'
      . '<a href="' . $params['systemurl'] . 'modules/gateways/chip/redirect.php?invoiceid=' . $params['invoiceid'] . '&gateway=' . $gateway_name . '">'
      . '<img height="44px" src="' . $params['systemurl'] . 'modules/gateways/' . $gateway_name . '/' . $image_file . '" title="' . Lang::trans($image_title) . '">'
      . '</a>'
      . '<br />'
      . Lang::trans('invoicerefnum')
      . ': '
      . $params['invoicenum']
      . '</p>';

    return $html;
  }

  public static function refund($params)
  {
    if ($params['currency'] != 'MYR') {
      return array(
        'status' => 'error',
        'rawdata' => Lang::trans('Refund failed: Transaction currency must be MYR.'),
        'transid' => $params['transid'],
      );
    }

    if ($params['basecurrency'] != 'MYR') {
      return array(
        'status' => 'error',
        'rawdata' => str_replace(':transid', $params['transid'], Lang::trans('Manual refund required: Automated refunds are only supported for MYR base currency. Please process the refund for Purchase ID :transid via the CHIP Dashboard.')),
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

  public static function account_balance($params)
  {
    $balanceInfo = [];

    $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
    $balanceData = $chip->account_balance();

    foreach ($balanceData as $currency => $value) {
      $balanceInfo[] = Balance::factory(
        ($value['balance'] / 100),
        $currency
      );
    }

    return BalanceCollection::factoryFromItems(...$balanceInfo);
  }

  public static function transaction_information(array $params = []): Information
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

  public static function capture($params, $gateway_name)
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
      'success_callback' => $system_url . 'modules/gateways/callback/' . $gateway_name . '.php?capturecallback=true&invoiceid=' . $params['invoiceid'],
      'creator_agent' => 'WHMCS: ' . CHIP_MODULE_VERSION,
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

    Capsule::select("SELECT GET_LOCK('chip_payment_$payment_id', 10);");

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

  public static function store_remote($params)
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

  public static function callback($gateway_name)
  {
    require_once __DIR__ . '/api.php';
    require_once __DIR__ . '/action.php';
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

    $get_invoice_id = filter_var($_GET['invoiceid'], FILTER_VALIDATE_INT);
    if (!$get_invoice_id || $get_invoice_id <= 0) {
      die('Invalid invoice ID');
    }

    $gatewayParams = getGatewayVariables($gateway_name);

    if (!$gatewayParams['type']) {
      die('Module Not Activated');
    }

    $invoice = new \WHMCS\Invoice($get_invoice_id);
    $params = $invoice->getGatewayInvoiceParams();

    if (\openssl_verify($content, \base64_decode($_SERVER['HTTP_X_SIGNATURE']), \ChipAction::retrieve_public_key($params), 'sha256WithRSAEncryption') != 1) {
      \header('Forbidden', true, 403);
      die('Invalid X Signature');
    }

    $payment = \json_decode($content, true);

    if ($payment['status'] != 'paid') {
      die('Status is not paid');
    }

    \ChipAction::complete_payment($params, $payment);

    echo 'Done';
  }
}
