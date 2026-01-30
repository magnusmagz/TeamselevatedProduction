#!/bin/bash

# Tryout System - Full Test Suite
# Usage: ./tests/run_all_tests.sh [--skip-db] [--skip-api] [--skip-frontend]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

SKIP_DB=false
SKIP_API=false
SKIP_FRONTEND=false
API_URL="http://localhost:8889"

# Parse arguments
for arg in "$@"; do
    case $arg in
        --skip-db) SKIP_DB=true ;;
        --skip-api) SKIP_API=true ;;
        --skip-frontend) SKIP_FRONTEND=true ;;
        --api-url=*) API_URL="${arg#*=}" ;;
    esac
done

echo "============================================"
echo "Tryout System - Full Test Suite"
echo "============================================"
echo ""

TOTAL_PASSED=0
TOTAL_FAILED=0

# ============================================
# 1. Database Tests
# ============================================
if [ "$SKIP_DB" = false ]; then
    echo "[1/3] DATABASE TESTS"
    echo "--------------------------------------------"

    # Reset and setup test database
    echo "Setting up test database..."
    "$ROOT_DIR/database/test/run_test_setup.sh" teamselevated_test > /dev/null 2>&1

    if [ $? -eq 0 ]; then
        echo "  ✓ Test database setup successful"
        ((TOTAL_PASSED++))

        # Verify tables exist
        TABLES=$(psql -d teamselevated_test -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE 'tryout%'")
        TABLES=$(echo $TABLES | tr -d ' ')

        if [ "$TABLES" -ge 4 ]; then
            echo "  ✓ Tryout tables created ($TABLES tables)"
            ((TOTAL_PASSED++))
        else
            echo "  ✗ Missing tryout tables (found $TABLES)"
            ((TOTAL_FAILED++))
        fi

        # Verify triggers exist
        TRIGGERS=$(psql -d teamselevated_test -t -c "SELECT COUNT(*) FROM pg_trigger WHERE tgname LIKE 'trigger_tryout%'")
        TRIGGERS=$(echo $TRIGGERS | tr -d ' ')

        if [ "$TRIGGERS" -ge 4 ]; then
            echo "  ✓ Triggers created ($TRIGGERS triggers)"
            ((TOTAL_PASSED++))
        else
            echo "  ✗ Missing triggers (found $TRIGGERS)"
            ((TOTAL_FAILED++))
        fi

        # Verify seed data
        REGISTRATIONS=$(psql -d teamselevated_test -t -c "SELECT COUNT(*) FROM registrations WHERE program_id=100")
        REGISTRATIONS=$(echo $REGISTRATIONS | tr -d ' ')

        if [ "$REGISTRATIONS" -ge 8 ]; then
            echo "  ✓ Seed data loaded ($REGISTRATIONS registrations)"
            ((TOTAL_PASSED++))
        else
            echo "  ✗ Seed data missing (found $REGISTRATIONS registrations)"
            ((TOTAL_FAILED++))
        fi
    else
        echo "  ✗ Test database setup failed"
        ((TOTAL_FAILED++))
    fi

    echo ""
fi

# ============================================
# 2. API Tests
# ============================================
if [ "$SKIP_API" = false ]; then
    echo "[2/3] API TESTS"
    echo "--------------------------------------------"

    # Check if PHP server is running
    if curl -s "$API_URL" > /dev/null 2>&1; then
        echo "  API server running at $API_URL"

        # Run PHP API tests
        if [ -f "$SCRIPT_DIR/TryoutApiTest.php" ]; then
            php "$SCRIPT_DIR/TryoutApiTest.php" "$API_URL"

            if [ $? -eq 0 ]; then
                ((TOTAL_PASSED++))
            else
                ((TOTAL_FAILED++))
            fi
        else
            echo "  ⚠ API test file not found"
        fi
    else
        echo "  ⚠ API server not running at $API_URL"
        echo "    Start with: php -S localhost:8889 -t $ROOT_DIR"
        echo "    Then re-run tests without --skip-api"
        echo ""
    fi

    echo ""
fi

# ============================================
# 3. Frontend Tests
# ============================================
if [ "$SKIP_FRONTEND" = false ]; then
    echo "[3/3] FRONTEND TESTS"
    echo "--------------------------------------------"

    cd "$ROOT_DIR/frontend"

    if [ -f "package.json" ]; then
        # Check if node_modules exists
        if [ ! -d "node_modules" ]; then
            echo "  Installing dependencies..."
            npm install --silent
        fi

        echo "  Running React component tests..."
        npm test -- --watchAll=false --testPathPattern="registration/components/__tests__" 2>&1

        if [ $? -eq 0 ]; then
            ((TOTAL_PASSED++))
        else
            ((TOTAL_FAILED++))
        fi
    else
        echo "  ⚠ Frontend package.json not found"
    fi

    cd "$ROOT_DIR"
    echo ""
fi

# ============================================
# Summary
# ============================================
echo "============================================"
echo "TEST SUMMARY"
echo "============================================"
echo "Sections Passed: $TOTAL_PASSED"
echo "Sections Failed: $TOTAL_FAILED"
echo ""

if [ $TOTAL_FAILED -eq 0 ]; then
    echo "All tests passed! ✓"
    exit 0
else
    echo "Some tests failed. ✗"
    exit 1
fi
