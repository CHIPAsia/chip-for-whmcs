<?php

use PHPUnit\Framework\TestCase;
use Mockery as m;

class ChipActionTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testCompletePaymentWithArray()
    {
        $params = [
            'invoiceid' => 1,
            'name' => 'CHIP',
            'clientdetails' => ['id' => 1],
            'paymentmethod' => 'chip_cards'
        ];

        $payment = [
            'id' => 'CHIP-123',
            'status' => 'paid',
            'payment' => [
                'currency' => 'MYR',
                'amount' => 10000 // 100.00
            ],
            'transaction_data' => [
                'attempts' => [
                    ['fee_amount' => 200] // 2.00
                ]
            ],
            'is_recurring_token' => false
        ];

        $result = ChipAction::complete_payment($params, $payment);
        $this->assertTrue($result);
    }

    public function testCompletePaymentWithInvalidStatus()
    {
        $params = [
            'invoiceid' => 1,
            'name' => 'CHIP',
            'clientdetails' => ['id' => 1],
            'paymentmethod' => 'chip_cards'
        ];

        $payment = [
            'id' => 'CHIP-123',
            'status' => 'pending',
            'payment' => [
                'currency' => 'MYR',
                'amount' => 10000
            ]
        ];

        $result = ChipAction::complete_payment($params, $payment);
        $this->assertFalse($result);
    }

    public function testCompletePaymentWithStringId()
    {
        $params = [
            'invoiceid' => 1,
            'name' => 'CHIP',
            'clientdetails' => ['id' => 1],
            'paymentmethod' => 'chip_cards',
            'secretKey' => 'key',
            'brandId' => 'brand'
        ];

        $result = ChipAction::complete_payment($params, 'CHIP-123');
        $this->assertTrue($result);
    }

    public function testCompletePaymentReturnsFalseOnLockFailure()
    {
        // We need to mock Capsule to return 0 for the lock
        // Since we're using a simple mock in bootstrap, we'd need a more dynamic mock here
        // For now, this is a placeholder for where you'd use Mockery on the Capsule class
        $this->markTestIncomplete('Requires dynamic Capsule mocking for lock failure');
    }

    public function testCompletePaymentThrowsExceptionOnAmountMismatch()
    {
        $params = [
            'invoiceid' => 1,
            'name' => 'CHIP',
            'clientdetails' => ['id' => 1],
            'paymentmethod' => 'chip_cards'
        ];

        $payment = [
            'id' => 'CHIP-123',
            'status' => 'paid',
            'payment' => [
                'currency' => 'MYR',
                'amount' => 5000 // 50.00 (Invoice is 100.00 in bootstrap mock)
            ],
            'transaction_data' => [
                'attempts' => [['fee_amount' => 100]]
            ]
        ];

        $this->expectException(\WHMCS\Exception\Module\NotServicable::class);
        ChipAction::complete_payment($params, $payment);
    }

    public function testRetrievePublicKey()
    {
        $params = [
            'secretKey' => '1234567890abcdef',
            'brandId' => 'brand-123'
        ];

        $key = ChipAction::retrieve_public_key($params);
        $this->assertEquals('mock_key', $key);
    }

    public function testCompletePaymentWithRecurringToken()
    {
        $params = [
            'invoiceid' => 1,
            'name' => 'CHIP',
            'clientdetails' => ['id' => 1],
            'paymentmethod' => 'chip_cards'
        ];

        $payment = [
            'id' => 'CHIP-123',
            'status' => 'paid',
            'payment' => ['currency' => 'MYR', 'amount' => 10000],
            'transaction_data' => [
                'attempts' => [['fee_amount' => 200]],
                'extra' => [
                    'cardholder_name' => 'John Doe',
                    'masked_pan' => '411111XXXXXX1111',
                    'expiry_month' => 12,
                    'expiry_year' => 2025,
                    'card_brand' => 'visa'
                ]
            ],
            'is_recurring_token' => true
        ];

        $result = ChipAction::complete_payment($params, $payment);
        $this->assertTrue($result);
    }
}
