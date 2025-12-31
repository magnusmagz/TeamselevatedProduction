# Payment Features Implementation Plan

## Tier 1: Core Features of Sports Team Revenue Collection

---

# Registration and Payment

## 1. Register Child for Program Online

**User Story:** As a parent, I want to register my child for a program online so they can participate in the season.

### Acceptance Criteria
- [ ] Parent can view list of available programs for their league
- [ ] Parent can select a program and see program details (dates, fees, requirements)
- [ ] Parent can select an existing child from their account or add a new child
- [ ] Parent can fill out required registration form fields
- [ ] Parent receives validation errors for incomplete/invalid fields
- [ ] Registration is saved with status "pending" until payment is completed
- [ ] Parent can see registration confirmation after successful submission

### Testing Steps
1. Log in as a parent user
2. Navigate to program registration page
3. Verify available programs are displayed with correct details
4. Select a program and verify form fields load correctly
5. Submit form with missing required fields → verify error messages appear
6. Submit form with valid data → verify registration is created in `registrations` table
7. Verify athlete record is created/linked in `athletes` table
8. Verify confirmation page displays with registration details

### Database Tables
- `registrations` (program_id, athlete_id, guardian_id, form_data, status)
- `athletes` (first_name, last_name, date_of_birth, league_id)
- `programs` (name, description, fee, start_date, end_date)

---

## 2. Pay Registration Fee Online

**User Story:** As a parent, I want to pay the registration fee online so I can complete enrollment conveniently.

### Acceptance Criteria
- [ ] Parent can see the total amount due for registration
- [ ] Parent can enter credit/debit card information securely
- [ ] Payment is processed through Maverick payment gateway
- [ ] Parent receives immediate feedback on payment success/failure
- [ ] Successful payment updates registration status to "paid"
- [ ] Payment transaction is recorded with processor reference ID
- [ ] Parent can view/download receipt after payment

### Testing Steps
1. Complete a registration to reach payment step
2. Verify correct amount is displayed
3. Enter invalid card details → verify error handling
4. Enter valid test card details → verify payment processes
5. Verify `payment_transactions` record created with status "completed"
6. Verify `athlete_payments` record updated (amount_paid, status)
7. Verify registration status changes to "approved" or "paid"
8. Verify receipt is accessible via `receipt_url`
9. Test declined card scenario → verify appropriate error message

### Database Tables
- `athlete_payments` (athlete_id, payment_item_id, final_amount, status, amount_paid)
- `payment_transactions` (athlete_payment_id, amount, maverick_transaction_id, status, receipt_url)
- `payment_items` (name, base_price, program_id)

---

## 3. Set Up Payment Plan with Automatic Installments

**User Story:** As a parent, I want to set up a payment plan so I can spread the cost over multiple automatic installments.

### Acceptance Criteria
- [ ] Parent can see payment plan options if available for the program
- [ ] Payment plan details show: number of installments, amounts, due dates
- [ ] Parent can select a payment plan during checkout
- [ ] Down payment (if required) is collected immediately
- [ ] Parent must provide card for automatic payments
- [ ] Future installments are scheduled with correct due dates
- [ ] Parent receives confirmation showing payment schedule
- [ ] Parent can view upcoming installments in their account

### Testing Steps
1. Select a program that allows payment plans
2. Verify payment plan options are displayed at checkout
3. Select a payment plan → verify installment schedule is shown
4. Complete checkout with down payment → verify transaction created
5. Verify `payment_installments` records created for future payments
6. Verify installment due dates are calculated correctly based on frequency
7. Verify `auto_pay_enabled` is set to true on installments
8. Log in as parent → verify can view payment schedule
9. Simulate installment due date → verify auto-charge attempts

### Database Tables
- `payment_plans` (name, total_installments, frequency, down_payment_percentage)
- `payment_installments` (athlete_payment_id, installment_number, amount, due_date, status, auto_pay_enabled)
- `athlete_payments` (payment_plan_id)

---

## 4. Register Multiple Children at Once

**User Story:** As a parent, I want to register multiple children at once so I can save time.

### Acceptance Criteria
- [ ] Parent can add multiple children to a single registration session
- [ ] Parent can select different programs for different children
- [ ] Parent can see combined total for all registrations
- [ ] Single checkout process for all children
- [ ] Each child gets their own registration record
- [ ] Parent receives single receipt showing all registrations
- [ ] Partial failures are handled gracefully (some succeed, some fail)

### Testing Steps
1. Log in as parent with multiple children on account
2. Start registration → add first child and program
3. Click "Add Another Child" → verify can add second child
4. Select different program for second child
5. Verify cart shows both registrations with combined total
6. Complete checkout → verify single payment transaction
7. Verify separate `registrations` records for each child
8. Verify separate `athlete_payments` records for each child
9. Verify receipt shows all children and programs

### Database Tables
- `registrations` (one per child/program)
- `athlete_payments` (one per child/program)
- `payment_transactions` (can link to multiple athlete_payments)

---

## 5. Sibling Discount

**User Story:** As a parent, I want to receive a discount when registering siblings so I pay less for my family.

### Acceptance Criteria
- [ ] System automatically detects when multiple siblings are being registered
- [ ] Sibling discount is applied automatically OR via discount code
- [ ] Discount details are shown clearly in cart (original price, discount, final price)
- [ ] Discount can be percentage-based or fixed amount
- [ ] Discount is recorded for audit purposes
- [ ] Receipt shows discount applied

### Testing Steps
1. Create a sibling discount code in admin (or configure auto-apply)
2. Register first child → verify full price shown
3. Add second child to registration → verify discount appears
4. Verify discount amount is calculated correctly
5. Complete checkout → verify `athlete_payments.discount_amount` recorded
6. Verify `athlete_payments.discount_code_id` links to discount
7. Verify receipt shows discount breakdown
8. Test discount limits (max uses, expiration)

### Database Tables
- `discount_codes` (code, discount_type, discount_value, max_uses)
- `athlete_payments` (discount_amount, discount_code_id)

---

## 6. Confirmation and Receipt

**User Story:** As a parent, I want to receive a confirmation and receipt so I have proof of payment.

### Acceptance Criteria
- [ ] Confirmation page displays immediately after successful payment
- [ ] Confirmation shows: children registered, programs, amounts paid, payment method
- [ ] Email confirmation is sent to parent's email address
- [ ] Receipt is downloadable as PDF
- [ ] Receipt includes organization details, transaction ID, date
- [ ] Receipt is accessible from parent's payment history

### Testing Steps
1. Complete a registration and payment
2. Verify confirmation page displays with correct details
3. Verify confirmation email is sent (check email or logs)
4. Click download receipt → verify PDF generates correctly
5. Verify PDF contains all required information
6. Navigate to payment history → verify receipt is accessible
7. Verify `payment_transactions.receipt_url` is populated

### Database Tables
- `payment_transactions` (receipt_url)
- `notifications` (for email tracking)

---

## 7. View Payment History and Outstanding Balance

**User Story:** As a parent, I want to view my payment history and any outstanding balance.

### Acceptance Criteria
- [ ] Parent can access payment history from their account
- [ ] History shows all past payments with date, amount, program, status
- [ ] Outstanding balances are clearly displayed
- [ ] Parent can see upcoming installment payments
- [ ] Parent can click to view receipt for any past payment
- [ ] Parent can make a payment on outstanding balance

### Testing Steps
1. Log in as parent with payment history
2. Navigate to payment history page
3. Verify past payments are listed with correct details
4. Verify outstanding balances are highlighted
5. Verify upcoming installments are shown with due dates
6. Click receipt link → verify receipt displays
7. Click pay balance → verify can complete payment
8. Verify amounts match `athlete_payments` and `payment_transactions` data

### Database Tables
- `athlete_payments` (amount_paid, amount_remaining, status)
- `payment_transactions` (amount, created_at, receipt_url)
- `payment_installments` (due_date, status)

---

# Revenue Tracking

## 8. Real-Time Payment Visibility

**User Story:** As a treasurer, I want to see all incoming payments in real time so I know our current cash position.

### Acceptance Criteria
- [ ] Dashboard shows total revenue collected (today, this week, this month)
- [ ] Recent transactions list updates without page refresh
- [ ] Can see payment method breakdown (card types)
- [ ] Can see successful vs failed payment counts
- [ ] Dashboard shows pending/processing amounts separately
- [ ] Can drill down to see individual transaction details

### Testing Steps
1. Log in as treasurer/admin
2. Navigate to revenue dashboard
3. Verify summary cards show correct totals
4. Process a new payment → verify dashboard updates
5. Verify payment appears in recent transactions list
6. Click on transaction → verify details page shows all info
7. Verify failed payments are tracked separately
8. Verify totals match database queries

### Database Tables
- `payment_transactions` (amount, status, created_at)
- `athlete_payments` (final_amount, status)

---

## 9. Payments by Program/Season

**User Story:** As a treasurer, I want to view payments organized by program or season so I can track revenue by category.

### Acceptance Criteria
- [ ] Can filter payments by program
- [ ] Can filter payments by season
- [ ] Each program/season shows: total expected, collected, outstanding
- [ ] Can see collection percentage per program
- [ ] Can export filtered data
- [ ] Visual chart showing revenue by category

### Testing Steps
1. Navigate to revenue dashboard
2. Select program filter → verify payments filter correctly
3. Select season filter → verify payments filter correctly
4. Verify totals recalculate based on filter
5. Verify collection percentage is accurate
6. Click export → verify filtered data exports correctly
7. Verify chart updates based on filters

### Database Tables
- `athlete_payments` (program_id)
- `payment_items` (program_id)
- `programs` (season_id, name)

---

## 10. Outstanding Balances

**User Story:** As a treasurer, I want to identify outstanding balances so I can follow up on unpaid registrations.

### Acceptance Criteria
- [ ] Can view list of all families with outstanding balances
- [ ] List shows: family name, athlete(s), amount owed, days overdue
- [ ] Can sort by amount owed or days overdue
- [ ] Can filter by program or team
- [ ] Can click to view family's full payment details
- [ ] Can send reminder directly from this view
- [ ] Can export list for offline follow-up

### Testing Steps
1. Navigate to outstanding balances report
2. Verify families with balances are listed
3. Verify amounts match `athlete_payments.amount_remaining`
4. Sort by amount → verify ordering
5. Sort by days overdue → verify ordering
6. Filter by program → verify list filters
7. Click family → verify payment details display
8. Click send reminder → verify reminder is sent
9. Export list → verify data is accurate

### Database Tables
- `athlete_payments` (status = 'pending' or 'partial', amount_remaining)
- `athletes` (to get family info via guardians)
- `guardians` (email, phone)

---

## 11. Automated Payment Reminders

**User Story:** As a treasurer, I want to send automated payment reminders to families with outstanding balances.

### Acceptance Criteria
- [ ] System can send automatic reminders based on configurable schedule
- [ ] Reminders are sent X days before due date (configurable)
- [ ] Reminders are sent X days after due date (configurable)
- [ ] Reminder includes: amount due, due date, payment link
- [ ] Can manually trigger reminder for specific family
- [ ] Can view history of reminders sent
- [ ] Families can opt out of reminders

### Testing Steps
1. Configure reminder schedule in admin settings
2. Create a payment with upcoming due date
3. Advance time (or trigger manually) → verify reminder sent
4. Check `notifications` table for reminder record
5. Verify email contains correct amount and payment link
6. Manually send reminder → verify it sends
7. View reminder history → verify log is accurate
8. Test opt-out functionality

### Database Tables
- `notifications` (user_id, type='payment_reminder', message)
- `payment_installments` (due_date)
- `athlete_payments` (due_date, amount_remaining)

---

## 12. Payment Failure Notifications

**User Story:** As a treasurer, I want to be notified when a payment fails, card is expired, or a card is declined.

### Acceptance Criteria
- [ ] Treasurer receives notification when auto-pay fails
- [ ] Notification includes: family name, amount, failure reason
- [ ] Parent also receives notification of failed payment
- [ ] Failed payments are flagged in the system
- [ ] Can see list of all failed payments requiring action
- [ ] System tracks retry attempts for auto-pay

### Testing Steps
1. Set up an installment with auto-pay enabled
2. Simulate card decline (expired card, insufficient funds)
3. Verify `payment_transactions` record with status='failed'
4. Verify `payment_transactions.failure_reason` is populated
5. Verify treasurer notification is created
6. Verify parent notification is created
7. Navigate to failed payments list → verify payment appears
8. Verify `payment_installments.auto_pay_attempts` increments

### Database Tables
- `payment_transactions` (status='failed', failure_reason)
- `payment_installments` (auto_pay_attempts, next_retry_at)
- `notifications` (type='payment_failed')

---

## 13. Process Refunds

**User Story:** As a treasurer, I want to process refunds and have them automatically reflected in the financials.

### Acceptance Criteria
- [ ] Treasurer can initiate refund from transaction details
- [ ] Can specify full or partial refund amount
- [ ] Must provide refund reason
- [ ] Refund is processed through payment gateway
- [ ] Original payment record is updated with refund info
- [ ] Revenue totals are automatically adjusted
- [ ] Parent receives refund confirmation

### Testing Steps
1. Navigate to a completed payment transaction
2. Click refund → verify refund form appears
3. Enter refund amount exceeding original → verify error
4. Enter valid partial refund amount with reason
5. Submit refund → verify processed through gateway
6. Verify `payment_transactions.refund_amount` updated
7. Verify `payment_transactions.refund_reason` recorded
8. Verify `payment_transactions.refunded_at` timestamp set
9. Verify revenue dashboard totals decrease
10. Verify parent receives refund notification

### Database Tables
- `payment_transactions` (refund_amount, refund_reason, refunded_at)
- `athlete_payments` (status may change to 'refunded')

---

## 14. Reconcile Payment Processor Deposits

**User Story:** As a treasurer, I want to reconcile payment processor deposits with registrations so the books are accurate.

### Acceptance Criteria
- [ ] Can view list of payment processor payouts/deposits
- [ ] Each payout shows included transactions
- [ ] Can match payout to bank deposit
- [ ] Can see processing fees deducted
- [ ] Discrepancies are highlighted
- [ ] Can mark payout as reconciled
- [ ] Reconciliation status is tracked

### Testing Steps
1. Navigate to reconciliation page
2. Verify payouts from Maverick are listed
3. Click payout → verify transactions are listed
4. Verify gross amount, fees, and net amount shown
5. Enter bank deposit amount → verify matches
6. If discrepancy, verify it's highlighted
7. Mark as reconciled → verify status updates
8. Verify reconciled payouts are tracked separately

### Database Tables
- `payment_transactions` (maverick_transaction_id)
- **NEW FIELD NEEDED:** `payment_transactions.payout_id` or new `payouts` table

---

## 15. Export Financial Data

**User Story:** As a treasurer, I want to export financial data to CSV or QuickBooks so I can use external accounting tools.

### Acceptance Criteria
- [ ] Can export transactions to CSV
- [ ] Can filter data before export (date range, program, status)
- [ ] CSV includes all relevant fields for accounting
- [ ] Can export in QuickBooks IIF format
- [ ] Export includes header row with column names
- [ ] Large exports are handled without timeout

### Testing Steps
1. Navigate to export page
2. Select date range and filters
3. Click export CSV → verify file downloads
4. Open CSV → verify columns are correct
5. Verify data matches filtered view
6. Test QuickBooks format export
7. Test large date range → verify completes

### Database Tables
- All payment tables for data source
- `financial_reports` (can store generated report metadata)

---

# Reporting

## 16. Deposit Report

**User Story:** As a treasurer, I want to generate a deposit report showing all transactions in each payout.

### Acceptance Criteria
- [ ] Can select a date range for deposits
- [ ] Report shows each deposit with date and amount
- [ ] Can drill down to see transactions in each deposit
- [ ] Shows gross amount, processing fees, net deposit
- [ ] Can print or export report
- [ ] Report totals match bank statements

### Testing Steps
1. Navigate to deposit report
2. Select date range
3. Verify deposits are listed
4. Click deposit → verify transactions shown
5. Verify fee calculations are accurate
6. Print report → verify format is clean
7. Export report → verify data is complete
8. Compare to bank statement → verify totals match

### Database Tables
- `payment_transactions`
- **NEW:** `payouts` table or payout tracking

---

## 17. Transaction Report

**User Story:** As a treasurer, I want to generate a transaction report filtered by date, program, or payment type.

### Acceptance Criteria
- [ ] Can filter by date range
- [ ] Can filter by program
- [ ] Can filter by payment type (full, installment, refund)
- [ ] Can filter by status (completed, failed, pending)
- [ ] Report shows all transaction details
- [ ] Can export filtered report
- [ ] Report includes totals for filtered data

### Testing Steps
1. Navigate to transaction report
2. Apply date filter → verify results filter
3. Apply program filter → verify results filter
4. Apply payment type filter → verify results filter
5. Combine multiple filters → verify AND logic
6. Verify totals update based on filters
7. Export → verify filtered data exports

### Database Tables
- `payment_transactions`
- `athlete_payments`
- `payment_items`

---

## 18. Roster with Fee Status

**User Story:** As a treasurer, I want to see roster counts alongside collected fees so I can verify everyone has paid.

### Acceptance Criteria
- [ ] Can view roster by program or team
- [ ] Each athlete shows payment status (paid, partial, unpaid)
- [ ] Summary shows: total athletes, paid count, unpaid count
- [ ] Shows total collected vs total expected
- [ ] Can filter to show only unpaid athletes
- [ ] Can click athlete to see payment details

### Testing Steps
1. Navigate to roster fee report
2. Select program or team
3. Verify all athletes are listed with payment status
4. Verify counts are accurate
5. Verify collected/expected amounts match data
6. Filter to unpaid → verify list filters
7. Click athlete → verify payment details shown
8. Cross-reference with registration data

### Database Tables
- `athletes`
- `athlete_payments`
- `registrations`
- `team_members`

---

# Permissions and Access Control

## 19. User Permissions for Financial Data

**User Story:** As an administrator, I want to set user permissions so only authorized people access financial data.

### Acceptance Criteria
- [ ] Can define roles with specific permissions
- [ ] Financial permissions include: view, create, refund, export
- [ ] Can assign roles to users
- [ ] Users without permission cannot access financial pages
- [ ] API endpoints enforce permissions
- [ ] Audit log tracks permission changes

### Testing Steps
1. Create role with limited financial permissions
2. Assign role to test user
3. Log in as test user
4. Verify can access allowed features
5. Verify blocked from restricted features
6. Try API call to restricted endpoint → verify 403
7. Check audit log for permission assignment

### Database Tables
- `user_roles` (role, permissions)
- `user_league_access`
- `team_audit_log`

---

## 20. Organization-Level Admin Access

**User Story:** As an administrator, I want to grant organization-level admin access to specific staff members.

### Acceptance Criteria
- [ ] Can grant full admin access at league level
- [ ] Admin can access all financial data across org
- [ ] Admin can manage other users' permissions
- [ ] Admin can access all programs and teams
- [ ] Can revoke admin access

### Testing Steps
1. Grant org admin to user via `user_league_access`
2. Log in as that user
3. Verify access to all org financial data
4. Verify can manage user permissions
5. Verify can access all programs
6. Revoke access → verify user loses access

### Database Tables
- `user_league_access` (user_id, league_id, role)

---

## 21. Division/Program-Level Access

**User Story:** As an administrator, I want to grant division or program-level access with restricted permissions.

### Acceptance Criteria
- [ ] Can assign user to specific program(s)
- [ ] User can only see data for assigned program(s)
- [ ] User cannot see org-wide financial summaries
- [ ] User can manage registrations for their program
- [ ] User can view payments for their program only

### Testing Steps
1. Create program-level access for user
2. Log in as that user
3. Verify can see assigned program data
4. Verify cannot see other programs
5. Verify cannot see org-level reports
6. Attempt to access other program → verify blocked

### Database Tables
- **NEW:** `user_program_access` (user_id, program_id, permissions)

---

## 22. Team Owner Invoice Access

**User Story:** As an administrator, I want team owners to manage their own team's invoices without accessing org-level financials.

### Acceptance Criteria
- [ ] Team owner can view their team's payment status
- [ ] Team owner can see which families have paid
- [ ] Team owner cannot see actual payment amounts (optional)
- [ ] Team owner cannot access other teams' data
- [ ] Team owner cannot process refunds
- [ ] Team owner can send payment reminders to their team

### Testing Steps
1. Assign team owner role to user
2. Log in as team owner
3. Verify can see team roster with payment status
4. Verify cannot see org financial dashboard
5. Verify cannot access other teams
6. Verify cannot process refunds
7. Send reminder → verify it sends

### Database Tables
- `team_members` (user_id, team_id, role='coach' or 'manager')
- **NEW:** team-level financial permissions

---

# Non-Registration Revenue

## 23. Record Sponsorship Payments

**User Story:** As a treasurer, I want to record sponsorship payments so all income is captured.

### Acceptance Criteria
- [ ] Can create a sponsorship record with sponsor details
- [ ] Can record payment amount and date
- [ ] Can categorize sponsorship (season, team, event)
- [ ] Sponsorship revenue appears in financial reports
- [ ] Can attach sponsorship agreement document
- [ ] Can track multi-payment sponsorships

### Testing Steps
1. Navigate to non-registration revenue
2. Add new sponsorship
3. Enter sponsor name, amount, category
4. Save → verify record created
5. View financial reports → verify included
6. Upload agreement document → verify attached
7. Add second payment → verify tracked

### Database Tables
- **NEW:** `sponsorships` (sponsor_name, amount, category, season_id, created_at)
- **NEW:** `sponsorship_payments` (sponsorship_id, amount, paid_at)

---

## 24. Record Scholarships

**User Story:** As a treasurer, I want to record scholarships so that all scholarships are captured.

### Acceptance Criteria
- [ ] Can create scholarship fund with total budget
- [ ] Can define scholarship criteria
- [ ] Can award scholarship to specific athlete
- [ ] Scholarship amount reduces athlete's payment
- [ ] Scholarship usage is tracked against budget
- [ ] Can generate scholarship report

### Testing Steps
1. Create scholarship fund
2. Set budget and criteria
3. Award to athlete during registration
4. Verify `athlete_payments.scholarship_amount` set
5. Verify scholarship budget decreases
6. Generate report → verify accurate

### Database Tables
- `scholarships` (name, total_budget, remaining_budget)
- `scholarship_applications` (scholarship_id, athlete_id, approved_amount)
- `athlete_payments` (scholarship_application_id, scholarship_amount)

---

## 25. Accept Donations

**User Story:** As a treasurer, I want to accept donations through the registration platform.

### Acceptance Criteria
- [ ] Donation option available during checkout
- [ ] Parent can enter custom donation amount
- [ ] Preset donation amounts available
- [ ] Donation is processed with payment
- [ ] Donation revenue tracked separately from registration fees
- [ ] Donation receipt provided for tax purposes

### Testing Steps
1. Complete registration to checkout
2. Verify donation option appears
3. Select preset amount or enter custom
4. Complete payment → verify donation processed
5. Verify donation tracked separately in reports
6. Verify receipt includes donation details

### Database Tables
- **NEW:** `donations` (amount, donor_user_id, program_id, created_at)
- OR use `payment_items` with item_type='donation'

---

## 26. Create Mid-Season Invoices

**User Story:** As a treasurer, I want to create invoices for additional fees during the season.

### Acceptance Criteria
- [ ] Can create one-off invoice for athlete or team
- [ ] Invoice includes description and amount
- [ ] Invoice is sent to parent email
- [ ] Parent can pay invoice online
- [ ] Invoice payment tracked in financials
- [ ] Can create bulk invoices for team

### Testing Steps
1. Navigate to invoicing
2. Select athlete or team
3. Enter invoice details (description, amount)
4. Send invoice → verify email sent
5. Log in as parent → verify invoice visible
6. Pay invoice → verify payment processed
7. Verify appears in financial reports
8. Test bulk invoice for team

### Database Tables
- `payment_items` (item_type='invoice', athlete_id or team_id)
- `athlete_payments`
- `payment_transactions`

---

# Implementation Priority

## Phase 1 (Ready Now)
1. Register child for program
2. Pay registration fee online
3. View payment history
4. Real-time payment visibility
5. Outstanding balances
6. Process refunds

## Phase 2 (Ready Now)
7. Payment plans with installments
8. Sibling discount
9. Confirmation and receipt
10. Payments by program/season
11. Transaction report

## Phase 3 (Minor Schema Changes)
12. Register multiple children
13. Automated payment reminders
14. Payment failure notifications
15. Roster with fee status
16. User permissions

## Phase 4 (Schema Additions Needed)
17. Reconcile deposits (needs payout tracking)
18. Deposit report (needs payout tracking)
19. Division/program-level access
20. Team owner access
21. Sponsorship payments
22. Accept donations
