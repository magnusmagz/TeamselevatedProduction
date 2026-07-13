<?php
/**
 * Stub Payment Processor for Demo Mode
 * Simulates a payment gateway without real charges
 */

require_once __DIR__ . '/PaymentProcessorInterface.php';

class StubPaymentProcessor implements PaymentProcessorInterface {
    private $demoMode = true;

    public function getName(): string {
        return 'stub';
    }

    // Test card numbers for different scenarios
    private $testCards = [
        '4242424242424242' => 'success',
        '4000000000000002' => 'card_declined',
        '4000000000009995' => 'insufficient_funds',
        '4000000000000069' => 'expired_card',
        '4000000000000127' => 'incorrect_cvc',
        '4000000000000341' => 'processing_error'
    ];

    /**
     * Process a payment (demo mode)
     */
    public function processPayment($amount, $paymentMethod, $customerInfo = []) {
        // Simulate processing delay
        usleep(500000); // 0.5 seconds

        $cardNumber = $paymentMethod['card_number'] ?? '4242424242424242';
        $result = $this->testCards[$cardNumber] ?? 'success';

        if ($result === 'success') {
            return [
                'success' => true,
                'transaction_id' => 'demo_txn_' . uniqid(),
                'charge_id' => 'demo_ch_' . uniqid(),
                'status' => 'succeeded',
                'amount' => $amount,
                'currency' => 'USD',
                'payment_method' => $this->maskCardNumber($cardNumber),
                'receipt_url' => 'https://demo-receipts.teamselevated.com/' . uniqid(),
                'timestamp' => date('Y-m-d H:i:s'),
                'demo_mode' => true
            ];
        } else {
            return [
                'success' => false,
                'error_code' => $result,
                'error_message' => $this->getErrorMessage($result),
                'status' => 'failed',
                'demo_mode' => true
            ];
        }
    }

    /**
     * Create a customer (demo mode)
     */
    public function createCustomer($email, $name, $metadata = []) {
        return [
            'success' => true,
            'customer_id' => 'demo_cust_' . uniqid(),
            'email' => $email,
            'name' => $name,
            'created_at' => date('Y-m-d H:i:s'),
            'demo_mode' => true
        ];
    }

    /**
     * Save a payment method (demo mode)
     */
    public function savePaymentMethod($customerId, $paymentMethod) {
        $cardNumber = $paymentMethod['card_number'] ?? '4242424242424242';

        return [
            'success' => true,
            'payment_method_id' => 'demo_pm_' . uniqid(),
            'customer_id' => $customerId,
            'card_last4' => substr($cardNumber, -4),
            'card_brand' => $this->getCardBrand($cardNumber),
            'exp_month' => $paymentMethod['exp_month'] ?? '12',
            'exp_year' => $paymentMethod['exp_year'] ?? '2025',
            'demo_mode' => true
        ];
    }

    /**
     * Charge a saved payment method (demo mode)
     */
    public function chargeSavedPaymentMethod($paymentMethodId, $amount, $description = '') {
        // Simulate potential failure (10% chance)
        $shouldFail = (rand(1, 10) === 1);

        if ($shouldFail) {
            return [
                'success' => false,
                'error_code' => 'card_declined',
                'error_message' => 'Your card was declined.',
                'status' => 'failed',
                'demo_mode' => true
            ];
        }

        return [
            'success' => true,
            'transaction_id' => 'demo_txn_' . uniqid(),
            'charge_id' => 'demo_ch_' . uniqid(),
            'payment_method_id' => $paymentMethodId,
            'status' => 'succeeded',
            'amount' => $amount,
            'description' => $description,
            'timestamp' => date('Y-m-d H:i:s'),
            'demo_mode' => true
        ];
    }

    /**
     * Process a refund (demo mode)
     */
    public function refund($transactionId, $amount, $reason = '') {
        // Simulate processing delay
        usleep(300000); // 0.3 seconds

        return [
            'success' => true,
            'refund_id' => 'demo_ref_' . uniqid(),
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'reason' => $reason,
            'status' => 'succeeded',
            'timestamp' => date('Y-m-d H:i:s'),
            'demo_mode' => true
        ];
    }

    /**
     * Get transaction details (demo mode)
     */
    public function getTransaction($transactionId) {
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100.00,
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'demo_mode' => true
        ];
    }

    /**
     * Simulate webhook event
     */
    public function simulateWebhook($eventType, $data = []) {
        $webhookEvents = [
            'payment.succeeded' => [
                'event_id' => 'demo_evt_' . uniqid(),
                'type' => 'payment.succeeded',
                'data' => array_merge([
                    'transaction_id' => 'demo_txn_' . uniqid(),
                    'amount' => 100.00,
                    'status' => 'succeeded'
                ], $data),
                'created_at' => time()
            ],
            'payment.failed' => [
                'event_id' => 'demo_evt_' . uniqid(),
                'type' => 'payment.failed',
                'data' => array_merge([
                    'transaction_id' => 'demo_txn_' . uniqid(),
                    'error' => 'card_declined'
                ], $data),
                'created_at' => time()
            ],
            'refund.succeeded' => [
                'event_id' => 'demo_evt_' . uniqid(),
                'type' => 'refund.succeeded',
                'data' => array_merge([
                    'refund_id' => 'demo_ref_' . uniqid(),
                    'transaction_id' => 'demo_txn_' . uniqid(),
                    'amount' => 100.00
                ], $data),
                'created_at' => time()
            ]
        ];

        return $webhookEvents[$eventType] ?? null;
    }

    /**
     * Helper: Mask card number
     */
    private function maskCardNumber($cardNumber) {
        return '****' . substr($cardNumber, -4);
    }

    /**
     * Helper: Get card brand from number
     */
    private function getCardBrand($cardNumber) {
        $firstDigit = substr($cardNumber, 0, 1);

        switch ($firstDigit) {
            case '4': return 'visa';
            case '5': return 'mastercard';
            case '3': return 'amex';
            case '6': return 'discover';
            default: return 'unknown';
        }
    }

    /**
     * Helper: Get error message for error code
     */
    private function getErrorMessage($errorCode) {
        $messages = [
            'card_declined' => 'Your card was declined. Please try a different payment method.',
            'insufficient_funds' => 'Your card has insufficient funds.',
            'expired_card' => 'Your card has expired.',
            'incorrect_cvc' => 'Your card\'s security code is incorrect.',
            'processing_error' => 'An error occurred while processing your payment. Please try again.'
        ];

        return $messages[$errorCode] ?? 'Payment failed. Please try again.';
    }

    /**
     * Get test card numbers for UI
     */
    public static function getTestCards() {
        return [
            [
                'number' => '4242424242424242',
                'description' => 'Successful payment',
                'result' => 'success'
            ],
            [
                'number' => '4000000000000002',
                'description' => 'Card declined',
                'result' => 'declined'
            ],
            [
                'number' => '4000000000009995',
                'description' => 'Insufficient funds',
                'result' => 'insufficient_funds'
            ],
            [
                'number' => '4000000000000069',
                'description' => 'Expired card',
                'result' => 'expired'
            ],
            [
                'number' => '4000000000000127',
                'description' => 'Incorrect CVC',
                'result' => 'incorrect_cvc'
            ]
        ];
    }
}
