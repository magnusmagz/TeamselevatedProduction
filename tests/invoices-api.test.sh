#!/bin/bash
# Invoice API Tests
# Tests the invoicing system endpoints

API_URL="${API_URL:-https://teamselevated-backend-0485388bd66e.herokuapp.com}"
PASSED=0
FAILED=0

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================"
echo "Invoice API Tests"
echo "API URL: $API_URL"
echo "========================================"
echo ""

# Test helper function
test_endpoint() {
    local name="$1"
    local url="$2"
    local expected="$3"
    local method="${4:-GET}"
    local data="$5"

    echo -n "Testing: $name... "

    if [ "$method" == "POST" ]; then
        response=$(curl -s -X POST -H "Content-Type: application/json" -d "$data" "$url")
    else
        response=$(curl -s "$url")
    fi

    if echo "$response" | grep -q "$expected"; then
        echo -e "${GREEN}PASSED${NC}"
        ((PASSED++))
        return 0
    else
        echo -e "${RED}FAILED${NC}"
        echo "  Expected: $expected"
        echo "  Got: $response" | head -c 200
        echo ""
        ((FAILED++))
        return 1
    fi
}

# Test helper for checking JSON structure
test_json_field() {
    local name="$1"
    local url="$2"
    local field="$3"

    echo -n "Testing: $name... "

    response=$(curl -s "$url")

    if echo "$response" | grep -q "\"$field\""; then
        echo -e "${GREEN}PASSED${NC}"
        ((PASSED++))
        return 0
    else
        echo -e "${RED}FAILED${NC}"
        echo "  Expected field: $field"
        echo "  Got: $response" | head -c 200
        echo ""
        ((FAILED++))
        return 1
    fi
}

echo "--- Basic API Tests ---"
echo ""

# Test 1: List invoices requires parameters
test_endpoint \
    "List invoices - requires athlete_id or league_id" \
    "$API_URL/api/invoices.php" \
    "athlete_id, guardian_id, or league_id is required"

# Test 2: List invoices with league_id
test_json_field \
    "List invoices by league" \
    "$API_URL/api/invoices.php?league_id=13" \
    "success"

# Test 3: List invoices returns summary
test_json_field \
    "List invoices returns summary" \
    "$API_URL/api/invoices.php?league_id=13" \
    "summary"

# Test 4: List invoices returns invoices array
test_json_field \
    "List invoices returns invoices array" \
    "$API_URL/api/invoices.php?league_id=13" \
    "invoices"

echo ""
echo "--- Invoice Creation Tests ---"
echo ""

# Test 5: Create invoice requires POST
test_endpoint \
    "Create invoice - requires POST method" \
    "$API_URL/api/invoices.php?action=create" \
    "POST method required"

# Test 6: Create invoice with data
CREATE_RESPONSE=$(curl -s -X POST -H "Content-Type: application/json" \
    -d '{"athlete_id": 1, "league_id": 13, "subtotal": 100, "total_amount": 100, "due_date": "2026-02-01", "memo": "Test invoice"}' \
    "$API_URL/api/invoices.php?action=create")

if echo "$CREATE_RESPONSE" | grep -q "invoice_number"; then
    echo -e "Testing: Create invoice with data... ${GREEN}PASSED${NC}"
    ((PASSED++))

    # Extract invoice ID for subsequent tests
    INVOICE_ID=$(echo "$CREATE_RESPONSE" | grep -o '"invoice_id":[0-9]*' | grep -o '[0-9]*')
    INVOICE_NUMBER=$(echo "$CREATE_RESPONSE" | grep -o '"invoice_number":"[^"]*"' | cut -d'"' -f4)
    echo "  Created invoice: $INVOICE_NUMBER (ID: $INVOICE_ID)"
else
    echo -e "Testing: Create invoice with data... ${RED}FAILED${NC}"
    echo "  Response: $CREATE_RESPONSE"
    ((FAILED++))
    INVOICE_ID=""
fi

echo ""
echo "--- Invoice Retrieval Tests ---"
echo ""

# Test 7: Get invoice details
if [ -n "$INVOICE_ID" ]; then
    test_json_field \
        "Get invoice details" \
        "$API_URL/api/invoices.php?action=get&id=$INVOICE_ID" \
        "invoice_number"

    test_json_field \
        "Get invoice includes items array" \
        "$API_URL/api/invoices.php?action=get&id=$INVOICE_ID" \
        "items"
else
    echo -e "${YELLOW}Skipping get tests - no invoice created${NC}"
fi

# Test 8: Get non-existent invoice
test_endpoint \
    "Get non-existent invoice returns error" \
    "$API_URL/api/invoices.php?action=get&id=99999" \
    "Invoice not found"

echo ""
echo "--- Invoice Status Tests ---"
echo ""

# Test 9: Mark invoice as viewed
if [ -n "$INVOICE_ID" ]; then
    VIEWED_RESPONSE=$(curl -s -X POST "$API_URL/api/invoices.php?action=mark-viewed&id=$INVOICE_ID")
    if echo "$VIEWED_RESPONSE" | grep -q "success"; then
        echo -e "Testing: Mark invoice as viewed... ${GREEN}PASSED${NC}"
        ((PASSED++))
    else
        echo -e "Testing: Mark invoice as viewed... ${RED}FAILED${NC}"
        echo "  Response: $VIEWED_RESPONSE"
        ((FAILED++))
    fi
else
    echo -e "${YELLOW}Skipping mark-viewed test - no invoice created${NC}"
fi

echo ""
echo "--- Family Invoice Tests ---"
echo ""

# Test 10: Family invoices requires guardian_id
test_endpoint \
    "Family invoices - requires guardian_id" \
    "$API_URL/api/invoices.php?action=family" \
    "guardian_id is required"

# Test 11: Family invoices with guardian_id
test_json_field \
    "Family invoices returns athletes" \
    "$API_URL/api/invoices.php?action=family&guardian_id=1" \
    "athletes"

test_json_field \
    "Family invoices returns summary" \
    "$API_URL/api/invoices.php?action=family&guardian_id=1" \
    "summary"

echo ""
echo "--- Invoice Send Tests ---"
echo ""

# Test 12: Send invoice requires POST
test_endpoint \
    "Send invoice - requires POST method" \
    "$API_URL/api/invoices.php?action=send&id=1" \
    "POST method required"

echo ""
echo "========================================"
echo "Test Results"
echo "========================================"
echo -e "Passed: ${GREEN}$PASSED${NC}"
echo -e "Failed: ${RED}$FAILED${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed${NC}"
    exit 1
fi
