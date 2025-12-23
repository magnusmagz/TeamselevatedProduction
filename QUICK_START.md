# Quick Start - Payment System Demo

## Option C: Load Demo Data (10 minutes)

### Step 1: Create Payment Tables

```bash
PGPASSWORD='npg_3Oe0xzCYVGlJ' psql \
  -h ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech \
  -U neondb_owner \
  -d neondb \
  -f database/migrations/001_payment_schema.sql
```

**Expected Output:**
```
Payment schema created successfully!
```

### Step 2: Load Demo Data

```bash
PGPASSWORD='npg_3Oe0xzCYVGlJ' psql \
  -h ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech \
  -U neondb_owner \
  -d neondb \
  -f database/seeds/demo-payment-data.sql
```

**Expected Output:**
```
✅ Demo data created successfully!
```

### Step 3: Verify Data Loaded

```bash
PGPASSWORD='npg_3Oe0xzCYVGlJ' psql \
  -h ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech \
  -U neondb_owner \
  -d neondb \
  -c "SELECT COUNT(*) as athlete_count FROM athletes;"
```

Should show ~100 athletes.

```bash
PGPASSWORD='npg_3Oe0xzCYVGlJ' psql \
  -h ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech \
  -U neondb_owner \
  -d neondb \
  -c "SELECT status, COUNT(*) as count, SUM(final_amount) as total FROM athlete_payments GROUP BY status;"
```

Should show breakdown of payment statuses.

### Step 4: Deploy Stub Payment Endpoints

```bash
cd /Users/maggiemae/TeamsElevated/teamselevated

# Stage the new files
git add backend/lib/StubPaymentProcessor.php
git add backend/api/payments-stub.php
git add database/

# Commit
git commit -m "Add payment demo system

- Payment schema migration
- Demo data seed script
- Stub payment processor for demo mode
- Payment stub API endpoints

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"

# Push to Heroku
git push heroku main
```

### Step 5: Test Stub Endpoint

```bash
curl "https://teamselevated-backend-0485388bd66e.herokuapp.com/api/payments-stub.php?action=test-cards"
```

**Expected Response:**
```json
{
  "success": true,
  "test_cards": [
    {
      "number": "4242424242424242",
      "description": "Successful payment",
      "result": "success"
    },
    ...
  ],
  "demo_mode": true
}
```

---

## Option A: Build MVP Demo UI (3 days)

### 5 Screens to Build

#### 1. Admin: Payment Items List (4 hours)
**Path:** `/admin/payment-items`

**Features:**
- Table of all payment items
- Filter by program
- Columns: Name, Type, Price, Program, Active
- Read-only (uses demo data)

**API Endpoint:**
```javascript
GET /api/payment-items.php?program_id=X
```

**Component Structure:**
```tsx
<PaymentItemsList>
  <ProgramFilter />
  <PaymentItemsTable>
    <PaymentItemRow /> (foreach item)
  </PaymentItemsTable>
</PaymentItemsList>
```

---

#### 2. Parent: Athlete Payments Dashboard (6 hours)
**Path:** `/payments` or `/parent/payments`

**Features:**
- Show athlete's total outstanding balance
- List all athlete_payments (registration, dues, uniform, etc.)
- Status badges (✅ Paid, ⏳ Partial, ❌ Pending, 🚨 Overdue)
- View by athlete (if parent has multiple)
- "Pay Now" button for unpaid items

**API Endpoint:**
```javascript
GET /api/athlete-payments.php?athlete_id=X
```

**Mock Data Response:**
```json
{
  "athlete": {
    "id": 1,
    "name": "Johnny Smith",
    "total_owed": 515.00,
    "total_paid": 275.00,
    "total_remaining": 240.00
  },
  "payments": [
    {
      "id": 1,
      "program_name": "Fall Soccer",
      "item_name": "Registration",
      "amount": 200.00,
      "paid": 200.00,
      "remaining": 0,
      "status": "paid",
      "due_date": null
    },
    {
      "id": 2,
      "program_name": "Fall Soccer",
      "item_name": "Monthly Dues",
      "amount": 150.00,
      "paid": 75.00,
      "remaining": 75.00,
      "status": "partial",
      "due_date": "2024-10-15",
      "on_payment_plan": true
    }
  ]
}
```

---

#### 3. Parent: Payment Checkout (8 hours)
**Path:** `/payments/checkout`

**Features:**
- Select what to pay:
  - Specific item
  - Multiple items
  - Full balance
- Amount selector
- Test card number input
- Demo mode banner showing test cards
- Submit payment button
- Success/error messages
- View receipt

**Test Card Helper Component:**
```tsx
<div className="bg-yellow-50 p-4 rounded mb-4">
  <p className="font-semibold text-sm mb-2">🧪 DEMO MODE - Test Cards:</p>
  <div className="text-xs space-y-1">
    <div>✅ 4242 4242 4242 4242 - Success</div>
    <div>❌ 4000 0000 0000 0002 - Declined</div>
    <div>💰 4000 0000 0000 9995 - Insufficient Funds</div>
  </div>
</div>
```

**API Call:**
```javascript
POST /api/payments-stub.php?action=process-payment
{
  "athlete_payment_id": 1,
  "amount": 150.00,
  "user_id": 1,
  "payment_method": {
    "card_number": "4242424242424242",
    "exp_month": "12",
    "exp_year": "2025",
    "cvc": "123"
  }
}
```

---

#### 4. Admin: Revenue Dashboard (6 hours)
**Path:** `/admin/revenue`

**Features:**
- Summary cards:
  - Total Revenue
  - Collected
  - Outstanding
  - Collection Rate %
- Revenue by program (table or chart)
- Payment status breakdown (pie/donut chart)
- Recent transactions list

**API Endpoint:**
```javascript
GET /api/revenue-summary.php?league_id=X
```

**Mock Response:**
```json
{
  "summary": {
    "total_revenue": 35000.00,
    "collected": 24500.00,
    "outstanding": 10500.00,
    "collection_rate": 70.0
  },
  "by_program": [
    {
      "program_name": "Fall Soccer U12",
      "revenue": 11875.00,
      "collected": 8312.50,
      "outstanding": 3562.50,
      "athletes": 25
    },
    ...
  ],
  "by_status": {
    "paid": {"count": 40, "amount": 14400.00},
    "partial": {"count": 30, "amount": 6450.00},
    "pending": {"count": 25, "amount": 6750.00},
    "overdue": {"count": 10, "amount": 3000.00}
  }
}
```

**Component Structure:**
```tsx
<RevenueDashboard>
  <SummaryCards>
    <Card title="Total Revenue" value="$35,000" />
    <Card title="Collected" value="$24,500" />
    <Card title="Outstanding" value="$10,500" />
    <Card title="Collection Rate" value="70%" />
  </SummaryCards>

  <RevenueByProgram programs={data.by_program} />

  <PaymentStatusChart data={data.by_status} />

  <RecentTransactions />
</RevenueDashboard>
```

---

#### 5. Demo Mode Banner (1 hour)
**Global Component**

**Features:**
- Yellow banner at top of all pages
- Shows "🧪 DEMO MODE" prominently
- Displays test card numbers
- Dismissible but shows on refresh
- Only shows when `PAYMENT_MODE=demo`

```tsx
// components/DemoModeBanner.tsx
export function DemoModeBanner() {
  const [dismissed, setDismissed] = useState(false);

  if (process.env.REACT_APP_PAYMENT_MODE !== 'demo') return null;
  if (dismissed) return null;

  return (
    <div className="bg-yellow-100 border-b-2 border-yellow-500 p-3">
      <div className="container mx-auto flex justify-between items-center">
        <div className="flex items-center gap-3">
          <span className="text-2xl">🧪</span>
          <div>
            <p className="font-bold text-yellow-900">DEMO MODE</p>
            <p className="text-sm text-yellow-700">
              No real payments will be processed. Test card: 4242424242424242
            </p>
          </div>
        </div>
        <button
          onClick={() => setDismissed(true)}
          className="text-yellow-700 hover:text-yellow-900"
        >
          ✕
        </button>
      </div>
    </div>
  );
}
```

Add to `App.tsx`:
```tsx
function App() {
  return (
    <>
      <DemoModeBanner />
      {/* rest of app */}
    </>
  );
}
```

---

## Day-by-Day Plan

### Day 1: Foundation & Admin View
- ✅ Load demo data (done above)
- Create API endpoint for payment items
- Build Payment Items List screen (admin)
- Build Revenue Dashboard skeleton
- Build Demo Mode Banner

**Deliverable:** Admin can view payment items and see revenue summary

---

### Day 2: Parent Payment View
- Create API endpoint for athlete payments
- Build Athlete Payments Dashboard (parent)
- Show payment status for each item
- Display payment plan status
- Link to checkout

**Deliverable:** Parents can see what they owe

---

### Day 3: Payment Checkout & Polish
- Build Payment Checkout screen
- Integrate with payments-stub.php
- Test card helper UI
- Success/error handling
- Receipt view
- Polish all screens
- Test end-to-end

**Deliverable:** Full demo flow working

---

## API Endpoints to Create

You'll need to create these simple read-only endpoints (since write operations use stub):

### 1. `/api/payment-items.php`
```php
GET ?program_id=X

SELECT * FROM payment_items
WHERE program_id = ? AND active = true
ORDER BY item_type, name;
```

### 2. `/api/athlete-payments.php`
```php
GET ?athlete_id=X

SELECT
    ap.*,
    pi.name as item_name,
    pi.item_type,
    p.name as program_name
FROM athlete_payments ap
JOIN payment_items pi ON ap.payment_item_id = pi.id
JOIN programs p ON ap.program_id = p.id
WHERE ap.athlete_id = ?
ORDER BY p.name, pi.name;
```

### 3. `/api/revenue-summary.php`
```php
GET ?league_id=X

-- Summary totals
SELECT
    SUM(final_amount) as total_revenue,
    SUM(amount_paid) as collected,
    SUM(amount_remaining) as outstanding
FROM athlete_payments;

-- By program
SELECT
    p.name,
    COUNT(DISTINCT ap.athlete_id) as athletes,
    SUM(ap.final_amount) as revenue,
    SUM(ap.amount_paid) as collected,
    SUM(ap.amount_remaining) as outstanding
FROM athlete_payments ap
JOIN programs p ON ap.program_id = p.id
GROUP BY p.id, p.name;

-- By status
SELECT
    status,
    COUNT(*) as count,
    SUM(final_amount) as amount
FROM athlete_payments
GROUP BY status;
```

---

## Environment Setup

### Add to `.env` (backend)
```bash
PAYMENT_MODE=demo
```

### Add to `.env.local` (frontend)
```bash
REACT_APP_PAYMENT_MODE=demo
REACT_APP_API_URL=https://teamselevated-backend-0485388bd66e.herokuapp.com
```

---

## Testing Checklist

### After Loading Demo Data:
- [ ] 100 athletes created
- [ ] 5 programs exist
- [ ] Payment items created for each program
- [ ] Athlete payments in various statuses
- [ ] Payment plans with installments
- [ ] Scholarships and applications
- [ ] Discount codes active

### After Building UI:
- [ ] Demo banner shows on all pages
- [ ] Payment items list loads
- [ ] Revenue dashboard shows correct totals
- [ ] Parent can view athlete payments
- [ ] Checkout accepts test card
- [ ] Success payment updates database
- [ ] Failed payment shows error
- [ ] Receipt displays

---

## Ready to Start!

Run through Option C steps above to load demo data, then start building the 5 screens over 3 days.

**Questions?** Check:
- `DEMO_MODE_README.md` for full demo documentation
- `REVENUE_COLLECTION_PLAN.md` for complete implementation plan
- `PAYMENT_SYSTEM_SUMMARY.md` for overview

Let's build! 🚀
