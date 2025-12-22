-- Athletes Database Schema
-- Based on youth-athlete-normalized CSV structure

-- Drop existing tables if they exist (in correct order due to foreign keys)
DROP TABLE IF EXISTS communication_prefs;
DROP TABLE IF EXISTS athlete_sports;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS insurance_policies;
DROP TABLE IF EXISTS injuries;
DROP TABLE IF EXISTS allergies;
DROP TABLE IF EXISTS medications;
DROP TABLE IF EXISTS medical_records;
DROP TABLE IF EXISTS emergency_contacts;
DROP TABLE IF EXISTS team_members;
DROP TABLE IF EXISTS athlete_guardians;
DROP TABLE IF EXISTS guardians;
DROP TABLE IF EXISTS athletes;

-- CORE ATHLETE TABLE
CREATE TABLE athletes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    middle_initial CHAR(1),
    last_name VARCHAR(50) NOT NULL,
    preferred_name VARCHAR(50),
    date_of_birth DATE NOT NULL,
    gender ENUM('Male', 'Female', 'Non-binary') NOT NULL,
    home_address_line1 VARCHAR(100) NOT NULL,
    home_address_line2 VARCHAR(100),
    city VARCHAR(50) NOT NULL,
    state VARCHAR(2) NOT NULL,
    zip_code VARCHAR(10) NOT NULL,
    country VARCHAR(50) DEFAULT 'USA',
    birth_certificate_url VARCHAR(255),
    passport_number VARCHAR(50),
    passport_expiry DATE,
    photo_url VARCHAR(255),
    school_name VARCHAR(100),
    grade_level INT CHECK (grade_level >= 1 AND grade_level <= 12),
    current_gpa DECIMAL(3,2) CHECK (current_gpa >= 0.0 AND current_gpa <= 4.0),
    dietary_restrictions JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    active_status BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_athlete_name (last_name, first_name),
    INDEX idx_athlete_dob (date_of_birth),
    INDEX idx_athlete_active (active_status)
);

-- PARENTS/GUARDIANS TABLE
CREATE TABLE guardians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    mobile_phone VARCHAR(20) NOT NULL,
    work_phone VARCHAR(20),
    home_phone VARCHAR(20),
    address_line1 VARCHAR(100),
    address_line2 VARCHAR(100),
    city VARCHAR(50),
    state VARCHAR(2),
    zip_code VARCHAR(10),
    occupation VARCHAR(100),
    employer VARCHAR(100),
    preferred_language ENUM('English', 'Spanish', 'Other') DEFAULT 'English',
    background_check_date DATE,
    background_check_status ENUM('Pending', 'Cleared', 'Failed', 'Expired'),
    safesport_trained BOOLEAN DEFAULT FALSE,
    safesport_expiry DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_guardian_email (email),
    INDEX idx_guardian_name (last_name, first_name)
);

-- ATHLETE_GUARDIAN_RELATIONSHIPS TABLE
CREATE TABLE athlete_guardians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    guardian_id INT NOT NULL,
    relationship_type ENUM('Mother', 'Father', 'Stepparent', 'Grandparent', 'Guardian', 'Other') NOT NULL,
    is_primary_contact BOOLEAN DEFAULT FALSE,
    has_legal_custody BOOLEAN DEFAULT TRUE,
    can_authorize_medical BOOLEAN DEFAULT TRUE,
    can_pickup BOOLEAN DEFAULT TRUE,
    receives_communications BOOLEAN DEFAULT TRUE,
    financial_responsible BOOLEAN DEFAULT FALSE,
    custody_notes TEXT,
    custody_document_url VARCHAR(255),
    active_status BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE CASCADE,
    UNIQUE KEY unique_athlete_guardian (athlete_id, guardian_id),
    INDEX idx_primary_contact (athlete_id, is_primary_contact),
    INDEX idx_guardian_relationship (guardian_id, relationship_type)
);

-- CREATE OR UPDATE TEAM_MEMBERS TABLE
CREATE TABLE IF NOT EXISTS team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    athlete_id INT,
    role ENUM('player', 'assistant_coach', 'team_manager') DEFAULT 'player',
    jersey_number INT CHECK (jersey_number >= 0 AND jersey_number <= 99),
    jersey_number_alt INT CHECK (jersey_number_alt >= 0 AND jersey_number_alt <= 99),
    positions JSON,
    primary_position VARCHAR(50),
    team_priority ENUM('primary', 'secondary', 'guest') DEFAULT 'primary',
    status ENUM('active', 'injured', 'suspended', 'inactive') DEFAULT 'active',
    join_date DATE NOT NULL,
    leave_date DATE,
    leave_reason VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    INDEX idx_team_user (team_id, user_id),
    INDEX idx_team_athlete (team_id, athlete_id),
    INDEX idx_user_teams (user_id),
    INDEX idx_athlete_teams (athlete_id)
);

-- EMERGENCY_CONTACTS TABLE
CREATE TABLE emergency_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    contact_name VARCHAR(100) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    primary_phone VARCHAR(20) NOT NULL,
    alternate_phone VARCHAR(20),
    can_authorize_medical BOOLEAN NOT NULL,
    priority_order INT NOT NULL DEFAULT 1,
    is_out_of_state BOOLEAN DEFAULT FALSE,
    notes VARCHAR(250),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    INDEX idx_emergency_athlete (athlete_id, priority_order)
);

-- MEDICAL_RECORDS TABLE
CREATE TABLE medical_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL UNIQUE,
    physical_exam_date DATE NOT NULL,
    physical_exam_file_url VARCHAR(255) NOT NULL,
    physician_name VARCHAR(100) NOT NULL,
    physician_phone VARCHAR(20) NOT NULL,
    preferred_hospital VARCHAR(100),
    blood_type VARCHAR(10),
    has_asthma BOOLEAN DEFAULT FALSE,
    has_diabetes BOOLEAN DEFAULT FALSE,
    diabetes_type ENUM('Type1', 'Type2'),
    has_seizures BOOLEAN DEFAULT FALSE,
    has_heart_condition BOOLEAN DEFAULT FALSE,
    family_cardiac_history TEXT,
    vision_issues BOOLEAN DEFAULT FALSE,
    hearing_issues BOOLEAN DEFAULT FALSE,
    mental_health_notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    INDEX idx_medical_athlete (athlete_id),
    INDEX idx_physical_exam_date (physical_exam_date)
);

-- MEDICATIONS TABLE
CREATE TABLE medications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    medication_name VARCHAR(100) NOT NULL,
    dosage VARCHAR(100) NOT NULL,
    frequency VARCHAR(100) NOT NULL,
    prescribing_doctor VARCHAR(100),
    start_date DATE,
    end_date DATE,
    storage_requirements VARCHAR(200),
    administration_notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    INDEX idx_medication_athlete (athlete_id, is_active)
);

-- ALLERGIES TABLE
CREATE TABLE allergies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    allergy_type ENUM('Food', 'Medication', 'Environmental', 'Insect', 'Other') NOT NULL,
    allergen VARCHAR(100) NOT NULL,
    reaction_severity ENUM('Mild', 'Moderate', 'Severe', 'Life-threatening') NOT NULL,
    reaction_description VARCHAR(250),
    treatment_required VARCHAR(250),
    epipen_required BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    INDEX idx_allergy_athlete (athlete_id),
    INDEX idx_allergy_severity (reaction_severity)
);

-- INJURIES TABLE
CREATE TABLE injuries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    team_id INT,
    injury_date DATE NOT NULL,
    injury_type VARCHAR(100) NOT NULL,
    body_part VARCHAR(50) NOT NULL,
    severity ENUM('Minor', 'Moderate', 'Severe'),
    treatment_received TEXT,
    treating_physician VARCHAR(100),
    return_to_play_date DATE,
    is_concussion BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    INDEX idx_injury_athlete (athlete_id, injury_date),
    INDEX idx_injury_concussion (athlete_id, is_concussion)
);

-- INSURANCE_POLICIES TABLE
CREATE TABLE insurance_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    is_primary BOOLEAN NOT NULL,
    provider_name VARCHAR(100) NOT NULL,
    policy_number VARCHAR(50) NOT NULL,
    group_number VARCHAR(50),
    policy_holder_name VARCHAR(100) NOT NULL,
    policy_holder_dob DATE,
    provider_phone VARCHAR(20) NOT NULL,
    effective_date DATE,
    expiration_date DATE,
    card_front_url VARCHAR(255),
    card_back_url VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    INDEX idx_insurance_athlete (athlete_id, is_primary)
);

-- DOCUMENTS TABLE
CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    document_type ENUM('Waiver', 'Medical', 'Academic', 'Legal', 'Photo', 'Other') NOT NULL,
    document_name VARCHAR(200) NOT NULL,
    file_url VARCHAR(255) NOT NULL,
    signed_date DATE,
    signed_by INT,
    expires_date DATE,
    is_required BOOLEAN DEFAULT FALSE,
    is_current BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    FOREIGN KEY (signed_by) REFERENCES guardians(id) ON DELETE SET NULL,
    INDEX idx_document_athlete (athlete_id, document_type),
    INDEX idx_document_current (athlete_id, is_current, is_required)
);

-- ATHLETE_SPORTS TABLE
CREATE TABLE athlete_sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_id INT NOT NULL,
    sport_type ENUM('Soccer', 'Basketball', 'Baseball', 'Swimming', 'Hockey', 'Football', 'Other') NOT NULL,
    years_experience INT DEFAULT 0,
    skill_level ENUM('Beginner', 'Intermediate', 'Advanced', 'Elite'),
    dominant_hand ENUM('Right', 'Left', 'Ambidextrous'),
    dominant_foot ENUM('Right', 'Left', 'Both'),
    sport_specific_id VARCHAR(50),
    ranking INT,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    INDEX idx_athlete_sport (athlete_id, sport_type)
);

-- COMMUNICATION_PREFERENCES TABLE
CREATE TABLE communication_prefs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guardian_id INT NOT NULL,
    athlete_id INT NOT NULL,
    preferred_method ENUM('Email', 'Text', 'Phone', 'App') NOT NULL DEFAULT 'Email',
    opt_in_team_emails BOOLEAN DEFAULT TRUE,
    opt_in_club_emails BOOLEAN DEFAULT TRUE,
    opt_in_text_alerts BOOLEAN DEFAULT FALSE,
    opt_in_game_reminders BOOLEAN DEFAULT TRUE,
    opt_in_volunteer_requests BOOLEAN DEFAULT TRUE,
    quiet_hours_start TIME,
    quiet_hours_end TIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE CASCADE,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_guardian_athlete_prefs (guardian_id, athlete_id),
    INDEX idx_comm_prefs_guardian (guardian_id),
    INDEX idx_comm_prefs_athlete (athlete_id)
);

-- Create views for common queries

-- View for athlete with primary guardian info
CREATE VIEW athlete_primary_contacts AS
SELECT
    a.id AS athlete_id,
    a.first_name AS athlete_first_name,
    a.last_name AS athlete_last_name,
    a.date_of_birth,
    g.first_name AS guardian_first_name,
    g.last_name AS guardian_last_name,
    g.email AS guardian_email,
    g.mobile_phone AS guardian_phone,
    ag.relationship_type
FROM athletes a
LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary_contact = TRUE
LEFT JOIN guardians g ON ag.guardian_id = g.id
WHERE a.active_status = TRUE;

-- View for medical alerts
CREATE VIEW medical_alerts AS
SELECT
    a.id AS athlete_id,
    a.first_name,
    a.last_name,
    GROUP_CONCAT(DISTINCT
        CASE
            WHEN al.reaction_severity IN ('Severe', 'Life-threatening') THEN CONCAT(al.allergen, ' (', al.reaction_severity, ')')
            ELSE NULL
        END
    ) AS severe_allergies,
    GROUP_CONCAT(DISTINCT
        CASE
            WHEN al.epipen_required = TRUE THEN 'EpiPen Required'
            ELSE NULL
        END
    ) AS epipen_alert,
    mr.has_asthma,
    mr.has_diabetes,
    mr.has_seizures,
    mr.has_heart_condition
FROM athletes a
LEFT JOIN allergies al ON a.id = al.athlete_id
LEFT JOIN medical_records mr ON a.id = mr.athlete_id
WHERE a.active_status = TRUE
GROUP BY a.id;

-- View for roster with medical clearance status
CREATE VIEW roster_medical_status AS
SELECT
    tm.team_id,
    tm.user_id,
    a.id AS athlete_id,
    a.first_name,
    a.last_name,
    a.date_of_birth,
    mr.physical_exam_date,
    CASE
        WHEN mr.physical_exam_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR) THEN 'Current'
        WHEN mr.physical_exam_date IS NULL THEN 'Missing'
        ELSE 'Expired'
    END AS physical_status,
    COUNT(DISTINCT d.id) AS required_docs_count,
    COUNT(DISTINCT CASE WHEN d.is_current = TRUE THEN d.id END) AS current_docs_count
FROM team_members tm
JOIN athletes a ON tm.athlete_id = a.id
LEFT JOIN medical_records mr ON a.id = mr.athlete_id
LEFT JOIN documents d ON a.id = d.athlete_id AND d.is_required = TRUE
WHERE tm.status = 'active'
GROUP BY tm.team_id, tm.user_id, a.id;