# Demo Mode - Payment System
## Teams Elevated Revenue Collection Demo

This demo mode allows you to showcase the full payment collection and reporting system without processing real payments.

---

## Quick Start

### 1. Enable Demo Mode

Add to your `.env` file:
```bash
PAYMENT_MODE=demo
```

### 2. Load Demo Data

Run the demo data seed script:

```bash
# Connect to your Neon PostgreSQL database
PGPASSWORD='your_password' psql -h your-host.neon.tech -U neondb_owner -d neondb -f database/seeds/demo-payment-data.sql
```

This creates:
- **5 Programs** with realistic pricing
- **100 Athletes** with varied payment scenarios
- **Payment Plans** (3-month, 6-month, seasonal, weekly)
- **Scholarships** with applications
- **Discount Codes** (EARLYBIRD20, SIBLING10, etc.)
- **Transaction History** (successful payments, failures, refunds)

### 3. Use Stub Payment Endpoints

The system automatically uses stub endpoints when `PAYMENT_MODE=demo`:

**Base URL:**
```
/api/payments-stub.php
```

**Available Actions:**
- `?action=process-payment` - Process a payment (no real charge)
- `?action=save-payment-method` - Save payment method for auto-pay
- `?action=charge-saved-method` - Charge saved payment method
- `?action=refund` - Process a refund
- `?action=test-cards` - Get list of test card numbers
- `?action=simulate-webhook` - Simulate webhook events

---

## Demo Data Breakdown

### Programs Created

| Program | Registration | Monthly Dues | Uniform | Total (3mo season) |
|---------|-------------|--------------|---------|-------------------|
| Fall Soccer U10 | $150 | $45 x 3 | $75 | $360 |
| Fall Soccer U12 | $200 | $60 x 3 | $95 | $475 |
| Winter Basketball U14 | $175 | $50 x 4 | $65 | $440 |
| Spring Baseball U11 | $160 | $40 x 3 | $80 | $360 |
| Summer Camp | $150 | - | - | $150 |

### Athlete Distribution (100 total)

- **40% Fully Paid** (40 athletes)
  - All payments completed
  - Transaction history shows successful charges
  - Status: `paid`

- **30% On Payment Plans** (30 athletes)
  - Enrolled in 3-month or 6-month plans
  - 1st installment paid, 2+ remaining
  - Status: `partial`
  - Scheduled installments in `payment_installments` table

- **15% Partial Payments** (15 athletes)
  - Made partial payment but not on payment plan
  - Status: `partial`

- **10% Overdue** (10 athletes)
  - Payment due date passed
  - No payments made
  - Status: `pending`
  - Due date in the past

- **5% Scholarship Recipients** (5 athletes)
  - 75% scholarship applied
  - Only paid 25% of total
  - Status: `paid`
  - Linked scholarship application

### Payment Plans

- **3-Month Plan** - 25% down, 3 monthly installments
- **6-Month Plan** - 15% down, 6 monthly installments
- **Seasonal Plan** - 20% down, 4 monthly installments
- **Weekly Plan** - 10% down, 8 weekly installments

### Discount Codes

| Code | Type | Amount | Times Used | Status |
|------|------|--------|-----------|--------|
| EARLYBIRD20 | Percentage | 20% | 15 | Active |
| SIBLING10 | Percentage | 10% | 8 | Active |
| SUMMER50 | Percentage | 50% | 12 | Active |
| WELCOME25 | Fixed | $25 | 23 | Active |
| RETURNPLAYER | Percentage | 15% | 34 | Active |

### Scholarships

- **Fall Soccer Needs-Based** - 50% off, 3 awarded (budget: $5,000, remaining: $3,500)
- **Basketball Excellence Award** - 75% off, 2 awarded (budget: $3,000, remaining: $2,000)
- **Full Ride Scholarship** - 100% off, 2 awarded (budget: $2,000, remaining: $1,200)
- **Equipment Grant** - $50 fixed, 12 awarded (budget: $1,500, remaining: $900)

Plus **5 pending applications** awaiting review

---

## Test Card Numbers

Use these card numbers in the checkout form to simulate different scenarios:

| Card Number | Result | Description |
|-------------|--------|-------------|
| `4242424242424242` | ✅ Success | Payment succeeds |
| `4000000000000002` | ❌ Declined | Card declined |
| `4000000000009995` | ❌ Insufficient Funds | Card has insufficient funds |
| `4000000000000069` | ❌ Expired | Card has expired |
| `4000000000000127` | ❌ Incorrect CVC | CVC is incorrect |
| `4000000000000341` | ❌ Processing Error | General processing error |

**Expiration:** Any future date (e.g., 12/2025)
**CVC:** Any 3 digits (e.g., 123)
**ZIP:** Any 5 digits (e.g., 12345)

---

## Example API Calls

### Process a Payment

```bash
curl -X POST "https://your-backend.herokuapp.com/api/payments-stub.php?action=process-payment" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 150.00,
    "athlete_payment_id": 1,
    "user_id": 1,
    "payment_method": {
      "card_number": "4242424242424242",
      "exp_month": "12",
      "exp_year": "2025",
      "cvc": "123"
    }
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Payment processed successfully (DEMO)",
  "transaction_id": 123,
  "demo_mode": true,
  "payment_result": {
    "transaction_id": "demo_txn_abc123",
    "status": "succeeded",
    "amount": 150.00,
    "receipt_url": "https://demo-receipts.teamselevated.com/abc123"
  }
}
```

### Get Test Cards

```bash
curl "https://your-backend.herokuapp.com/api/payments-stub.php?action=test-cards"
```

### Simulate Failed Payment

Use card `4000000000000002` to test error handling:

```bash
curl -X POST "https://your-backend.herokuapp.com/api/payments-stub.php?action=process-payment" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 150.00,
    "athlete_payment_id": 1,
    "user_id": 1,
    "payment_method": {
      "card_number": "4000000000000002",
      "exp_month": "12",
      "exp_year": "2025",
      "cvc": "123"
    }
  }'
```

**Response:**
```json
{
  "success": false,
  "message": "Your card was declined. Please try a different payment method.",
  "error_code": "card_declined",
  "demo_mode": true
}
```

---

## Demo Dashboard Metrics

After loading demo data, you'll see realistic metrics:

### League Admin Dashboard
- **Total Revenue:** ~$35,000
- **Collected:** ~$24,500 (70%)
- **Outstanding:** ~$10,500 (30%)
- **Athletes Enrolled:** 100
- **Active Payment Plans:** 30
- **Scholarships Awarded:** 17
- **Pending Applications:** 5

### Payment Status Breakdown
- Fully Paid: 40 athletes ($14,400)
- Payment Plans: 30 athletes ($10,500 total, $4,200 remaining)
- Partial: 15 athletes ($2,250 paid, $3,150 remaining)
- Overdue: 10 athletes ($0 paid, $3,000 overdue)
- Scholarships: 5 athletes ($450 paid vs $1,800 original)

### Revenue by Program
- Fall Soccer U12: $11,875 (25 athletes)
- Winter Basketball: $11,000 (28 athletes)
- Fall Soccer U10: $10,800 (30 athletes)
- Spring Baseball: $5,760 (16 athletes)
- Summer Camp: $1,500 (10 athletes)

---

## Switching to Live Mode

When ready to process real payments with Maverick:

### 1. Update Environment
```bash
PAYMENT_MODE=live
MAVERICK_API_KEY=your_live_api_key
MAVERICK_SECRET=your_live_secret
```

### 2. Update API Endpoints

Change from:
```javascript
const endpoint = '/api/payments-stub.php';
```

To:
```javascript
const endpoint = process.env.REACT_APP_PAYMENT_MODE === 'demo'
  ? '/api/payments-stub.php'
  : '/api/payments.php';
```

### 3. Integrate Maverick SDK

Replace `StubPaymentProcessor` with real Maverick API calls:

```php
// Before (Demo)
$processor = new StubPaymentProcessor();
$result = $processor->processPayment($amount, $paymentMethod);

// After (Live)
$processor = new MaverickPaymentProcessor($apiKey, $secret);
$result = $processor->processPayment($amount, $paymentMethod);
```

---

## Demo Mode Features

### Visual Indicators

**Show demo mode banner:**
```tsx
{process.env.REACT_APP_PAYMENT_MODE === 'demo' && (
  <div className="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4">
    <p className="font-bold">🧪 DEMO MODE</p>
    <p className="text-sm">No real payments will be processed. Use test card: 4242424242424242</p>
  </div>
)}
```

### Test Card Helper

Show available test cards in checkout:
```tsx
<TestCardHelper cards={[
  { number: '4242424242424242', label: 'Success' },
  { number: '4000000000000002', label: 'Declined' },
  { number: '4000000000009995', label: 'Insufficient Funds' }
]} />
```

### Auto-Billing Simulation

Payment plans will attempt auto-billing with 90% success rate:
```php
// 10% chance of failure for testing
$shouldFail = (rand(1, 10) === 1);
```

---

## Resetting Demo Data

To reset and reload fresh demo data:

```bash
# Run the seed script again
PGPASSWORD='your_password' psql -h your-host.neon.tech -U neondb_owner -d neondb -f database/seeds/demo-payment-data.sql
```

The script automatically truncates payment tables before inserting new data.

---

## Verification Queries

Check that demo data loaded correctly:

```sql
-- Count athletes by program
SELECT
    p.name as program_name,
    COUNT(DISTINCT ap.athlete_id) as athlete_count,
    SUM(ap.final_amount) as total_revenue,
    SUM(ap.amount_paid) as collected,
    SUM(ap.amount_remaining) as outstanding
FROM athlete_payments ap
JOIN programs p ON ap.program_id = p.id
GROUP BY p.name
ORDER BY total_revenue DESC;

-- Payment status breakdown
SELECT
    status,
    COUNT(*) as count,
    SUM(final_amount) as total_amount,
    SUM(amount_paid) as paid_amount,
    SUM(amount_remaining) as remaining_amount
FROM athlete_payments
GROUP BY status;

-- Active payment plans
SELECT
    COUNT(*) as active_payment_plans,
    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_installments,
    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as upcoming_installments
FROM payment_installments;
```

---

## Troubleshooting

### Demo data not showing up
- Verify `PAYMENT_MODE=demo` in `.env`
- Check database connection
- Ensure migration ran before seed script
- Check for SQL errors in seed script output

### Payments not processing
- Confirm using `/api/payments-stub.php` endpoint
- Check browser console for errors
- Verify test card numbers are correct
- Check backend logs

### Auto-billing not working
- Demo mode simulates auto-billing on-demand only
- Use `?action=charge-saved-method` to trigger
- Or run daily cron job script (also stubbed in demo)

---

## Next Steps

1. ✅ Load demo data
2. ✅ Test payment checkout flow
3. ✅ Review dashboards and reports
4. ✅ Test payment plans
5. ✅ Review scholarship workflow
6. ✅ Test refund processing
7. ✅ Present demo to stakeholders
8. 🔄 Begin Maverick integration for production

---

## Support

For questions about demo mode:
- Check `/database/seeds/demo-payment-data.sql` for data structure
- Review `/backend/lib/StubPaymentProcessor.php` for payment logic
- See `/backend/api/payments-stub.php` for API endpoints

Ready to go live? See `REVENUE_COLLECTION_PLAN.md` for full implementation details.
