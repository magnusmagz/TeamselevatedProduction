# Revenue Collection & Reporting Implementation Plan
## Teams Elevated Financial Management System

---

## Executive Summary

This plan outlines a comprehensive revenue collection and reporting system for Teams Elevated, based on industry best practices from platforms like Ollie Sports, LeagueApps, TeamLinkt, and SportsEngine. The system will support flexible payment options, payment plans, scholarships, automated billing, and multi-level financial reporting across the league-club-team hierarchy.

---

## Research Findings: Industry Best Practices

### Ollie Sports Approach
- **Automated payment systems** allowing families to pay fees directly through the app
- **Customizable registration** forms with integrated fee collection
- **Real-time financial tracking** with clear audit trails
- **Security-first** approach with latest protocols
- **Centralized management** for all payments and registrations
- **Automated reminders** for payment deadlines

### Common Features Across Leading Platforms

**Payment Processing:**
- Maverick Payments integration (similar to Ollie Sports)
- Multiple payment methods: Credit/Debit cards, ACH/e-check
- Custom pricing per business
- Competitive processing rates (often lower than Stripe)
- Full-service processor with in-house operations

**Payment Plans:**
- Flexible installment options
- Automated billing with stored payment methods
- Customizable terms per program/season
- Automated reminders before payments
- Auto-pay enrollment
- Mid-season pro-rating for late registrants

**Financial Management:**
- Real-time dashboards and reporting
- Revenue tracking by program/team/season
- Automated reconciliation
- Audit trails and financial statements
- Reduce month-end accounting time by 50%
- Transaction history and receipts

**Additional Revenue Features:**
- Discount codes (early bird, sibling, multi-team)
- Late registration fees
- Add-on purchases (uniforms, equipment, events)
- Fundraising campaign management
- Sponsorship tracking
- Online stores for merchandise

---

## System Architecture

### Payment Hierarchy

```
League Level
├─ League-wide fees (membership, insurance)
├─ League-managed scholarship funds
└─ Clubs
    ├─ Club fees (registration, admin)
    ├─ Club scholarship programs
    └─ Teams
        ├─ Team fees (coaching, equipment, tournaments)
        ├─ Travel expenses
        └─ Individual athlete payments
```

### Revenue Collection Points

1. **Registration Fees** - Initial signup per season/program
2. **Recurring Fees** - Monthly/quarterly dues
3. **Event Fees** - Tournaments, camps, clinics
4. **Add-on Items** - Uniforms, gear, photos
5. **Fundraising** - Campaigns, donations
6. **Sponsorships** - Corporate/individual sponsors
7. **Late Fees** - Penalties for overdue payments

---

## Database Schema Design

### New Tables

#### `payment_items`
**Purpose:** Define what can be charged (registration, dues, uniforms, etc.)
```sql
CREATE TABLE payment_items (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    item_type VARCHAR(50) NOT NULL, -- 'registration', 'dues', 'uniform', 'tournament', 'donation', 'merchandise', 'late_fee'
    base_price DECIMAL(10,2) NOT NULL,

    -- Scoping
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),
    team_id INTEGER REFERENCES teams(id),
    program_id INTEGER REFERENCES programs(id),

    -- Configuration
    is_recurring BOOLEAN DEFAULT false,
    recurring_frequency VARCHAR(20), -- 'monthly', 'quarterly', 'annual'
    is_required BOOLEAN DEFAULT true,
    allow_payment_plan BOOLEAN DEFAULT false,

    -- Availability
    available_from TIMESTAMP,
    available_to TIMESTAMP,
    max_quantity INTEGER,

    -- Maverick Payments integration
    maverick_product_id VARCHAR(255),
    maverick_price_id VARCHAR(255),

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `payment_plans`
**Purpose:** Define installment payment options
```sql
CREATE TABLE payment_plans (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,

    -- Plan structure
    total_installments INTEGER NOT NULL,
    frequency VARCHAR(20) NOT NULL, -- 'weekly', 'biweekly', 'monthly'
    down_payment_percentage DECIMAL(5,2) DEFAULT 0.00, -- 0-100

    -- Scoping (which items can use this plan)
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),

    -- Configuration
    auto_pay_required BOOLEAN DEFAULT true,
    late_fee_amount DECIMAL(10,2) DEFAULT 0.00,
    grace_period_days INTEGER DEFAULT 3,

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `scholarships`
**Purpose:** Financial aid programs
```sql
CREATE TABLE scholarships (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,

    -- Scoping
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),

    -- Fund details
    total_budget DECIMAL(10,2),
    remaining_budget DECIMAL(10,2),

    -- Award structure
    award_type VARCHAR(20) NOT NULL, -- 'percentage', 'fixed_amount', 'full'
    award_percentage DECIMAL(5,2), -- if percentage
    award_amount DECIMAL(10,2), -- if fixed
    max_awards INTEGER,
    awards_given INTEGER DEFAULT 0,

    -- Eligibility
    eligibility_criteria TEXT,
    requires_application BOOLEAN DEFAULT true,
    application_deadline TIMESTAMP,

    -- Season/Program
    season_id INTEGER REFERENCES seasons(id),
    program_id INTEGER REFERENCES programs(id),

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `scholarship_applications`
**Purpose:** Track scholarship requests
```sql
CREATE TABLE scholarship_applications (
    id SERIAL PRIMARY KEY,
    scholarship_id INTEGER REFERENCES scholarships(id) ON DELETE CASCADE,

    -- Applicant
    user_id INTEGER REFERENCES users(id),
    athlete_id INTEGER REFERENCES athletes(id),

    -- Application details
    application_data JSONB, -- flexible field for form responses
    financial_need_statement TEXT,
    supporting_documents JSONB, -- array of file URLs

    -- Review
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'under_review', 'approved', 'denied'
    reviewed_by INTEGER REFERENCES users(id),
    reviewed_at TIMESTAMP,
    review_notes TEXT,

    -- Award
    approved_amount DECIMAL(10,2),
    approved_percentage DECIMAL(5,2),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `athlete_payments`
**Purpose:** Master record of what each athlete owes
```sql
CREATE TABLE athlete_payments (
    id SERIAL PRIMARY KEY,
    athlete_id INTEGER REFERENCES athletes(id) ON DELETE CASCADE,
    payment_item_id INTEGER REFERENCES payment_items(id),
    program_id INTEGER REFERENCES programs(id), -- Direct program tracking for reporting

    -- Amount details
    base_amount DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    scholarship_amount DECIMAL(10,2) DEFAULT 0.00,
    final_amount DECIMAL(10,2) NOT NULL, -- base - discount - scholarship

    -- Payment plan
    payment_plan_id INTEGER REFERENCES payment_plans(id),

    -- References
    scholarship_application_id INTEGER REFERENCES scholarship_applications(id),
    discount_code_id INTEGER REFERENCES discount_codes(id),

    -- Status
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'partial', 'paid', 'refunded', 'waived'
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    amount_remaining DECIMAL(10,2),

    -- Dates
    due_date TIMESTAMP,
    paid_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `payment_transactions`
**Purpose:** Individual payment records
```sql
CREATE TABLE payment_transactions (
    id SERIAL PRIMARY KEY,
    athlete_payment_id INTEGER REFERENCES athlete_payments(id),

    -- Payment details
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50), -- 'credit_card', 'debit_card', 'ach', 'cash', 'check', 'waived'

    -- Maverick Payments integration
    maverick_transaction_id VARCHAR(255) UNIQUE,
    maverick_charge_id VARCHAR(255),
    maverick_customer_id VARCHAR(255),
    maverick_payment_method_id VARCHAR(255),

    -- Transaction info
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processing', 'succeeded', 'failed', 'refunded', 'canceled'
    failure_reason TEXT,
    receipt_url TEXT,

    -- Metadata
    payment_type VARCHAR(20), -- 'full', 'installment', 'partial'
    installment_number INTEGER, -- if part of payment plan
    paid_by_user_id INTEGER REFERENCES users(id),

    -- Refunds
    refund_amount DECIMAL(10,2) DEFAULT 0.00,
    refund_reason TEXT,
    refunded_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `payment_installments`
**Purpose:** Scheduled payments for payment plans
```sql
CREATE TABLE payment_installments (
    id SERIAL PRIMARY KEY,
    athlete_payment_id INTEGER REFERENCES athlete_payments(id) ON DELETE CASCADE,
    payment_plan_id INTEGER REFERENCES payment_plans(id),

    -- Installment details
    installment_number INTEGER NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date TIMESTAMP NOT NULL,

    -- Status
    status VARCHAR(20) DEFAULT 'scheduled', -- 'scheduled', 'processing', 'paid', 'failed', 'late', 'waived'
    paid_amount DECIMAL(10,2) DEFAULT 0.00,
    paid_at TIMESTAMP,

    -- Late fees
    late_fee_assessed DECIMAL(10,2) DEFAULT 0.00,
    late_fee_paid DECIMAL(10,2) DEFAULT 0.00,

    -- Auto-pay
    auto_pay_enabled BOOLEAN DEFAULT false,
    auto_pay_attempts INTEGER DEFAULT 0,
    next_retry_at TIMESTAMP,

    -- Transaction reference
    transaction_id INTEGER REFERENCES payment_transactions(id),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `discount_codes`
**Purpose:** Promotional and special pricing
```sql
CREATE TABLE discount_codes (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,

    -- Discount structure
    discount_type VARCHAR(20) NOT NULL, -- 'percentage', 'fixed_amount'
    discount_value DECIMAL(10,2) NOT NULL,
    max_discount_amount DECIMAL(10,2), -- cap for percentage discounts

    -- Usage limits
    max_uses INTEGER,
    times_used INTEGER DEFAULT 0,
    max_uses_per_user INTEGER DEFAULT 1,

    -- Scoping
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),
    applicable_item_types TEXT[], -- which payment_item types it applies to

    -- Validity
    valid_from TIMESTAMP,
    valid_to TIMESTAMP,

    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### `financial_reports`
**Purpose:** Cached/generated financial summaries
```sql
CREATE TABLE financial_reports (
    id SERIAL PRIMARY KEY,

    -- Report scope
    report_type VARCHAR(50) NOT NULL, -- 'revenue_summary', 'outstanding_payments', 'scholarship_usage', 'payment_plan_status'
    league_id INTEGER REFERENCES leagues(id),
    club_id INTEGER REFERENCES club_profile(id),
    team_id INTEGER REFERENCES teams(id),

    -- Time period
    period_start TIMESTAMP NOT NULL,
    period_end TIMESTAMP NOT NULL,

    -- Report data (JSON for flexibility)
    report_data JSONB NOT NULL,

    -- Metadata
    generated_by INTEGER REFERENCES users(id),
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## Payment Workflows

### 1. Registration with Payment

**Parent Flow:**
1. Parent registers athlete for program/team
2. System calculates total fees (registration + required items)
3. Shows available payment options:
   - Pay in full (with early bird discount if applicable)
   - Payment plan options
   - Apply discount code
   - Request scholarship
4. Parent selects payment method
5. If payment plan: Selects plan, enables auto-pay (required)
6. System processes initial payment (or down payment)
7. Creates payment schedule for installments
8. Sends confirmation email with receipt and schedule

**Backend Process:**
```
1. Create athlete_payments records for each payment_item
2. Apply discounts/scholarships to calculate final_amount
3. If payment plan selected:
   - Create payment_installments for each scheduled payment
   - Store payment method in Stripe for auto-billing
4. Process initial payment via Stripe
5. Create payment_transactions record
6. Update athlete_payments status
7. Send receipt and schedule
8. Schedule reminder emails
```

### 2. Payment Plan Auto-Billing

**Automated Process (Daily Cron Job):**
```
1. Query payment_installments due today or overdue
2. For each installment with auto_pay_enabled:
   - Retrieve stored Stripe payment method
   - Attempt charge via Stripe API
   - If successful:
     * Create payment_transactions record
     * Update installment status to 'paid'
     * Update athlete_payments amount_paid and amount_remaining
     * Send receipt email
   - If failed:
     * Update installment status to 'failed'
     * Increment auto_pay_attempts
     * Schedule retry (3 attempts total)
     * Send payment failed notification
3. Apply late fees after grace period
4. Send upcoming payment reminders (3 days before)
```

### 3. Scholarship Application & Approval

**Applicant Flow:**
1. Parent/athlete applies for scholarship
2. Fills out application form with financial need statement
3. Uploads supporting documents
4. Submits application

**Admin Review Flow:**
1. League/club admin views pending applications
2. Reviews criteria and supporting documents
3. Approves/denies with award amount
4. System updates scholarship remaining_budget
5. Creates scholarship_applications record
6. Applies scholarship to athlete_payments
7. Recalculates final_amount and payment schedule
8. Notifies family of decision

### 4. Manual Payments (Cash/Check)

**Admin Flow:**
1. Admin navigates to athlete payment record
2. Records manual payment
3. Selects payment method (cash/check)
4. Enters amount and check number/reference
5. System creates payment_transactions record
6. Updates athlete_payments status
7. Generates receipt for family

### 5. Refunds

**Admin Flow:**
1. Admin initiates refund from transaction record
2. Enters refund amount and reason
3. System processes refund via Stripe (if card payment)
4. Updates payment_transactions with refund details
5. Adjusts athlete_payments amount_paid
6. Sends refund confirmation email

---

## Stripe Integration

### Setup Requirements

1. **Stripe Account**
   - Create Stripe account
   - Complete business verification
   - Set up bank account for payouts

2. **API Keys**
   - Store in environment variables:
     * `STRIPE_SECRET_KEY`
     * `STRIPE_PUBLISHABLE_KEY`
     * `STRIPE_WEBHOOK_SECRET`

3. **Webhooks**
   - Configure webhook endpoint: `/api/stripe-webhook.php`
   - Subscribe to events:
     * `payment_intent.succeeded`
     * `payment_intent.payment_failed`
     * `charge.refunded`
     * `customer.subscription.deleted`
     * `invoice.payment_succeeded`
     * `invoice.payment_failed`

### Stripe Integration Architecture

```
Frontend (React)
    ↓
  Stripe.js (PCI compliant)
    ↓
  Create PaymentIntent (Backend API)
    ↓
  Stripe API
    ↓
  Webhook to Backend
    ↓
  Update Database
```

### Key Stripe Features to Use

1. **Payment Intents** - For one-time payments
2. **Setup Intents** - To save payment methods for future use
3. **Customers** - Create Stripe customer for each parent/user
4. **Payment Methods** - Store cards/ACH for auto-billing
5. **Invoices** - For payment plan installments
6. **Products & Prices** - Sync payment_items to Stripe
7. **Refunds** - Process refunds programmatically

---

## Reporting & Analytics

### Real-Time Dashboards

#### League Admin Dashboard
- **Total Revenue** across all clubs
- **Outstanding Payments** by club
- **Scholarship Fund Usage**
- **Payment Plan Status** (on-track vs delinquent)
- **Revenue by Program Type**
- **Monthly Revenue Trends**

#### Club Admin Dashboard
- **Total Revenue** for their club
- **Outstanding Payments** by team
- **Top Revenue Sources** (registration, tournaments, etc.)
- **Payment Completion Rate**
- **Scholarship Budget Remaining**
- **Overdue Payments Alert**

#### Team Coach Dashboard
- **Team Revenue Status**
- **Athlete Payment Status** (who has/hasn't paid)
- **Upcoming Payment Deadlines**

### Standard Reports

1. **Revenue Summary Report**
   - Total collected by period
   - Breakdown by payment type
   - Revenue by team/program
   - Export to CSV/Excel

2. **Outstanding Payments Report**
   - All unpaid/partial payments
   - Sorted by amount and due date
   - Contact information for follow-up
   - Aging report (30/60/90 days)

3. **Payment Plan Status Report**
   - Active payment plans
   - Completed vs on-track vs delinquent
   - Failed payment attempts
   - Upcoming installments

4. **Scholarship Usage Report**
   - Total scholarships awarded
   - Budget used vs remaining
   - Awards by program/team
   - Application statistics

5. **Transaction Report**
   - All transactions by date range
   - Refunds and adjustments
   - Payment method breakdown
   - Audit trail for accounting

6. **Tax Documentation**
   - Annual receipts for families
   - 1099 preparation data
   - Donation tracking (if applicable)

### Export Capabilities

- CSV/Excel for all reports
- PDF receipts and invoices
- QuickBooks integration (future)
- Monthly financial statements

---

## User Interfaces

### Parent/Guardian Portal

**My Payments Dashboard:**
- Outstanding balance summary
- Payment history
- Upcoming payments (if on payment plan)
- Downloadable receipts
- Saved payment methods
- Apply discount code
- Request scholarship

**Make a Payment:**
- Select athlete (if multiple)
- View amount due
- Choose payment amount (full/partial/scheduled)
- Enter payment details (Stripe Elements)
- Review and confirm
- Receive instant receipt

**Payment Plans:**
- View payment schedule
- See upcoming installments
- Manage auto-pay settings
- Make early payment
- View payment history

**Scholarships:**
- Browse available scholarships
- Submit application
- Upload documents
- Check application status

### League/Club Admin Portal

**Revenue Overview:**
- Dashboard with key metrics
- Revenue charts and trends
- Quick stats (total, outstanding, collected %)

**Payment Items Management:**
- Create/edit payment items
- Set prices and availability
- Configure payment plan eligibility
- Sync with Stripe products

**Payment Plans:**
- Create plan templates
- Set installment schedules
- Configure auto-pay requirements
- Set late fee policies

**Scholarships:**
- Create scholarship programs
- Set budget and criteria
- Review applications
- Award scholarships
- Track usage

**Payment Management:**
- View all athlete payments
- Record manual payments
- Process refunds
- Send payment reminders
- Export data

**Reports:**
- Access all standard reports
- Filter by date, club, team, program
- Schedule automated reports (email)
- Export to various formats

### Coach Interface

**Team Payments:**
- View team roster with payment status
- See who has outstanding balances
- Payment status indicators (paid/partial/overdue)
- Send gentle reminders (template emails)

---

## Notification System

### Automated Emails

1. **Payment Confirmation**
   - Sent immediately after successful payment
   - Includes receipt and transaction details
   - PDF attachment option

2. **Payment Schedule**
   - Sent when payment plan is enrolled
   - Shows all installment dates and amounts
   - Calendar invite attachments

3. **Upcoming Payment Reminder**
   - 3 days before installment due date
   - Includes amount and due date
   - Link to make payment

4. **Payment Failed Notification**
   - Sent when auto-pay fails
   - Instructions to update payment method
   - Link to update card

5. **Late Payment Notice**
   - After grace period expires
   - Includes late fee if applicable
   - Urgency messaging

6. **Payment Received**
   - Confirmation of installment payment
   - Updated balance information
   - Next payment due date

7. **Scholarship Application Received**
   - Confirms application submitted
   - Expected review timeline
   - Next steps

8. **Scholarship Decision**
   - Approval or denial notification
   - Award amount if approved
   - Updated payment schedule

9. **Refund Processed**
   - Confirmation of refund
   - Amount and reason
   - Expected timing (5-10 business days)

### In-App Notifications

- Payment due reminders
- Failed payment alerts
- Scholarship status updates
- Receipt available notifications

---

## Discount & Promotion Features

### Discount Code Types

1. **Early Bird Discount**
   - Register before deadline for X% off
   - Automatically applied based on registration date

2. **Sibling Discount**
   - Automatic discount for multiple athletes in same family
   - Tiered: 2nd child 10%, 3rd+ 15%

3. **Multi-Team Discount**
   - Discount for athletes playing on multiple teams
   - Configured by league/club

4. **Returning Player Discount**
   - Loyalty discount for returning athletes
   - Based on previous season participation

5. **Promo Codes**
   - Custom codes for marketing campaigns
   - Limited usage and date ranges
   - Shareable codes

6. **Referral Discounts**
   - Give $X off, get $X off
   - Track referral source

### Automatic Discount Application

- System checks eligibility on checkout
- Displays available discounts
- Allows stacking (if configured)
- Shows savings breakdown

---

## Financial Compliance & Security

### PCI Compliance

- **Never store card numbers** - Use Stripe tokens
- All payment forms use Stripe Elements (PCI-compliant)
- SSL/TLS encryption for all transactions
- Regular security audits

### Data Protection

- Encrypt sensitive financial data at rest
- Role-based access to financial information
- Audit logging for all financial transactions
- Secure webhook signature verification

### Financial Controls

- Multi-level approval for refunds over threshold
- Separate permissions for viewing vs processing payments
- Transaction logs with user attribution
- End-of-day reconciliation reports
- Bank deposit matching

### Tax Compliance

- Track tax-deductible donations separately
- Generate annual tax receipts
- Store records for 7 years
- Support for sales tax if applicable

---

## Implementation Phases

### Phase 1: Foundation (Weeks 1-2)
**Database & Stripe Setup**

- [ ] Create all database tables and relationships
- [ ] Set up Stripe account and API integration
- [ ] Create Stripe webhook endpoint
- [ ] Implement basic payment intent creation
- [ ] Test Stripe test mode transactions

**Deliverables:**
- Migration scripts for all tables
- Stripe integration library
- Webhook handler
- Test payment flow

### Phase 2: Core Payment Features (Weeks 3-4)
**One-Time Payments**

- [ ] Payment items CRUD (admin)
- [ ] Athlete payment creation on registration
- [ ] Payment checkout page (parent)
- [ ] Stripe payment processing
- [ ] Receipt generation and email
- [ ] Payment history view

**Deliverables:**
- Payment item management UI
- Checkout flow (frontend + backend)
- Receipt system
- Email templates

### Phase 3: Payment Plans (Weeks 5-6)
**Installment Payments**

- [ ] Payment plan templates (admin)
- [ ] Payment plan selection at checkout
- [ ] Save payment method for future use
- [ ] Generate installment schedules
- [ ] Auto-billing cron job
- [ ] Failed payment handling and retries
- [ ] Late fee assessment
- [ ] Payment plan dashboard (parent)

**Deliverables:**
- Payment plan management UI
- Installment scheduler
- Auto-billing system
- Retry logic
- Parent payment plan portal

### Phase 4: Scholarships & Discounts (Weeks 7-8)
**Financial Aid & Promotions**

- [ ] Scholarship program CRUD (admin)
- [ ] Application form builder
- [ ] Document upload system
- [ ] Application review workflow
- [ ] Scholarship approval and application to payments
- [ ] Discount code system
- [ ] Automatic discount application
- [ ] Scholarship reporting

**Deliverables:**
- Scholarship management UI
- Application portal
- Review workflow
- Discount engine
- Award tracking

### Phase 5: Reporting & Analytics (Weeks 9-10)
**Financial Dashboards**

- [ ] Real-time revenue dashboards
- [ ] Standard report generation
- [ ] Export to CSV/Excel/PDF
- [ ] Scheduled report emails
- [ ] Outstanding payments report
- [ ] Payment plan status report
- [ ] Scholarship usage report
- [ ] Transaction audit report

**Deliverables:**
- Dashboard components
- Report generation engine
- Export functionality
- Email scheduling

### Phase 6: Advanced Features (Weeks 11-12)
**Additional Capabilities**

- [ ] Manual payment recording (cash/check)
- [ ] Refund processing
- [ ] Payment reminders system
- [ ] Bulk payment actions
- [ ] Family account consolidation
- [ ] Multi-athlete payment bundling
- [ ] Payment method management
- [ ] Mobile-optimized payment flow

**Deliverables:**
- Admin payment management tools
- Refund workflow
- Notification engine
- Mobile responsive UI

### Phase 7: Testing & Refinement (Weeks 13-14)
**Quality Assurance**

- [ ] End-to-end payment testing
- [ ] Stripe webhook testing
- [ ] Payment plan automation testing
- [ ] Load testing for high-volume periods
- [ ] Security audit
- [ ] User acceptance testing
- [ ] Documentation
- [ ] Admin training materials

**Deliverables:**
- Test reports
- Security audit results
- User documentation
- Admin guides

---

## Technical Implementation Details

### Backend API Endpoints

```
Payment Items:
GET    /api/payment-items.php?league_id=X          - List payment items
GET    /api/payment-items.php?id=X                 - Get payment item
POST   /api/payment-items.php                      - Create payment item
PUT    /api/payment-items.php?id=X                 - Update payment item
DELETE /api/payment-items.php?id=X                 - Delete payment item

Payments:
GET    /api/payments.php?athlete_id=X              - Get athlete payments
POST   /api/payments.php                           - Create payment
POST   /api/payments/process.php                   - Process payment with Stripe
POST   /api/payments/refund.php                    - Refund payment
POST   /api/payments/manual.php                    - Record manual payment

Payment Plans:
GET    /api/payment-plans.php                      - List plans
POST   /api/payment-plans.php                      - Create plan
GET    /api/payment-plans/installments.php?id=X    - Get installments
POST   /api/payment-plans/enroll.php               - Enroll in plan

Scholarships:
GET    /api/scholarships.php                       - List scholarships
POST   /api/scholarships.php                       - Create scholarship
POST   /api/scholarships/apply.php                 - Submit application
GET    /api/scholarships/applications.php          - List applications
PUT    /api/scholarships/applications.php?id=X     - Review application

Discount Codes:
GET    /api/discount-codes.php                     - List codes
POST   /api/discount-codes.php                     - Create code
POST   /api/discount-codes/validate.php            - Validate code

Reports:
GET    /api/reports/revenue.php                    - Revenue report
GET    /api/reports/outstanding.php                - Outstanding payments
GET    /api/reports/payment-plans.php              - Payment plan status
GET    /api/reports/scholarships.php               - Scholarship usage
GET    /api/reports/transactions.php               - Transaction history

Stripe Webhooks:
POST   /api/stripe-webhook.php                     - Handle Stripe events
```

### Frontend Components

```
Pages:
/payments                       - Parent payment dashboard
/payments/checkout              - Payment checkout flow
/payments/history               - Payment history
/payments/plans                 - Payment plan management
/scholarships                   - Browse/apply for scholarships
/admin/revenue                  - Admin revenue dashboard
/admin/payment-items            - Manage payment items
/admin/payment-plans            - Manage payment plans
/admin/scholarships             - Manage scholarships
/admin/payments                 - Payment management
/admin/reports                  - Financial reports

Components:
<PaymentCheckout />             - Stripe checkout form
<PaymentPlanSelector />         - Choose payment plan
<InstallmentSchedule />         - Display payment schedule
<ScholarshipApplicationForm />  - Apply for scholarship
<DiscountCodeInput />           - Enter promo code
<PaymentHistory />              - List transactions
<RevenueDashboard />            - Charts and metrics
<OutstandingPaymentsTable />    - Unpaid balances
<RefundModal />                 - Process refund
<ManualPaymentForm />           - Record cash/check
```

---

## Cron Jobs / Scheduled Tasks

### Daily Tasks

**Auto-Billing (runs at 6 AM EST):**
```php
// Process due installments
foreach (due_installments as $installment) {
    if ($installment->auto_pay_enabled) {
        charge_payment_method($installment);
        if (success) {
            mark_installment_paid();
            send_receipt_email();
        } else {
            schedule_retry();
            send_failed_payment_email();
        }
    }
}
```

**Late Fee Assessment (runs at 12 AM EST):**
```php
// Apply late fees after grace period
foreach (overdue_installments as $installment) {
    if (days_overdue > grace_period) {
        assess_late_fee($installment);
        send_late_notice_email();
    }
}
```

**Payment Reminders (runs at 8 AM EST):**
```php
// Send reminders 3 days before due date
foreach (upcoming_installments as $installment) {
    if (due_in_days == 3) {
        send_reminder_email($installment);
    }
}
```

**Failed Payment Retries (runs every 6 hours):**
```php
// Retry failed payments (up to 3 attempts)
foreach (failed_installments as $installment) {
    if (retry_attempts < 3 && time > next_retry_at) {
        retry_charge($installment);
        increment_retry_attempts();
    }
}
```

### Weekly Tasks

**Overdue Payment Summary (runs Monday 9 AM):**
```php
// Send summary to admins
generate_overdue_report();
send_to_league_admins();
send_to_club_admins();
```

**Revenue Summary (runs Monday 9 AM):**
```php
// Weekly revenue summary
generate_revenue_report(last_7_days);
send_to_admins();
```

### Monthly Tasks

**Financial Statements (runs 1st of month):**
```php
// Generate monthly statements
generate_monthly_statement();
send_to_admins();
archive_report();
```

**Scholarship Budget Review (runs 1st of month):**
```php
// Review scholarship budgets
check_scholarship_budgets();
alert_if_depleted();
```

---

## Cost Analysis

### Stripe Fees

**Standard Processing:**
- 2.9% + $0.30 per successful card charge
- 0.8% per successful ACH charge (capped at $5)

**Example Calculations:**
- $100 registration: $3.20 fee (keep $96.80)
- $500 seasonal dues: $14.80 fee (keep $485.20)
- $1000 tournament fee: $29.30 fee (keep $970.70)

**Volume Pricing:**
- Available for organizations processing $80k+/month
- Contact Stripe for custom rates

### Pass-Through Fee Options

**Option 1: League/Club Absorbs Fees**
- Simplest for families
- Budget for fees in pricing

**Option 2: Add Convenience Fee**
- Add 3% to total at checkout
- Clearly disclose to families
- Check local regulations

**Option 3: Cash/Check Discount**
- Offer small discount for non-card payments
- Incentivize lower-cost payment methods

### Revenue Projections

**Example League (10 clubs, 100 teams, 1500 athletes):**

Assumptions:
- $200 average registration fee
- $50 monthly dues (9 months)
- 60% use payment plans
- 10% receive scholarships (average 30% off)

**Annual Revenue:**
- Registrations: 1500 × $200 = $300,000
- Monthly dues: 1500 × $50 × 9 = $675,000
- **Total: $975,000**

**Stripe Fees (estimate):**
- ~3% of total = $29,250

**Net Revenue:**
- $945,750

**Scholarship Awards:**
- 150 athletes × $200 × 30% = $9,000 (registrations)
- 150 athletes × $450 × 30% = $20,250 (dues)
- **Total scholarships: $29,250**

**Collection Efficiency:**
- With automated billing: 95% collection rate
- Manual billing: 75-80% collection rate
- **Improvement: +15-20% revenue collected**

---

## Success Metrics

### Key Performance Indicators (KPIs)

**Revenue Metrics:**
- Total revenue collected
- Revenue by source (registration, dues, events, etc.)
- Month-over-month growth
- Collection rate (% of owed amount collected)

**Payment Plan Metrics:**
- % of families on payment plans
- Payment plan completion rate
- Failed payment rate
- Average days to full payment

**Scholarship Metrics:**
- Applications received
- Awards granted
- Total scholarship amount
- Scholarship budget utilization

**Operational Metrics:**
- Average payment processing time
- Failed payment retry success rate
- Refund processing time
- Time saved on manual accounting (target: 50% reduction)

**User Satisfaction:**
- Payment experience rating
- Number of payment support tickets
- Payment abandonment rate
- NPS for financial features

---

## Risk Mitigation

### Financial Risks

**Chargeback Prevention:**
- Clear refund policy
- Detailed transaction descriptions
- Prompt customer support
- Documented scholarship awards

**Failed Payment Management:**
- Require auto-pay for payment plans
- Send reminders before charge
- Multiple retry attempts
- Clear late fee policy

**Scholarship Budget Overruns:**
- Set hard budget limits
- Track awards in real-time
- Approval workflow
- Regular budget reviews

### Technical Risks

**Stripe Downtime:**
- Monitor Stripe status
- Display status to users
- Queue failed attempts for retry
- Maintain manual payment option

**Data Loss:**
- Daily database backups
- Transaction log redundancy
- Audit trail preservation
- Disaster recovery plan

**Security Breach:**
- PCI compliance (via Stripe)
- Regular security audits
- Encrypted data at rest
- Access controls and logging

---

## Future Enhancements

### Phase 8+ (Future Roadmap)

**Advanced Features:**
- QuickBooks/Xero integration
- Automated 1099 generation
- Multi-currency support (international teams)
- Cryptocurrency payment option
- Recurring subscriptions (annual membership)
- Automated scholarship eligibility checks
- SMS payment reminders
- Parent payment portal mobile app
- Customizable invoice templates
- Batch payment processing
- Layaway program for expensive items
- Peer-to-peer fundraising tools
- Grant application management
- Sponsor payment tracking
- Automatic tax receipt generation

**Machine Learning:**
- Predict payment failures
- Optimize payment plan terms
- Scholarship fraud detection
- Revenue forecasting

---

## Training & Documentation

### Admin Training Required

1. **Payment Item Setup**
   - Creating registration fees
   - Setting up recurring dues
   - Configuring event fees

2. **Payment Plan Management**
   - Creating plan templates
   - Understanding auto-billing
   - Handling failed payments

3. **Scholarship Administration**
   - Creating programs
   - Reviewing applications
   - Awarding scholarships

4. **Financial Reporting**
   - Running standard reports
   - Interpreting dashboards
   - Exporting data

5. **Payment Processing**
   - Recording manual payments
   - Processing refunds
   - Handling disputes

### Parent/Family Resources

1. **How to Make a Payment** (video + article)
2. **Understanding Payment Plans** (FAQ)
3. **Applying for Scholarships** (guide)
4. **Using Discount Codes** (quick tip)
5. **Managing Payment Methods** (tutorial)
6. **Viewing Payment History** (guide)

---

## Conclusion & Next Steps

This comprehensive revenue collection system will position Teams Elevated as a competitive platform in the youth sports management space, matching or exceeding the capabilities of industry leaders like Ollie Sports, LeagueApps, and SportsEngine.

**Immediate Next Steps:**

1. **Review & Approval**
   - Review this plan with stakeholders
   - Prioritize features (MVP vs future)
   - Approve budget and timeline

2. **Stripe Account Setup**
   - Create Stripe account
   - Complete business verification
   - Configure test environment

3. **Database Design Finalization**
   - Review schema with development team
   - Identify any additional requirements
   - Create migration scripts

4. **Begin Phase 1**
   - Start with database and Stripe foundation
   - Build incrementally
   - Test thoroughly at each phase

**Estimated Timeline:** 14 weeks for full implementation
**Estimated Cost:** Stripe fees (~3% of transactions)

---

## Sources

Research for this plan was compiled from:

- [Streamline Payments and Registration through Ollie](https://www.olliesports.com/post/streamline-payments-and-registration-through-ollie)
- [Youth Sports Registration with Stripe and TeamLinkt](https://teamlinkt.com/blog/youth-sports-registration-with-stripe-and-teamlinkt)
- [Payments | LeagueApps](https://leagueapps.com/youth-sports-management-platform/payments/)
- [Online Team Sports Accounting Software | SportsEngine](https://www.sportsengine.com/hq/features/financials/)
- [Registration & Payments - Sprocket Sports](https://sprocketsports.com/solutions/registration-and-payments)
- [Best Youth Sports Platforms for Collecting Payments in 2024](https://sportsplus.app/blog/122/best-youth-sports-platforms-for-collecting-payments-in-2024)
