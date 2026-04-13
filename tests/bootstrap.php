<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../modules/gateways/chip/action.php';

// Mock WHMCS Functions
if (!function_exists('convertCurrency')) {
    function convertCurrency($amount, $from, $to) {
        return $amount; // Simple mock
    }
}

if (!function_exists('addInvoicePayment')) {
    function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway, $sendEmail = false) {
        return true;
    }
}

if (!function_exists('logTransaction')) {
    function logTransaction($gateway, $data, $status, $extra = []) {
        // Mock
    }
}

if (!function_exists('sendMessage')) {
    function sendMessage($template, $invoiceId, $extra = []) {
        // Mock
    }
}

// Mock WHMCS Classes
namespace WHMCS\Database {
    class Capsule {
        public static function select($query) { return [ (object)['lock' => 1] ]; }
        public static function table($name) { return new class {
            public function where($col, $val) { return $this; }
            public function take($n) { return $this; }
            public function first($cols = ['*']) { return null; }
        }; }
        public static function transaction($callback) { return $callback(); }
    }
}

namespace WHMCS\User {
    class Client {
        public static function find($id) { 
            $client = new \stdClass();
            $client->currencyId = 1;
            $client->billingContact = new \stdClass();
            return $client;
        }
    }
}

namespace WHMCS\Billing {
    class Invoice {
        public static function findOrFail($id) {
            $invoice = new \stdClass();
            $invoice->balance = 100.00;
            $invoice->payMethod = new \stdClass();
            return $invoice;
        }
    }
}

namespace WHMCS\Billing\Payment\Transaction {
    class History {
        public static function where($col, $val) { return new class {
            public function take($n) { return $this; }
            public function first() { return null; }
        }; }
        public function save() {}
    }
}

namespace WHMCS\Payment\PayMethod\Adapter {
    class RemoteCreditCard {
        public static function factoryPayMethod($client, $contact) {
            return new class {
                public $payment;
                public $description;
                public function __construct() {
                    $this->payment = new class {
                        public function setLastFour($v) {}
                        public function setCardType($v) {}
                        public function setExpiryDate($v) {}
                        public function setRemoteToken($v) {}
                        public function save() {}
                    };
                }
                public function setGateway($v) {}
                public function save() {}
            };
        }
    }
}

namespace WHMCS\Module {
    class Gateway {
        public static function factory($name) { return new class {
            public function getMetaDataValue($key) { return null; }
        }; }
    }
}

namespace WHMCS {
    class Carbon {
        public static function createFromCcInput($v) { return new \DateTime(); }
    }
}

namespace WHMCS\Exception\Module {
    class NotServicable extends \Exception {}
}

namespace WHMCS\Config {
    class Setting {
        public static function getValue($key) { return null; }
        public static function setValue($key, $val) {}
        public static function where($col, $op, $val) { return new class {
            public function delete() {}
        }; }
    }
}

namespace WHMCS\Mail {
    class Template {
        public static function where($col, $op, $val) { return new class {
            public function first() { return null; }
        }; }
    }
}

namespace {
    // Mock ChipAPI if not loaded
    if (!class_exists('ChipAPI')) {
        class ChipAPI {
            public static function get_instance($key, $brandId) {
                return new self();
            }
            public function get_payment($id) {
                return [
                    'id' => $id,
                    'status' => 'paid',
                    'payment' => ['currency' => 'MYR', 'amount' => 10000],
                    'transaction_data' => ['attempts' => [['fee_amount' => 100]]],
                    'is_recurring_token' => false
                ];
            }
            public function public_key() { return 'mock_key'; }
        }
    }
}
