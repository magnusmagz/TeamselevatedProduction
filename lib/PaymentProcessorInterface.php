<?php
/**
 * Contract for payment processors.
 *
 * Implementations:
 *   - StubPaymentProcessor  — demo mode, simulated charges, no real money
 *   - StripePaymentProcessor — live mode (Phase 2, see docs/payments-stripe-implementation-plan.md)
 *
 * Obtain an instance via PaymentProcessorFactory::create() — never instantiate a
 * processor directly in API code, so the PAYMENT_MODE switch stays in one place.
 *
 * All methods return an associative array with at least:
 *   'success' => bool
 * and on success the processor's identifiers (transaction_id, charge_id, ...),
 * or on failure 'error_code' / 'error_message'.
 */

interface PaymentProcessorInterface {

    /** Machine name recorded in payment_transactions.processor ('stub', 'stripe'). */
    public function getName(): string;

    /** Charge a one-off payment. $paymentMethod shape is processor-specific. */
    public function processPayment($amount, $paymentMethod, $customerInfo = []);

    /** Create a customer record with the processor. */
    public function createCustomer($email, $name, $metadata = []);

    /** Attach a reusable payment method to a customer. */
    public function savePaymentMethod($customerId, $paymentMethod);

    /** Charge a previously saved payment method (off-session). */
    public function chargeSavedPaymentMethod($paymentMethodId, $amount, $description = '');

    /** Refund a prior transaction, fully or partially. */
    public function refund($transactionId, $amount, $reason = '');

    /** Fetch current details/status for a transaction. */
    public function getTransaction($transactionId);
}
