#!/bin/bash
# Payment API Integration Tests
# Tests all payment-related API endpoints

API_URL="https://teamselevated-backend-0485388bd66e.herokuapp.com"
PASS_COUNT=0
FAIL_COUNT=0

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================="
echo "Payment API Integration Tests"
echo "========================================="
echo ""

# Helper function to test API endpoint
test_api() {
    local test_name="$1"
    local endpoint="$2"
    local expected_key="$3"

    echo -n "Testing: $test_name... "

    response=$(curl -s "$API_URL$endpoint")
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "$API_URL$endpoint")

    if [ "$http_code" != "200" ]; then
        echo -e "${RED}FAIL${NC} (HTTP $http_code)"
        FAIL_COUNT=$((FAIL_COUNT + 1))
        return 1
    fi

    if echo "$response" | grep -q "\"$expected_key\""; then
        echo -e "${GREEN}PASS${NC}"
        PASS_COUNT=$((PASS_COUNT + 1))
        return 0
    else
        echo -e "${RED}FAIL${NC} (Missing key: $expected_key)"
        echo "Response: $response"
        FAIL_COUNT=$((FAIL_COUNT + 1))
        return 1
    fi
}

# Helper function to validate JSON structure
test_json_structure() {
    local test_name="$1"
    local endpoint="$2"
    local jq_query="$3"
    local expected_value="$4"

    echo -n "Testing: $test_name... "

    response=$(curl -s "$API_URL$endpoint")
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "$API_URL$endpoint")

    if [ "$http_code" != "200" ]; then
        echo -e "${RED}FAIL${NC} (HTTP $http_code)"
        FAIL_COUNT=$((FAIL_COUNT + 1))
        return 1
    fi

    result=$(echo "$response" | python3 -c "import sys, json; data=json.load(sys.stdin); print($jq_query)" 2>/dev/null)

    if [ "$result" = "$expected_value" ]; then
        echo -e "${GREEN}PASS${NC}"
        PASS_COUNT=$((PASS_COUNT + 1))
        return 0
    else
        echo -e "${RED}FAIL${NC} (Expected: $expected_value, Got: $result)"
        FAIL_COUNT=$((FAIL_COUNT + 1))
        return 1
    fi
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "1. Revenue Summary API Tests"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Test revenue summary endpoint
test_api "Revenue summary returns success" \
    "/api/revenue-summary.php?league_id=13" \
    "success"

test_api "Revenue summary has summary data" \
    "/api/revenue-summary.php?league_id=13" \
    "summary"

test_api "Revenue summary has program breakdown" \
    "/api/revenue-summary.php?league_id=13" \
    "by_program"

test_api "Revenue summary has status breakdown" \
    "/api/revenue-summary.php?league_id=13" \
    "by_status"

test_json_structure "Revenue summary success flag is true" \
    "/api/revenue-summary.php?league_id=13" \
    "data['success']" \
    "True"

echo ""
echo -n "Testing: Revenue data has realistic values... "
response=$(curl -s "$API_URL/api/revenue-summary.php?league_id=13")
total=$(echo "$response" | python3 -c "import sys, json; data=json.load(sys.stdin); print(float(data['summary']['total_revenue']))" 2>/dev/null)
if (( $(echo "$total > 0" | bc -l) )); then
    echo -e "${GREEN}PASS${NC} (Total: \$$total)"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC} (Total: \$$total)"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "2. Athlete Payments API Tests"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Get first athlete ID from demo data
echo -n "Getting test athlete ID from demo data... "
# Query the database to get a real athlete ID
ATHLETE_ID=$(PGPASSWORD='npg_3Oe0xzCYVGlJ' psql -h ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech -U neondb_owner -d neondb -t -c "SELECT id FROM athletes WHERE league_id = 13 LIMIT 1;" 2>/dev/null | xargs)
if [ -z "$ATHLETE_ID" ]; then
    ATHLETE_ID=1  # Fallback
fi
echo "Using athlete_id=$ATHLETE_ID"

test_api "Athlete payments returns success" \
    "/api/athlete-payments.php?athlete_id=$ATHLETE_ID" \
    "success"

test_api "Athlete payments has athlete data" \
    "/api/athlete-payments.php?athlete_id=$ATHLETE_ID" \
    "athlete"

test_api "Athlete payments has payments array" \
    "/api/athlete-payments.php?athlete_id=$ATHLETE_ID" \
    "payments"

echo -n "Testing: Athlete payments missing ID returns error... "
response=$(curl -s "$API_URL/api/athlete-payments.php")
if echo "$response" | grep -q "error"; then
    echo -e "${GREEN}PASS${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "3. Payment Items API Tests"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Test with program ID 74 (from demo data)
test_api "Payment items returns success" \
    "/api/payment-items.php?program_id=74" \
    "success"

test_api "Payment items has items array" \
    "/api/payment-items.php?program_id=74" \
    "items"

echo -n "Testing: Payment items missing ID returns error... "
response=$(curl -s "$API_URL/api/payment-items.php")
if echo "$response" | grep -q "error"; then
    echo -e "${GREEN}PASS${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "4. Payment Stub API Tests"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

echo -n "Testing: Payment stub endpoint exists... "
response=$(curl -s "$API_URL/api/payments-stub.php?action=test-cards")
if echo "$response" | grep -q "4242"; then
    echo -e "${GREEN}PASS${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC}"
    echo "Response: $response"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo -n "Testing: Payment stub requires action parameter... "
response=$(curl -s "$API_URL/api/payments-stub.php")
if echo "$response" | grep -q "Invalid action"; then
    echo -e "${GREEN}PASS${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo -n "Testing: Payment stub process-payment endpoint exists... "
response=$(curl -s -X POST "$API_URL/api/payments-stub.php?action=process-payment" \
    -H "Content-Type: application/json" \
    -d '{
        "amount": 100,
        "payment_method": {
            "type": "card",
            "card_number": "4242424242424242",
            "expiry_month": "12",
            "expiry_year": "25",
            "cvv": "123"
        }
    }')

# Just check it doesn't return server error
if echo "$response" | grep -qv "Internal Server Error"; then
    echo -e "${GREEN}PASS${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC}"
    echo "Response: $response"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "5. CORS and Headers Tests"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

echo -n "Testing: Revenue API has CORS headers... "
headers=$(curl -s -I "$API_URL/api/revenue-summary.php?league_id=13")
if echo "$headers" | grep -iq "Access-Control-Allow-Origin"; then
    echo -e "${GREEN}PASS${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo -n "Testing: Payment items API has JSON content type... "
headers=$(curl -s -I "$API_URL/api/payment-items.php?program_id=74")
if echo "$headers" | grep -iq "Content-Type: application/json"; then
    echo -e "${GREEN}PASS${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo -e "${RED}FAIL${NC}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo ""
echo "========================================="
echo "Test Results Summary"
echo "========================================="
echo ""
echo -e "${GREEN}Passed: $PASS_COUNT${NC}"
echo -e "${RED}Failed: $FAIL_COUNT${NC}"
echo ""

TOTAL=$((PASS_COUNT + FAIL_COUNT))
if [ $TOTAL -gt 0 ]; then
    SUCCESS_RATE=$(echo "scale=1; $PASS_COUNT * 100 / $TOTAL" | bc)
    echo "Success Rate: $SUCCESS_RATE%"
fi
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}✗ Some tests failed${NC}"
    exit 1
fi
