#!/bin/bash

# Invitation System Test Suite
# Tests all invitation endpoints and flows

set -e  # Exit on error

# Configuration
API_URL="${API_URL:-http://localhost:8889}"
DB_HOST="${DB_HOST:-ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech}"
DB_USER="${DB_USER:-neondb_owner}"
DB_NAME="${DB_NAME:-neondb}"
PGPASSWORD="${PGPASSWORD:-npg_3Oe0xzCYVGlJ}"

export PGPASSWORD

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Helper functions
pass() {
    echo -e "${GREEN}✓ PASS${NC}: $1"
    ((TESTS_PASSED++))
    ((TESTS_RUN++))
}

fail() {
    echo -e "${RED}✗ FAIL${NC}: $1"
    echo -e "  ${RED}Error: $2${NC}"
    ((TESTS_FAILED++))
    ((TESTS_RUN++))
}

info() {
    echo -e "${YELLOW}ℹ INFO${NC}: $1"
}

section() {
    echo ""
    echo "========================================="
    echo "$1"
    echo "========================================="
}

# Test variables
TEST_EMAIL="test-invite-$(date +%s)@example.com"
TEST_NAME="Test User $(date +%s)"
INVITATION_ID=""
INVITATION_CODE=""
AUTH_TOKEN=""
LEAGUE_ID=""
CLUB_ID=""

# =========================================
# SETUP
# =========================================
section "SETUP: Creating Test Data"

# Create test league
info "Creating test league..."
LEAGUE_RESULT=$(PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -t -c "
INSERT INTO leagues (name, description, active, created_at)
VALUES ('Test League $(date +%s)', 'Test league for invitation tests', true, CURRENT_TIMESTAMP)
RETURNING id;
" 2>&1)

LEAGUE_ID=$(echo "$LEAGUE_RESULT" | grep -oE '[0-9]+' | head -1)
if [[ -n "$LEAGUE_ID" && "$LEAGUE_ID" =~ ^[0-9]+$ ]]; then
    pass "Created test league with ID: $LEAGUE_ID"
else
    fail "Failed to create test league" "$LEAGUE_RESULT"
    exit 1
fi

# Create test club
info "Creating test club..."
CLUB_RESULT=$(PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -t -c "
INSERT INTO club_profile (name, league_id, email, created_at)
VALUES ('Test Club $(date +%s)', $LEAGUE_ID, 'test-club@example.com', CURRENT_TIMESTAMP)
RETURNING id;
" 2>&1)

CLUB_ID=$(echo "$CLUB_RESULT" | grep -oE '[0-9]+' | head -1)
if [[ -n "$CLUB_ID" && "$CLUB_ID" =~ ^[0-9]+$ ]]; then
    pass "Created test club with ID: $CLUB_ID"
else
    fail "Failed to create test club" "$CLUB_RESULT"
    exit 1
fi

# Create test admin user
info "Creating test admin user..."
USER_RESULT=$(PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -t -c "
INSERT INTO users (first_name, last_name, email, auth_provider, created_at)
VALUES ('Test', 'Admin', 'test-admin-$(date +%s)@example.com', 'test', CURRENT_TIMESTAMP)
RETURNING id;
" 2>&1)

USER_ID=$(echo "$USER_RESULT" | grep -oE '[0-9]+' | head -1)
if [[ -n "$USER_ID" && "$USER_ID" =~ ^[0-9]+$ ]]; then
    pass "Created test user with ID: $USER_ID"
else
    fail "Failed to create test user" "$USER_RESULT"
    exit 1
fi

# Grant club admin access
PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "
INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at)
VALUES ($USER_ID, $CLUB_ID, 'club_admin', CURRENT_TIMESTAMP);
" > /dev/null 2>&1

# Generate JWT token for test user using PHP JWT library
AUTH_TOKEN_OUTPUT=$(php ./tests/generate-test-token.php $USER_ID $CLUB_ID 2>&1)
TOKEN_EXIT_CODE=$?

if [ $TOKEN_EXIT_CODE -ne 0 ]; then
    fail "Failed to generate auth token" "$AUTH_TOKEN_OUTPUT"
    exit 1
fi

# Extract only the token (last line) in case there are warnings
AUTH_TOKEN=$(echo "$AUTH_TOKEN_OUTPUT" | tail -1)

# Validate token format (should start with "eyJ")
if [[ ! "$AUTH_TOKEN" =~ ^eyJ ]]; then
    fail "Generated token has invalid format" "Token: $AUTH_TOKEN"
    exit 1
fi

pass "Generated auth token for test user"

# =========================================
# TEST 1: Send Email Invitation
# =========================================
section "TEST 1: Send Email Invitation"

RESPONSE=$(curl -s -X POST "$API_URL/api/invitations-gateway.php?action=send" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -d "{
    \"clubId\": $CLUB_ID,
    \"emails\": [\"$TEST_EMAIL\"],
    \"role\": \"coach\",
    \"personalMessage\": \"Welcome to the team!\"
  }")

if echo "$RESPONSE" | grep -q '"success":true'; then
    INVITATION_ID=$(echo "$RESPONSE" | grep -o '"invitationId":"[^"]*"' | cut -d'"' -f4 | head -1)
    if [ -n "$INVITATION_ID" ]; then
        pass "Email invitation created successfully (ID: $INVITATION_ID)"
    else
        fail "Email invitation response missing invitation ID" "$RESPONSE"
    fi
else
    fail "Failed to send email invitation" "$RESPONSE"
fi

# =========================================
# TEST 2: Get Invitation Info (Public)
# =========================================
section "TEST 2: Get Invitation Info (Public Endpoint)"

if [ -n "$INVITATION_ID" ]; then
    RESPONSE=$(curl -s "$API_URL/api/invitations-gateway.php?action=info&id=$INVITATION_ID")

    if echo "$RESPONSE" | grep -q '"success":true'; then
        if echo "$RESPONSE" | grep -q "\"email\":\"$TEST_EMAIL\""; then
            pass "Retrieved invitation info successfully"
        else
            fail "Invitation info doesn't match sent invitation" "$RESPONSE"
        fi
    else
        fail "Failed to get invitation info" "$RESPONSE"
    fi
else
    info "Skipping test (no invitation ID from previous test)"
fi

# =========================================
# TEST 3: Create Shareable Link
# =========================================
section "TEST 3: Create Shareable Invitation Link"

RESPONSE=$(curl -s -X POST "$API_URL/api/invitations-gateway.php?action=create-link" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -d "{
    \"clubId\": $CLUB_ID,
    \"role\": \"coach\",
    \"maxUses\": 5
  }")

if echo "$RESPONSE" | grep -q '"success":true'; then
    INVITATION_CODE=$(echo "$RESPONSE" | grep -o '"code":"[^"]*"' | cut -d'"' -f4)
    if [ -n "$INVITATION_CODE" ]; then
        pass "Shareable link created successfully (Code: $INVITATION_CODE)"
    else
        fail "Link creation response missing code" "$RESPONSE"
    fi
else
    fail "Failed to create shareable link" "$RESPONSE"
fi

# =========================================
# TEST 4: Get Link Info (Public)
# =========================================
section "TEST 4: Get Link Info (Public Endpoint)"

if [ -n "$INVITATION_CODE" ]; then
    RESPONSE=$(curl -s "$API_URL/api/invitations-gateway.php?action=info&code=$INVITATION_CODE")

    if echo "$RESPONSE" | grep -q '"success":true'; then
        if echo "$RESPONSE" | grep -q "\"code\":\"$INVITATION_CODE\""; then
            pass "Retrieved link info successfully"
        else
            fail "Link info doesn't match created link" "$RESPONSE"
        fi
    else
        fail "Failed to get link info" "$RESPONSE"
    fi
else
    info "Skipping test (no link code from previous test)"
fi

# =========================================
# TEST 5: Accept Email Invitation
# =========================================
section "TEST 5: Accept Email Invitation (New User)"

if [ -n "$INVITATION_ID" ]; then
    # For email invitations, the email is already in the invitation record
    # We just need to provide the name for the new user
    NEW_USER_NAME="New User $(date +%s)"

    RESPONSE=$(curl -s -X POST "$API_URL/api/invitations-gateway.php?action=accept" \
      -H "Content-Type: application/json" \
      -d "{
        \"id\": \"$INVITATION_ID\",
        \"name\": \"$NEW_USER_NAME\"
      }")

    if echo "$RESPONSE" | grep -q '"success":true'; then
        if echo "$RESPONSE" | grep -q '"token"'; then
            pass "Invitation accepted and user created with auth token"

            # Verify user was created in database (using TEST_EMAIL which was in the invitation)
            USER_CHECK=$(PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -t -c "
            SELECT COUNT(*) FROM users WHERE email = '$TEST_EMAIL';
            " 2>&1)

            if [[ $USER_CHECK =~ 1 ]]; then
                pass "New user verified in database"
            else
                fail "User not found in database after acceptance" "$USER_CHECK"
            fi

            # Verify role was assigned
            ROLE_CHECK=$(PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -t -c "
            SELECT COUNT(*) FROM user_club_access
            WHERE user_id = (SELECT id FROM users WHERE email = '$TEST_EMAIL')
            AND club_profile_id = $CLUB_ID
            AND role = 'coach';
            " 2>&1)

            if [[ $ROLE_CHECK =~ 1 ]]; then
                pass "Role assigned correctly in database"
            else
                fail "Role not assigned after acceptance" "$ROLE_CHECK"
            fi
        else
            fail "Invitation accepted but no auth token returned" "$RESPONSE"
        fi
    else
        fail "Failed to accept invitation" "$RESPONSE"
    fi
else
    info "Skipping test (no invitation ID from previous test)"
fi

# =========================================
# TEST 6: List Invitations
# =========================================
section "TEST 6: List Invitations (Admin)"

RESPONSE=$(curl -s "$API_URL/api/invitations-gateway.php?action=list&clubId=$CLUB_ID" \
  -H "Authorization: Bearer $AUTH_TOKEN")

if echo "$RESPONSE" | grep -q '"success":true'; then
    if echo "$RESPONSE" | grep -q '"invitations"'; then
        pass "Retrieved invitations list successfully"
    else
        fail "Invitations list missing from response" "$RESPONSE"
    fi
else
    fail "Failed to list invitations" "$RESPONSE"
fi

# =========================================
# TEST 7: Database Schema Validation
# =========================================
section "TEST 7: Database Schema Validation"

# Check invitations table structure
TABLE_CHECK=$(PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "\d invitations" 2>&1)

if echo "$TABLE_CHECK" | grep -q "league_id"; then
    pass "Invitations table has league_id column"
else
    fail "Invitations table missing league_id column" "$TABLE_CHECK"
fi

if echo "$TABLE_CHECK" | grep -q "club_profile_id"; then
    pass "Invitations table has club_profile_id column"
else
    fail "Invitations table missing club_profile_id column" "$TABLE_CHECK"
fi

if echo "$TABLE_CHECK" | grep -q "invited_by"; then
    pass "Invitations table has invited_by column"
else
    fail "Invitations table missing invited_by column" "$TABLE_CHECK"
fi

# Check invitation_links table
LINKS_CHECK=$(PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "\d invitation_links" 2>&1)

if echo "$LINKS_CHECK" | grep -q "code"; then
    pass "Invitation_links table has code column"
else
    fail "Invitation_links table missing code column" "$LINKS_CHECK"
fi

# =========================================
# TEST 8: Validation Tests
# =========================================
section "TEST 8: Validation & Error Handling"

# Test: Missing required fields
RESPONSE=$(curl -s -X POST "$API_URL/api/invitations-gateway.php?action=send" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -d "{}")

if echo "$RESPONSE" | grep -q "error"; then
    pass "API correctly rejects request with missing fields"
else
    fail "API should reject request with missing fields" "$RESPONSE"
fi

# Test: Invalid email format
RESPONSE=$(curl -s -X POST "$API_URL/api/invitations-gateway.php?action=send" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -d "{
    \"clubId\": $CLUB_ID,
    \"emails\": [\"invalid-email\"],
    \"role\": \"coach\"
  }")

if echo "$RESPONSE" | grep -q "error\|Invalid"; then
    pass "API correctly rejects invalid email format"
else
    # This might still succeed with an error in the errors array
    if echo "$RESPONSE" | grep -q "errors"; then
        pass "API reports invalid email in errors array"
    else
        fail "API should reject invalid email format" "$RESPONSE"
    fi
fi

# =========================================
# CLEANUP
# =========================================
section "CLEANUP: Removing Test Data"

# Delete test data
PGPASSWORD="$PGPASSWORD" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" > /dev/null 2>&1 <<EOF
DELETE FROM user_club_access WHERE club_profile_id = $CLUB_ID;
DELETE FROM invitations WHERE club_profile_id = $CLUB_ID;
DELETE FROM invitation_links WHERE club_profile_id = $CLUB_ID;
DELETE FROM club_profile WHERE id = $CLUB_ID;
DELETE FROM user_league_access WHERE league_id = $LEAGUE_ID;
DELETE FROM leagues WHERE id = $LEAGUE_ID;
DELETE FROM users WHERE email LIKE 'test-%@example.com';
EOF

pass "Test data cleaned up"

# =========================================
# SUMMARY
# =========================================
section "TEST SUMMARY"

echo ""
echo "Tests Run:    $TESTS_RUN"
echo -e "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Tests Failed: ${RED}$TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}✗ Some tests failed${NC}"
    exit 1
fi
