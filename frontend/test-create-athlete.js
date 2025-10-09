/**
 * Test script to create an athlete with all required fields
 */

const API_URL = 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

async function createTestAthlete() {
  console.log('🏃 Starting athlete creation test...\n');

  // Step 1: Create the athlete profile
  const athleteData = {
    first_name: 'Test',
    last_name: 'Athlete',
    middle_initial: 'T',
    preferred_name: 'Testy',
    date_of_birth: '2010-05-15',
    gender: 'Male',
    home_address_line1: '123 Test Street', // Backend still requires address
    home_address_line2: 'Apt 4',
    city: 'Test City',
    state: 'CA',
    zip_code: '90210',
    school_name: 'Test Elementary',
    grade_level: 5,
    email: 'test.athlete@test.com'
  };

  console.log('📝 Creating athlete with data:');
  console.log(JSON.stringify(athleteData, null, 2));
  console.log('');

  try {
    const athleteResponse = await fetch(`${API_URL}/api/athletes/`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(athleteData)
    });

    const athleteResult = await athleteResponse.json();

    if (!athleteResponse.ok) {
      console.error('❌ Failed to create athlete');
      console.error('Status:', athleteResponse.status);
      console.error('Response:', JSON.stringify(athleteResult, null, 2));
      return;
    }

    console.log('✅ Athlete created successfully!');
    console.log('Athlete ID:', athleteResult.id);
    console.log('');

    const athleteId = athleteResult.id;

    // Step 2: Create guardian
    const guardianData = {
      athlete_id: athleteId,
      first_name: 'Parent',
      last_name: 'Guardian',
      email: 'parent.guardian@test.com',
      mobile_phone: '(555) 123-4567',
      work_phone: '(555) 987-6543',
      relationship_type: 'Mother',
      is_primary_contact: 1,
      has_legal_custody: 1,
      can_authorize_medical: 1,
      can_pickup: 1,
      receives_communications: 1,
      financial_responsible: 1
    };

    console.log('👨‍👩‍👧 Creating guardian with data:');
    console.log(JSON.stringify(guardianData, null, 2));
    console.log('');

    const guardianResponse = await fetch(`${API_URL}/api/guardians/`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(guardianData)
    });

    const guardianResult = await guardianResponse.json();

    if (!guardianResponse.ok) {
      console.error('❌ Failed to create guardian:', guardianResult);
      return;
    }

    console.log('✅ Guardian created successfully!');
    console.log('');

    // Step 3: Create medical information with physical dates
    const medicalData = {
      athlete_id: athleteId,
      allergies: 'Peanuts, Tree Nuts',
      allergy_severity: 'severe',
      medical_conditions: 'Asthma',
      medications: 'Albuterol inhaler',
      has_asthma: true,
      inhaler_location: 'Backpack',
      has_epipen: true,
      epipen_location: 'Nurse office',
      physician_name: 'Dr. Smith',
      physician_phone: '(555) 111-2222',
      physician_address: '123 Medical Dr',
      insurance_provider: 'Blue Cross',
      insurance_policy_number: 'BC123456',
      insurance_group_number: 'GRP789',
      last_physical_date: '2024-08-15',
      physical_expiry_date: '2025-08-15', // Auto-calculated +1 year
      height_inches: 54,
      weight_lbs: 85,
      blood_type: 'A+',
      emergency_treatment_consent: true,
      special_instructions: 'Keep inhaler nearby during physical activity'
    };

    console.log('🏥 Creating medical information with data:');
    console.log(JSON.stringify(medicalData, null, 2));
    console.log('');

    const medicalResponse = await fetch(`${API_URL}/api/medical-info/`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(medicalData)
    });

    const medicalResult = await medicalResponse.json();

    if (!medicalResponse.ok) {
      console.error('❌ Failed to create medical info:', medicalResult);
      return;
    }

    console.log('✅ Medical information created successfully!');
    console.log('');

    // Summary
    console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    console.log('🎉 TEST COMPLETED SUCCESSFULLY!');
    console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    console.log('');
    console.log('Created:');
    console.log(`  ✓ Athlete: ${athleteData.first_name} ${athleteData.last_name} (ID: ${athleteId})`);
    console.log(`  ✓ Guardian: ${guardianData.first_name} ${guardianData.last_name}`);
    console.log(`  ✓ Medical Info: ${medicalData.allergies}, ${medicalData.medical_conditions}`);
    console.log(`  ✓ Physical Expiry: ${medicalData.physical_expiry_date}`);
    console.log('');

  } catch (error) {
    console.error('❌ Test failed with error:', error);
    console.error('Stack trace:', error.stack);
  }
}

// Run the test
createTestAthlete();
