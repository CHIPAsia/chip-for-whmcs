<?php

declare(strict_types=1);

use WHMCS\Billing\Payment\Transaction\Information;
use WHMCS\Carbon;
use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\Balance;
use WHMCS\Module\Gateway\BalanceCollection;
use WHMCS\Session;

class ChipGateway
{
    public static function link($params, $gateway_name, $image_file, $image_title)
    {
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
          . '<img height="44px" src="' . $params['systemurl'] . 'modules/gateways/' . $gateway_name . '/' . $image_file . '" title="' . \Lang::trans($image_title) . '">'
          . '</a>'
          . '<br />'
          . \Lang::trans('invoicerefnum')
          . ': '
          . $params['invoicenum']
          . '</p>';

        return $html;
    }

    public static function refund($params)
    {
        $refund_amount = $params['amount'];
        $currency_code = $params['currency'];

        if (isset($params['convertto'])) {
            $convertto_currency = Capsule::table('tblcurrencies')->where('id', $params['convertto'])->first();
            if ($convertto_currency) {
                $from_currency = Capsule::table('tblcurrencies')->where('code', $params['currency'])->first();
                if ($from_currency) {
                    $refund_amount = convertCurrency((float)$refund_amount, (int)$from_currency->id, (int)$params['convertto']);
                    $currency_code = $convertto_currency->code;
                }
            }
        }

        try {
            $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
            $result = $chip->refund_payment($params['transid'], ['amount' => round($refund_amount * 100)]);

            if (!is_array($result) || !array_key_exists('id', $result) or $result['status'] != 'success') {
                return [
                    'status' => 'error',
                    'rawdata' => json_encode($result),
                    'transid' => $params['transid'],
                ];
            }

            $fees = $result['payment']['fee_amount'] / 100;
            if (isset($params['convertto'])) {
                $from_currency = Capsule::table('tblcurrencies')->where('code', $currency_code)->first();
                $to_currency = Capsule::table('tblcurrencies')->where('code', $params['currency'])->first();

                if ($from_currency && $to_currency) {
                    $fees = convertCurrency((float)$fees, (int)$from_currency->id, (int)$to_currency->id);
                }
            }

            return [
                'status' => 'success',
                'rawdata' => json_encode($result),
                'transid' => $result['id'],
                'fees' => $fees,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'rawdata' => $e->getMessage(),
                'transid' => $params['transid'],
            ];
        }
    }

    public static function account_balance($params)
    {
        $balanceInfo = [];

        try {
            $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
            $balanceData = $chip->account_balance();

            if (is_array($balanceData)) {
                foreach ($balanceData as $currency => $value) {
                    $balanceInfo[] = Balance::factory(
                        ($value['balance'] / 100),
                        $currency
                    );
                }
            }
        } catch (Exception $e) {
            \logActivity('CHIP Balance Error: ' . $e->getMessage());
        }

        return BalanceCollection::factoryFromItems(...$balanceInfo);
    }

    public static function transaction_information(array $params = []): Information
    {
        $information = new Information();

        try {
            $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
            $payment = $chip->get_payment($params['transactionId']);

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

            $currency = \WHMCS\Billing\Currency::where("code", $payment['payment']['currency'])->firstOrFail();

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
        } catch (Exception $e) {
            \logActivity('CHIP Transaction Info Error: ' . $e->getMessage());
            return $information;
        }
    }

    public static function capture($params, $gateway_name)
    {
        $capture_amount = $params['amount'];
        $currency_code = $params['currency'];

        if (isset($params['convertto'])) {
            $convertto_currency = Capsule::table('tblcurrencies')->where('id', $params['convertto'])->first();
            if ($convertto_currency) {
                $from_currency = Capsule::table('tblcurrencies')->where('code', $params['currency'])->first();
                if ($from_currency) {
                    $capture_amount = convertCurrency((float)$capture_amount, (int)$from_currency->id, (int)$params['convertto']);
                    $currency_code = $convertto_currency->code;
                }
            }
        }

        try {
            $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);

            $get_client = $chip->get_client_by_email($params['clientdetails']['email']);
            $client = $get_client['results'][0];

            $system_url = $params['systemurl'];

            if ($params['systemUrlHttps'] == 'https') {
                $system_url = preg_replace("/^http:/i", "https:", $system_url);
            }

            $purchase_params = [
                'success_callback' => $system_url . 'modules/gateways/callback/' . $gateway_name . '.php?capturecallback=true&invoiceid=' . $params['invoiceid'],
                'creator_agent' => 'WHMCS: ' . CHIP_MODULE_VERSION,
                'reference' => $params['invoiceid'],
                'client_id' => $client['id'],
                'platform' => 'whmcs',
                'send_receipt' => $params['purchaseSendReceipt'] == 'on',
                'due' => time() + (abs((int)$params['dueStrictTiming']) * 60),
                'brand_id' => $params['brandId'],
                'purchase' => [
                    'timezone' => $params['purchaseTimeZone'],
                    'currency' => $currency_code,
                    'due_strict' => $params['dueStrict'] == 'on',
                    'products' => [
                        [
                            'name' => substr($params['description'], 0, 256),
                            'price' => round($capture_amount * 100),
                        ]
                    ],
                ],
            ];

            $create_payment = $chip->create_payment($purchase_params);

            $charge_payment = $chip->charge_payment($create_payment['id'], ['recurring_token' => $params["gatewayid"]]);

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
                $fee = $charge_payment['transaction_data']['attempts'][0]['fee_amount'] / 100;
                if (isset($params['convertto'])) {
                    $from_currency = Capsule::table('tblcurrencies')->where('code', $currency_code)->first();
                    $to_currency_id = (int)$params['clientdetails']['currency'];

                    if ($from_currency) {
                        $fee = convertCurrency((float)$fee, (int)$from_currency->id, $to_currency_id);
                    }
                }

                return ["status" => "success", "transid" => $create_payment['id'], "rawdata" => $charge_payment, 'fee' => $fee];
            } elseif ($charge_payment['status'] == 'pending_charge') {
                return ["status" => "pending", "transid" => $create_payment['id'], "rawdata" => $charge_payment];
            }

            return ["status" => "declined", "transid" => $create_payment['id'], "rawdata" => $charge_payment];
        } catch (Exception $e) {
            \logActivity('CHIP Capture Error: ' . $e->getMessage());
            return ["status" => "declined", "declinereason" => $e->getMessage()];
        }
    }

    public static function store_remote($params)
    {
        $action = $params['action'];
        $token = $params['gatewayid'];

        switch ($action) {
            case 'delete':
                try {
                    $chip = \ChipAPI::get_instance($params['secretKey'], '');
                    $chip->delete_token($token);
                } catch (Exception $e) {
                    \logActivity('CHIP Delete Token Error: ' . $e->getMessage());
                }
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

        \App::load_function('gateway');
        \App::load_function('invoice');

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

    public static function redirect()
    {
        require_once __DIR__ . '/api.php';
        require_once __DIR__ . '/action.php';
        require_once __DIR__ . '/../../../init.php';

        \App::load_function('gateway');
        \App::load_function('invoice');

        if (!isset($_GET['invoiceid'])) {
            \logActivity('CHIP Redirect: Missing invoiceid');
            exit;
        }

        $gateway_module = isset($_GET['gateway']) ? $_GET['gateway'] : 'chip';

        $get_invoice_id = filter_var($_GET['invoiceid'], FILTER_VALIDATE_INT);
        if (!$get_invoice_id || $get_invoice_id <= 0) {
            \logActivity('CHIP Redirect: Invalid invoiceid ' . $_GET['invoiceid']);
            header('Location: ' . $GLOBALS['CONFIG']['SystemURL']);
            exit;
        }

        $invoice = new \WHMCS\Invoice($get_invoice_id);
        $params = $invoice->getGatewayInvoiceParams();

        if ($params['paymentmethod'] != $gateway_module) {
            logActivity("CHIP Redirect: Gateway mismatch. Expected $gateway_module, got " . $params['paymentmethod']);
            header('Location: ' . $params['returnurl']);
            exit;
        }

        $currentUser = new \WHMCS\Authentication\CurrentUser();
        $user = $currentUser->user();
        $admin = $currentUser->isAuthenticatedAdmin();

        if ($admin) {
            // The request is made by admin. No further check required.
        } elseif ($user) {
            $current_user_client_id = $currentUser->client()->id;
            $param_client_id = $params['clientdetails']['client_id'];

            if ($current_user_client_id != $param_client_id) {
                \logActivity('CHIP Redirect: Attempt to access other client invoice with number #' . $get_invoice_id, $current_user_client_id);
                header('Location: ' . $GLOBALS['CONFIG']['SystemURL']);
                exit;
            }
        } else {
            \logActivity('CHIP Redirect: Unauthenticated access attempt for invoice #' . $get_invoice_id);
            header('Location: ' . $GLOBALS['CONFIG']['SystemURL']);
            exit;
        }

        $phone_a = explode('.', $params['clientdetails']['phonenumberformatted']);
        $phone = implode(' ', $phone_a);

        $system_url = $params['systemurl'];

        if ($params['systemUrlHttps'] == 'https') {
            $system_url = preg_replace("/^http:/i", "https:", $system_url);
        }

        $purchase_amount = $params['amount'];
        $currency_code = $params['currency'];

        if (isset($params['convertto'])) {
            $convertto_currency = Capsule::table('tblcurrencies')->where('id', $params['convertto'])->first();
            if ($convertto_currency) {
                $from_currency = Capsule::table('tblcurrencies')->where('code', $params['currency'])->first();
                if ($from_currency) {
                    $purchase_amount = convertCurrency((float)$purchase_amount, (int)$from_currency->id, (int)$params['convertto']);
                    $currency_code = $convertto_currency->code;
                }
            }
        }

        $send_params = [
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
            'purchase' => [
                'timezone' => $params['purchaseTimeZone'],
                'currency' => $currency_code,
                'due_strict' => $params['dueStrict'] == 'on',
                'products' => [
                    [
                        'name' => substr($params['description'], 0, 256),
                        'price' => round($purchase_amount * 100),
                        'quantity' => '1',
                    ]
                ],
            ],
        ];

        if (isset($params['paymentWhitelist']) and $params['paymentWhitelist'] == 'on') {
            $send_params['payment_method_whitelist'] = [];

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

        \logActivity("CHIP Redirect: Creating payment for Invoice #$get_invoice_id via $gateway_module");

        try {
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
                \logActivity("CHIP Redirect: Failed to create payment for Invoice #$get_invoice_id. Response: " . json_encode($payment));
                echo "Failed to create payment. Please contact administrator.";
                exit;
            }

            \WHMCS\Session::set('chip_' . $get_invoice_id, $payment['id']);
            \WHMCS\Session::set($gateway_module . '_' . $get_invoice_id, $payment['id']);

            header('Location: ' . $payment['checkout_url']);
        } catch (Exception $e) {
            \logActivity("CHIP Redirect Error for Invoice #$get_invoice_id: " . $e->getMessage());
            echo "An error occurred while initiating payment. Please try again later.";
            exit;
        }
    }
}
