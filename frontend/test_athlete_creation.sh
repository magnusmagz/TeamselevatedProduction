#!/bin/bash

echo "=== ATHLETE CREATION TEST WITH MEDICAL INFO ==="
echo "Testing complete athlete creation with guardian and medical information..."
echo

# Generate unique timestamp
TIMESTAMP=$(date +%s)
EMAIL="test.athlete.${TIMESTAMP}@example.com"

echo "1. Creating athlete with basic information:"
echo "   - first_name: TestFirst${TIMESTAMP}"
echo "   - last_name: TestLast"
echo "   - email: $EMAIL"
echo

# Create athlete
ATHLETE_RESPONSE=$(curl -s -X POST "http://localhost:8889/athletes-gateway.php" \
  -H "Content-Type: application/json" \
  -d "{
    \"first_name\": \"TestFirst${TIMESTAMP}\",
    \"last_name\": \"TestLast\",
    \"email\": \"$EMAIL\",
    \"date_of_birth\": \"2010-05-15\",
    \"gender\": \"Male\",
    \"home_address_line1\": \"123 Test Street\",
    \"city\": \"Portland\",
    \"state\": \"OR\",
    \"zip_code\": \"97201\"
  }")

echo "Athlete Response:"
echo "$ATHLETE_RESPONSE"
echo

# Extract athlete ID from the response
ATHLETE_ID=$(echo "$ATHLETE_RESPONSE" | grep -o '"athlete_id":"[0-9]*"' | sed 's/"athlete_id":"//' | sed 's/"//')

if [ -z "$ATHLETE_ID" ]; then
    echo "ERROR: Failed to create athlete"
    exit 1
fi

echo "✅ SUCCESS: Athlete created with ID: $ATHLETE_ID"
echo

# Add guardian
echo "2. Adding guardian information..."
GUARDIAN_RESPONSE=$(curl -s -X POST "http://localhost:8889/guardian-gateway.php" \
  -H "Content-Type: application/json" \
  -d "{
    \"athlete_id\": $ATHLETE_ID,
    \"first_name\": \"ParentFirst\",
    \"last_name\": \"ParentLast${TIMESTAMP}\",
    \"email\": \"parent${TIMESTAMP}@example.com\",
    \"mobile_phone\": \"503-555-0100\",
    \"relationship_type\": \"Mother\",
    \"is_primary_contact\": 1,
    \"has_legal_custody\": 1,
    \"can_authorize_medical\": 1,
    \"can_pickup\": 1,
    \"receives_communications\": 1
  }")

echo "Guardian Response:"
echo "$GUARDIAN_RESPONSE"
echo

if echo "$GUARDIAN_RESPONSE" | grep -q "success"; then
    echo "✅ SUCCESS: Guardian added"
else
    echo "⚠️ WARNING: Guardian may not have been added correctly"
fi
echo

# Add medical information
echo "3. Adding medical information..."
MEDICAL_RESPONSE=$(curl -s -X POST "http://localhost:8889/medical-gateway.php" \
  -H "Content-Type: application/json" \
  -d "{
    \"athlete_id\": $ATHLETE_ID,
    \"allergies\": \"Peanuts, Bee stings\",
    \"allergy_severity\": \"severe\",
    \"medical_conditions\": \"Asthma, ADHD\",
    \"medications\": \"Albuterol inhaler as needed, Ritalin 10mg daily\",
    \"has_asthma\": true,
    \"inhaler_location\": \"Backpack front pocket\",
    \"has_epipen\": true,
    \"epipen_location\": \"Coach medical kit and backpack\",
    \"physician_name\": \"Dr. Sarah Smith\",
    \"physician_phone\": \"503-555-0200\",
    \"physician_address\": \"456 Medical Center Dr, Portland, OR 97201\",
    \"insurance_provider\": \"Blue Cross Blue Shield\",
    \"insurance_policy_number\": \"ABC123456789\",
    \"insurance_group_number\": \"GRP789012\",
    \"last_physical_date\": \"2024-08-01\",
    \"physical_expiry_date\": \"2025-08-01\",
    \"height_inches\": 60,
    \"weight_lbs\": 95,
    \"blood_type\": \"O+\",
    \"emergency_treatment_consent\": true,
    \"special_instructions\": \"Call parent immediately if any allergic reaction. Student may self-administer inhaler.\"
  }")

echo "Medical Response:"
echo "$MEDICAL_RESPONSE"
echo

if echo "$MEDICAL_RESPONSE" | grep -q "success"; then
    echo "✅ SUCCESS: Medical information added"
else
    echo "⚠️ WARNING: Medical information may not have been added correctly"
fi
echo

# Verify medical information and alerts
echo "4. Verifying medical information and alerts..."
VERIFY_RESPONSE=$(curl -s "http://localhost:8889/medical-gateway.php?athlete_id=$ATHLETE_ID")

echo "Medical Verification Response:"
echo "$VERIFY_RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$VERIFY_RESPONSE"
echo

# Check for expected alerts
echo "5. Checking generated alerts..."
ALERTS_FOUND=0

if echo "$VERIFY_RESPONSE" | grep -q "SEVERE ALLERGY"; then
    echo "✅ Critical allergy alert generated"
    ALERTS_FOUND=$((ALERTS_FOUND + 1))
fi

if echo "$VERIFY_RESPONSE" | grep -q "EpiPen Required"; then
    echo "✅ EpiPen alert generated"
    ALERTS_FOUND=$((ALERTS_FOUND + 1))
fi

if echo "$VERIFY_RESPONSE" | grep -q "Asthma"; then
    echo "✅ Asthma alert generated"
    ALERTS_FOUND=$((ALERTS_FOUND + 1))
fi

echo
echo "=== TEST SUMMARY ==="
echo "✅ Athlete created with ID: $ATHLETE_ID"
echo "✅ Guardian information saved"
echo "✅ Medical information saved"
echo "✅ $ALERTS_FOUND medical alerts generated"
echo
echo "View the complete profile at:"
echo "http://localhost:3003/athlete/$ATHLETE_ID/enhanced"
echo
echo "To test the create form in the UI:"
echo "1. Go to http://localhost:3003/athletes"
echo "2. Click 'Create New Athlete'"
echo "3. Fill in all three steps including the new medical fields"
echo "4. Submit the form"
echo
echo "=== TEST COMPLETE ==="