<?php

declare(strict_types=1);

use WHMCS\Billing\Invoice;
use WHMCS\Carbon;
use WHMCS\Config\Setting as WHMCSSetting;
use WHMCS\Database\Capsule;
use WHMCS\Exception\Module\NotServicable;
use WHMCS\Module\Gateway;
use WHMCS\Payment\PayMethod\Adapter\RemoteCreditCard;
use WHMCS\User\Client;

class ChipAction
{
    /**
     * Confirmation emails prepared during complete_payment() and sent only after the HTTP response
     * has been flushed to the gateway.
     *
     * @var list<array{template: string, invoiceid: int, extra: array}>
     */
    private static array $pending_emails = [];

    public static function complete_payment($params, $payment)
    {
        if (\is_array($payment)) { // success callback
            $payment_id = $payment['id'];
        } elseif (\is_string($payment)) { // success redirect
            try {
                $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
                $payment_id = $payment;
                $payment = $chip->get_payment($payment);
            } catch (Exception $e) {
                \logActivity('CHIP Complete Payment Error: ' . $e->getMessage());

                return false;
            }
        } else {
            return false;
        }

        if ($payment['status'] != 'paid') {
            return false;
        }

        // Non-blocking on purpose. Two requests for the same payment (the callback and a retry, or
        // the invoice page and the callback) must never queue behind each other: the gateway's
        // callback timeout is shorter than the work below, so waiting here turned every retry of a
        // slow first attempt into another timeout. A miss means another request is already
        // processing this payment, and the caller turns that into a retryable HTTP 503.
        $lock = Capsule::select('SELECT GET_LOCK(?, 0) as `lock`', ["chip_payment_$payment_id"])[0];
        if (!$lock || (int)$lock->lock !== 1) {
            return false;
        }

        try {
            return Capsule::transaction(function () use ($params, $payment, $payment_id) {
                // capture() told WHMCS the capture succeeded, so WHMCS credits this invoice itself
                // (ccProcessing() -> captureCCPayment() -> Invoice::addPayment()). Crediting again
                // here is what turned one capture into two tblaccounts rows with the same transid
                // and a negative invoice balance; it happens whenever this callback arrives before
                // WHMCS reaches addPayment(), which is routine on a slow capture request.
                //
                // A capture the module left pending or declined has no marker: WHMCS does not
                // credit those, so this callback must go on and credit them.
                if (!ChipHelpers::capture_callback_should_credit(self::is_capture_callback(), $payment_id)) {
                    $credited = Capsule::table('tblaccounts')
                        ->where('transid', $payment_id)
                        ->take(1)
                        ->first();

                    if (!$credited) {
                        \logActivity(sprintf(
                            'CHIP Capture Warning: invoice #%d is not credited for payment %s. WHMCS records capture payments itself - if the invoice is still unpaid it has to be credited manually.',
                            $params['invoiceid'],
                            $payment_id
                        ));
                    }

                    return true;
                }

                $account = Capsule::table('tblaccounts')
                    ->where('transid', $payment_id)
                    ->take(1)
                    ->first();

                if ($account) {
                    return true;
                }

                $history = \WHMCS\Billing\Payment\Transaction\History::where("transaction_id", $payment_id)
                    ->take(1)
                    ->first();

                // WHMCS records one of these for EVERY capture attempt it makes
                // ("Automatic Payment Attempt"), including the pending and declined ones it never
                // credits. Reading it as "already credited" is what kept a pending capture from
                // ever being credited when its callback finally said paid - the invoice stayed
                // "Payment Pending" with the money taken. A capture callback therefore decides on
                // the money row above, not on the attempt record.
                if ($history && !self::is_capture_callback()) {
                    return true;
                }

                $invoice = Invoice::findOrFail($params['invoiceid']);

                // A second purchase for an invoice that is already fully paid happens when
                // the payer completes checkout twice, typically because the first callback
                // was slow enough that the invoice still looked unpaid. The money is with us
                // but there is nothing left to credit, so the amount check below would throw,
                // return HTTP 500, and the gateway would retry for hours. Treat it as an
                // idempotent success and leave an audit trail so the duplicate can be refunded.
                if ($invoice->balance <= 0) {
                    $credited = Capsule::table('tblaccounts')
                        ->where('invoiceid', $params['invoiceid'])
                        ->orderBy('id', 'desc')
                        ->first(['transid', 'amountin']);

                    \logActivity(sprintf(
                        'CHIP Duplicate Payment: invoice #%d is already fully paid. Payment %s (%s %s) was NOT credited%s. A refund may be required.',
                        $params['invoiceid'],
                        $payment_id,
                        $payment['payment']['currency'],
                        number_format($payment['payment']['amount'] / 100, 2),
                        $credited ? ' - credited transaction: ' . $credited->transid : ''
                    ));

                    return true;
                }

                $history = new \WHMCS\Billing\Payment\Transaction\History();
                $history->invoice_id = $params['invoiceid'];
                $history->gateway = $params['name'];
                $history->transactionId = $payment_id;
                $history->remoteStatus = ucfirst($payment['status']);
                $history->description = 'Updated by Redirect/Callback';
                $history->completed = true;
                $history->save();

                $client = Client::find($invoice->userid);
                $client_currency_id = (int)$client->currencyId;

                $transaction_currency = Capsule::table("tblcurrencies")->where("code", "=", strtoupper($payment['payment']['currency']))->first(["id"]);

                if (!$transaction_currency) {
                    throw new NotServicable("Transaction currency not found in system");
                }

                if (isset($params['convertto']) && (int)$params['convertto'] > 0) {
                    if ((int)$transaction_currency->id !== (int)$params['convertto']) {
                        throw new NotServicable("Transaction currency mismatch with Convert To setting");
                    }
                }

                $payment_amount = \convertCurrency((float)($payment['payment']['amount'] / 100), (int)$transaction_currency->id, $client_currency_id);
                $transactionFee = \convertCurrency((float)($payment['transaction_data']['attempts'][0]['fee_amount'] / 100), (int)$transaction_currency->id, $client_currency_id);

                $amount = $invoice->balance;

                if (abs($payment_amount - $amount) < 0.01) {
                    $payment_amount = $amount;
                }

                if ($payment_amount != $amount) {
                    throw new NotServicable("Invoice Amount Invalid");
                }

                $send_credit_card_email = isset($_GET['capturecallback']) and $_GET['capturecallback'] == 'true' and $payment['recurring_token'] and isset($_SERVER['HTTP_X_SIGNATURE']);

                $invoice_payment_status = \addInvoicePayment(
                    $params['invoiceid'],
                    $payment_id,
                    $payment_amount,
                    $transactionFee,
                    $params['paymentmethod'],
                    $send_credit_card_email
                );

                \logTransaction($params['name'], $payment, ucfirst($payment['status']), ['history_id' => $history->id]);

                if ($payment['is_recurring_token'] && ($params['disableRecurring'] ?? '') !== 'on') {
                    $payMethod = RemoteCreditCard::factoryPayMethod($client, $client->billingContact);
                    $gateway = Gateway::factory($params['paymentmethod']);
                    $payMethod->description = $payment['transaction_data']['extra']['cardholder_name'];
                    $payMethod->setGateway($gateway);
                    $payMethod_payment = $payMethod->payment;
                    $masked_pan = $payment['transaction_data']['extra']['masked_pan'];

                    $payMethod_payment->setLastFour(substr($masked_pan, -4));
                    $expiry_date = sprintf("%02d", $payment['transaction_data']['extra']['expiry_month']) . '/' . $payment['transaction_data']['extra']['expiry_year'];
                    $payMethod_payment->setCardType(ucfirst($payment['transaction_data']['extra']['card_brand']));
                    $payMethod_payment->setExpiryDate(Carbon::createFromCcInput($expiry_date));
                    $payMethod_payment->setRemoteToken($payment['id']);
                    $payMethod_payment->save();
                    $payMethod->save();
                }

                if (!$invoice_payment_status) {
                    return false;
                }

                if ($send_credit_card_email) {
                    $emailTemplate = "Credit Card Payment Confirmation";
                    $gateway = WHMCS\Module\Gateway::factory($params['paymentmethod']);
                    if ($customEmailTemplate = $gateway->getMetaDataValue("successEmail")) {
                        $customEmailTemplate = WHMCS\Mail\Template::where("name", "=", $customEmailTemplate)->first();
                        if ($customEmailTemplate) {
                            $emailTemplate = $customEmailTemplate->name;
                        }
                    }

                    // Queued rather than sent: the SMTP round trip is the slowest thing left after
                    // the credit is committed, and this endpoint's response time is what decides
                    // whether the gateway retries. See send_pending_emails_after_response().
                    self::$pending_emails[] = [
                        'template' => $emailTemplate,
                        'invoiceid' => (int) $params['invoiceid'],
                        'extra' => ["payMethod" => $invoice->payMethod],
                    ];
                }

                return true;
            });
        } finally {
            Capsule::select('SELECT RELEASE_LOCK(?)', ["chip_payment_$payment_id"]);
        }
    }

    /**
     * Send the confirmation emails queued during complete_payment(), after the response has been
     * flushed.
     *
     * The gateway only needs to know that the credit was committed. Sending mail inside the request
     * made a slow SMTP server stretch the whole callback past the gateway's delivery timeout: the
     * delivery was marked failed and retried, the merchant's WHMCS spent ~2 minutes still showing the
     * invoice unpaid, and an automatic capture in that window charged the saved card a second time.
     * Answering first removes that coupling; a mail failure is logged and never fails the callback.
     */
    public static function send_pending_emails_after_response(): void
    {
        if (self::$pending_emails === []) {
            return;
        }

        if (\function_exists('fastcgi_finish_request')) {
            \ignore_user_abort(true);

            while (\ob_get_level() > 0) {
                \ob_end_flush();
            }

            \flush();
            \fastcgi_finish_request();
        }

        self::send_pending_emails();
    }

    /**
     * Send the queued confirmation emails now. Used by the browser-return path (link()), where the
     * response has not been produced yet and there is nothing to flush.
     */
    public static function send_pending_emails(): void
    {
        $queued = self::$pending_emails;
        self::$pending_emails = [];

        foreach ($queued as $email) {
            try {
                \sendMessage($email['template'], $email['invoiceid'], $email['extra']);
            } catch (Exception $e) {
                \logActivity('CHIP Confirmation Email Error for Invoice #' . $email['invoiceid'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * The gateway asks for a capture callback by adding capturecallback=true to success_callback;
     * a payer checkout never carries it, so this distinguishes the two credit paths.
     */
    private static function is_capture_callback(): bool
    {
        return isset($_GET['capturecallback']) && $_GET['capturecallback'] == 'true';
    }

    public static function retrieve_public_key($params)
    {
        $ten_secret_key = substr($params['secretKey'], 0, 10);
        $setting_name = "CHIP_PUBLIC_KEY_" . $ten_secret_key;

        if ($public_key = WHMCSSetting::getValue($setting_name)) {
            // A cached key that cannot be parsed makes openssl_verify() fail for every callback, and
            // the endpoint then answers 403 "Invalid X Signature" without crediting anything - the
            // payment is taken and the invoice is never marked paid. Treat an unusable cache entry as
            // missing so an installation that cached a bad key heals itself on the next callback.
            if (self::is_usable_public_key((string) $public_key)) {
                return $public_key;
            }

            WHMCSSetting::where("setting", $setting_name)->delete();
        }

        try {
            $chip = \ChipAPI::get_instance($params['secretKey'], $params['brandId']);
            $public_key = self::normalise_public_key((string) $chip->public_key());

            if (!self::is_usable_public_key($public_key)) {
                throw new ChipAPIException('CHIP API returned a public key that cannot be parsed');
            }

            WHMCSSetting::setValue($setting_name, $public_key);

            return $public_key;
        } catch (Exception $e) {
            \logActivity('CHIP Public Key Error: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * The gateway answers GET /public_key/ with a JSON string, i.e. the PEM wrapped in double quotes
     * with its newlines escaped ("-----BEGIN PUBLIC KEY-----\nMIIB..."). The old code only unescaped
     * the newlines and kept the quotes, so the cached value was never a valid PEM and every callback
     * failed signature verification. Decode the JSON string when that is what arrived; a plain PEM
     * body is passed through.
     */
    private static function normalise_public_key(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed !== '' && $trimmed[0] === '"') {
            $decoded = json_decode($trimmed, true);

            if (is_string($decoded) && $decoded !== '') {
                return trim($decoded);
            }
        }

        return trim(\str_replace('\n', "\n", $raw));
    }

    private static function is_usable_public_key(string $public_key): bool
    {
        if (trim($public_key) === '') {
            return false;
        }

        return @openssl_pkey_get_public($public_key) !== false;
    }

    public static function clean_up_public_key()
    {
        if (PHP_SAPI !== 'cli') {
            $currentUser = new \WHMCS\Authentication\CurrentUser();
            if (!$currentUser->isAuthenticatedAdmin()) {
                die('Unauthorized');
            }
        }

        require __DIR__ . '/../../../init.php';
        WHMCSSetting::where('setting', 'LIKE', "CHIP_PUBLIC_KEY_%")->delete();

        echo 'Public Key clean up success';
    }
}

if (isset($_GET['clean_up_public_key'])) {
    ChipAction::clean_up_public_key();
}
