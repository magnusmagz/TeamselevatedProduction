#!/bin/bash

# Tryout System Test Database Setup
# Usage: ./run_test_setup.sh [database_name]

DB_NAME=${1:-teamselevated_test}
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATIONS_DIR="$SCRIPT_DIR/../migrations"

echo "============================================"
echo "Tryout System Test Database Setup"
echo "============================================"
echo ""

# Check if PostgreSQL is available
if ! command -v psql &> /dev/null; then
    echo "Error: PostgreSQL (psql) is not installed or not in PATH"
    exit 1
fi

echo "Step 1: Creating database '$DB_NAME'..."
dropdb --if-exists $DB_NAME 2>/dev/null
createdb $DB_NAME

if [ $? -ne 0 ]; then
    echo "Error: Failed to create database"
    exit 1
fi
echo "  Database created."
echo ""

echo "Step 2: Running base test schema..."
psql -d $DB_NAME -f "$SCRIPT_DIR/setup_test_db.sql" -q
if [ $? -ne 0 ]; then
    echo "Error: Failed to run base schema"
    exit 1
fi
echo "  Base schema applied."
echo ""

echo "Step 3: Running tryout system migration (009)..."
psql -d $DB_NAME -f "$MIGRATIONS_DIR/009_tryout_system.sql" -q
if [ $? -ne 0 ]; then
    echo "Error: Failed to run tryout migration"
    exit 1
fi
echo "  Tryout migration applied."
echo ""

echo "Step 4: Seeding test data..."
psql -d $DB_NAME -f "$SCRIPT_DIR/seed_tryout_data.sql" -q
if [ $? -ne 0 ]; then
    echo "Error: Failed to seed data"
    exit 1
fi
echo "  Test data seeded."
echo ""

echo "============================================"
echo "Test database setup complete!"
echo "============================================"
echo ""
echo "Database: $DB_NAME"
echo ""
echo "Quick verification:"
psql -d $DB_NAME -c "SELECT 'Programs' as table_name, COUNT(*) as count FROM programs WHERE type='tryout'
UNION ALL SELECT 'Registrations', COUNT(*) FROM registrations WHERE program_id=100
UNION ALL SELECT 'Evaluations', COUNT(*) FROM tryout_evaluations
UNION ALL SELECT 'Criteria', COUNT(*) FROM tryout_evaluation_criteria
UNION ALL SELECT 'Sessions', COUNT(*) FROM tryout_sessions;"

echo ""
echo "To connect: psql -d $DB_NAME"
echo ""
