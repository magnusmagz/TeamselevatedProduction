<?php
/**
 * Resolves the active payment processor from PAYMENT_MODE.
 *
 *   PAYMENT_MODE=demo (default) -> StubPaymentProcessor (simulated, no real charges)
 *   PAYMENT_MODE=live           -> StripePaymentProcessor (Phase 2 — throws until built)
 *
 * Keeping the switch here means live mode can never silently fall through to the
 * stub: an unconfigured live environment fails loudly instead of fake-charging.
 */

require_once __DIR__ . '/PaymentProcessorInterface.php';
require_once __DIR__ . '/StubPaymentProcessor.php';

class PaymentProcessorFactory {

    public static function create(?string $mode = null): PaymentProcessorInterface {
        $mode = $mode ?? Env::get('PAYMENT_MODE', 'demo');

        switch ($mode) {
            case 'demo':
                return new StubPaymentProcessor();

            case 'live':
                throw new RuntimeException(
                    'PAYMENT_MODE=live but no live processor is configured. ' .
                    'StripePaymentProcessor ships in Phase 2 — see docs/payments-stripe-implementation-plan.md.'
                );

            default:
                throw new RuntimeException("Unknown PAYMENT_MODE: {$mode}");
        }
    }
}
