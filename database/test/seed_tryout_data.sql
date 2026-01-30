-- Seed Data for Tryout System Testing
-- Run after setup_test_db.sql and 009_tryout_system.sql

-- ============================================
-- 1. CLUB AND USERS
-- ============================================

-- Insert test club
INSERT INTO club_profile (id, name, slug, primary_color, contact_email)
VALUES (1, 'Test Soccer Club', 'test-soccer-club', '#1a365d', 'admin@testsoccerclub.com')
ON CONFLICT (id) DO NOTHING;

-- Insert test users (coaches/evaluators)
INSERT INTO users (id, email, password, first_name, last_name, role, system_role) VALUES
(1, 'admin@testsoccerclub.com', 'hashed_password', 'Admin', 'User', 'club_admin', 'user'),
(2, 'coach1@testsoccerclub.com', 'hashed_password', 'John', 'Smith', 'coach', 'user'),
(3, 'coach2@testsoccerclub.com', 'hashed_password', 'Sarah', 'Johnson', 'coach', 'user'),
(4, 'coach3@testsoccerclub.com', 'hashed_password', 'Mike', 'Williams', 'coach', 'user')
ON CONFLICT (id) DO NOTHING;

-- ============================================
-- 2. VENUES
-- ============================================

INSERT INTO venues (id, club_id, name, address, city, state) VALUES
(1, 1, 'Main Soccer Complex', '123 Sports Way', 'Testville', 'CA'),
(2, 1, 'North Field', '456 Athletic Ave', 'Testville', 'CA')
ON CONFLICT (id) DO NOTHING;

-- ============================================
-- 3. TEAMS (for roster assignment)
-- ============================================

INSERT INTO teams (id, club_id, name, age_group, division, max_players) VALUES
(1, 1, 'U12 Elite Red', 'U12', 'Elite', 18),
(2, 1, 'U12 Elite Blue', 'U12', 'Elite', 18),
(3, 1, 'U12 Competitive', 'U12', 'Competitive', 20)
ON CONFLICT (id) DO NOTHING;

-- ============================================
-- 4. TRYOUT PROGRAM
-- ============================================

INSERT INTO programs (id, club_id, name, type, description, start_date, end_date,
                      registration_opens, registration_closes, min_age, max_age,
                      capacity, status, embed_code)
VALUES (
    100,
    1,
    '2026 Spring U12 Tryouts',
    'tryout',
    'Tryouts for U12 travel teams. All skill levels welcome.',
    '2026-03-15',
    '2026-03-16',
    '2026-02-01',
    '2026-03-10',
    10,
    12,
    60,
    'published',
    'TRY2026SPRING001'
)
ON CONFLICT (id) DO NOTHING;

-- Add default form fields for tryout
INSERT INTO program_form_fields (program_id, field_name, field_label, field_type, required, section, display_order, options) VALUES
(100, 'athlete_first', 'Athlete First Name', 'text', true, 'athlete_info', 1, NULL),
(100, 'athlete_last', 'Athlete Last Name', 'text', true, 'athlete_info', 2, NULL),
(100, 'athlete_birthday', 'Date of Birth', 'date', true, 'athlete_info', 3, NULL),
(100, 'athlete_gender', 'Gender', 'select', true, 'athlete_info', 4, '["Male", "Female", "Non-binary", "Prefer not to say"]'),
(100, 'athlete_grade', 'Grade', 'select', true, 'athlete_info', 5, '["5th", "6th", "7th", "8th"]'),
(100, 'guardian_first', 'Parent/Guardian First Name', 'text', true, 'parent_info', 6, NULL),
(100, 'guardian_last', 'Parent/Guardian Last Name', 'text', true, 'parent_info', 7, NULL),
(100, 'guardian_email', 'Email', 'email', true, 'parent_info', 8, NULL),
(100, 'mobile_phone', 'Phone Number', 'tel', true, 'parent_info', 9, NULL)
ON CONFLICT DO NOTHING;

-- ============================================
-- 5. TRYOUT SESSIONS
-- ============================================

INSERT INTO tryout_sessions (id, program_id, session_date, start_time, end_time, location, venue_id) VALUES
(1, 100, '2026-03-15', '09:00', '12:00', 'Field A', 1),
(2, 100, '2026-03-15', '13:00', '16:00', 'Field B', 1),
(3, 100, '2026-03-16', '09:00', '12:00', 'Field A', 1)
ON CONFLICT (id) DO NOTHING;

-- ============================================
-- 6. EVALUATION CRITERIA
-- ============================================

INSERT INTO tryout_evaluation_criteria (id, program_id, name, description, max_score, weight, display_order) VALUES
(1, 100, 'Technical Skills', 'Ball control, passing accuracy, first touch, shooting', 5, 1.00, 0),
(2, 100, 'Tactical Awareness', 'Positioning, decision making, game reading', 5, 1.00, 1),
(3, 100, 'Physical Attributes', 'Speed, agility, stamina, strength', 5, 0.75, 2),
(4, 100, 'Attitude & Coachability', 'Effort, listening, teamwork, positive attitude', 5, 1.25, 3)
ON CONFLICT (id) DO NOTHING;

-- ============================================
-- 7. GUARDIANS
-- ============================================

INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
(1, 'Robert', 'Anderson', 'robert.anderson@email.com', '555-0101'),
(2, 'Jennifer', 'Martinez', 'jennifer.martinez@email.com', '555-0102'),
(3, 'David', 'Thompson', 'david.thompson@email.com', '555-0103'),
(4, 'Michelle', 'Garcia', 'michelle.garcia@email.com', '555-0104'),
(5, 'James', 'Wilson', 'james.wilson@email.com', '555-0105'),
(6, 'Lisa', 'Brown', 'lisa.brown@email.com', '555-0106'),
(7, 'Christopher', 'Davis', 'christopher.davis@email.com', '555-0107'),
(8, 'Amanda', 'Miller', 'amanda.miller@email.com', '555-0108')
ON CONFLICT (id) DO NOTHING;

-- ============================================
-- 8. ATHLETES
-- ============================================

INSERT INTO athletes (id, first_name, last_name, date_of_birth, gender, grade_level, club_id) VALUES
(1, 'Emma', 'Anderson', '2014-05-15', 'Female', 6, 1),
(2, 'Liam', 'Martinez', '2014-08-22', 'Male', 6, 1),
(3, 'Olivia', 'Thompson', '2013-12-03', 'Female', 7, 1),
(4, 'Noah', 'Garcia', '2014-03-17', 'Male', 6, 1),
(5, 'Sophia', 'Wilson', '2013-09-28', 'Female', 7, 1),
(6, 'Jackson', 'Brown', '2014-01-10', 'Male', 6, 1),
(7, 'Ava', 'Davis', '2013-07-04', 'Female', 7, 1),
(8, 'Lucas', 'Miller', '2014-11-19', 'Male', 6, 1)
ON CONFLICT (id) DO NOTHING;

-- Link athletes to guardians
INSERT INTO athlete_guardians (athlete_id, guardian_id, relationship, is_primary) VALUES
(1, 1, 'Father', true),
(2, 2, 'Mother', true),
(3, 3, 'Father', true),
(4, 4, 'Mother', true),
(5, 5, 'Father', true),
(6, 6, 'Mother', true),
(7, 7, 'Father', true),
(8, 8, 'Mother', true)
ON CONFLICT (athlete_id, guardian_id) DO NOTHING;

-- ============================================
-- 9. TRYOUT REGISTRATIONS
-- ============================================

-- Registration data with various statuses to test the full flow
INSERT INTO registrations (id, program_id, athlete_id, guardian_id, form_data, status, tryout_status, submitted_at) VALUES
-- Registered only (not checked in yet)
(101, 100, 1, 1, '{"athlete_first": "Emma", "athlete_last": "Anderson", "athlete_birthday": "2014-05-15", "athlete_gender": "Female", "athlete_grade": "6th", "guardian_first": "Robert", "guardian_last": "Anderson", "guardian_email": "robert.anderson@email.com", "mobile_phone": "555-0101"}', 'approved', 'registered', '2026-02-15 10:00:00'),

-- Checked in (ready for evaluation)
(102, 100, 2, 2, '{"athlete_first": "Liam", "athlete_last": "Martinez", "athlete_birthday": "2014-08-22", "athlete_gender": "Male", "athlete_grade": "6th", "guardian_first": "Jennifer", "guardian_last": "Martinez", "guardian_email": "jennifer.martinez@email.com", "mobile_phone": "555-0102"}', 'approved', 'checked_in', '2026-02-16 09:30:00'),

(103, 100, 3, 3, '{"athlete_first": "Olivia", "athlete_last": "Thompson", "athlete_birthday": "2013-12-03", "athlete_gender": "Female", "athlete_grade": "7th", "guardian_first": "David", "guardian_last": "Thompson", "guardian_email": "david.thompson@email.com", "mobile_phone": "555-0103"}', 'approved', 'checked_in', '2026-02-17 11:00:00'),

(104, 100, 4, 4, '{"athlete_first": "Noah", "athlete_last": "Garcia", "athlete_birthday": "2014-03-17", "athlete_gender": "Male", "athlete_grade": "6th", "guardian_first": "Michelle", "guardian_last": "Garcia", "guardian_email": "michelle.garcia@email.com", "mobile_phone": "555-0104"}', 'approved', 'checked_in', '2026-02-18 14:00:00'),

-- Evaluated (have scores)
(105, 100, 5, 5, '{"athlete_first": "Sophia", "athlete_last": "Wilson", "athlete_birthday": "2013-09-28", "athlete_gender": "Female", "athlete_grade": "7th", "guardian_first": "James", "guardian_last": "Wilson", "guardian_email": "james.wilson@email.com", "mobile_phone": "555-0105"}', 'approved', 'evaluated', '2026-02-19 10:30:00'),

(106, 100, 6, 6, '{"athlete_first": "Jackson", "athlete_last": "Brown", "athlete_birthday": "2014-01-10", "athlete_gender": "Male", "athlete_grade": "6th", "guardian_first": "Lisa", "guardian_last": "Brown", "guardian_email": "lisa.brown@email.com", "mobile_phone": "555-0106"}', 'approved', 'evaluated', '2026-02-20 09:00:00'),

-- More registrations for ranking tests
(107, 100, 7, 7, '{"athlete_first": "Ava", "athlete_last": "Davis", "athlete_birthday": "2013-07-04", "athlete_gender": "Female", "athlete_grade": "7th", "guardian_first": "Christopher", "guardian_last": "Davis", "guardian_email": "christopher.davis@email.com", "mobile_phone": "555-0107"}', 'approved', 'evaluated', '2026-02-21 10:00:00'),

(108, 100, 8, 8, '{"athlete_first": "Lucas", "athlete_last": "Miller", "athlete_birthday": "2014-11-19", "athlete_gender": "Male", "athlete_grade": "6th", "guardian_first": "Amanda", "guardian_last": "Miller", "guardian_email": "amanda.miller@email.com", "mobile_phone": "555-0108"}', 'approved', 'evaluated', '2026-02-22 11:30:00')
ON CONFLICT (id) DO NOTHING;

-- ============================================
-- 10. SAMPLE EVALUATIONS
-- ============================================

-- Sophia Wilson (registration 105) - evaluated by 2 coaches
INSERT INTO tryout_evaluations (registration_id, evaluator_id, session_id, scores, overall_score, notes) VALUES
(105, 2, 1, '{"1": 5, "2": 4, "3": 4, "4": 5}', 87.50, 'Excellent technical skills, natural leader'),
(105, 3, 1, '{"1": 4, "2": 5, "3": 4, "4": 5}', 87.50, 'Great game awareness, positive attitude')
ON CONFLICT (registration_id, evaluator_id) DO NOTHING;

-- Jackson Brown (registration 106) - evaluated by 3 coaches
INSERT INTO tryout_evaluations (registration_id, evaluator_id, session_id, scores, overall_score, notes) VALUES
(106, 2, 1, '{"1": 4, "2": 4, "3": 5, "4": 4}', 82.50, 'Very athletic, good potential'),
(106, 3, 1, '{"1": 4, "2": 3, "3": 5, "4": 4}', 77.50, 'Strong physically, needs work on positioning'),
(106, 4, 1, '{"1": 3, "2": 4, "3": 5, "4": 4}', 77.50, 'Good speed, keep developing technical skills')
ON CONFLICT (registration_id, evaluator_id) DO NOTHING;

-- Ava Davis (registration 107) - evaluated by 2 coaches
INSERT INTO tryout_evaluations (registration_id, evaluator_id, session_id, scores, overall_score, notes) VALUES
(107, 2, 2, '{"1": 5, "2": 5, "3": 3, "4": 5}', 90.00, 'Outstanding skills and attitude'),
(107, 3, 2, '{"1": 5, "2": 4, "3": 4, "4": 5}', 87.50, 'Very talented player')
ON CONFLICT (registration_id, evaluator_id) DO NOTHING;

-- Lucas Miller (registration 108) - evaluated by 2 coaches
INSERT INTO tryout_evaluations (registration_id, evaluator_id, session_id, scores, overall_score, notes) VALUES
(108, 2, 2, '{"1": 3, "2": 3, "3": 4, "4": 4}', 67.50, 'Good effort, needs development'),
(108, 3, 2, '{"1": 3, "2": 4, "3": 3, "4": 4}', 70.00, 'Coachable player with potential')
ON CONFLICT (registration_id, evaluator_id) DO NOTHING;

-- Update overall scores on registrations
UPDATE registrations SET overall_score = 87.50 WHERE id = 105;
UPDATE registrations SET overall_score = 79.17 WHERE id = 106;
UPDATE registrations SET overall_score = 88.75 WHERE id = 107;
UPDATE registrations SET overall_score = 68.75 WHERE id = 108;

-- ============================================
-- SUMMARY
-- ============================================

DO $$
BEGIN
    RAISE NOTICE '============================================';
    RAISE NOTICE 'Tryout Test Data Seeded Successfully!';
    RAISE NOTICE '============================================';
    RAISE NOTICE 'Created:';
    RAISE NOTICE '  - 1 Club';
    RAISE NOTICE '  - 4 Users (1 admin, 3 coaches)';
    RAISE NOTICE '  - 2 Venues';
    RAISE NOTICE '  - 3 Teams for roster assignment';
    RAISE NOTICE '  - 1 Tryout Program (ID: 100)';
    RAISE NOTICE '  - 3 Tryout Sessions';
    RAISE NOTICE '  - 4 Evaluation Criteria';
    RAISE NOTICE '  - 8 Athletes with guardians';
    RAISE NOTICE '  - 8 Registrations in various states:';
    RAISE NOTICE '      * 1 registered (not checked in)';
    RAISE NOTICE '      * 3 checked_in (ready for eval)';
    RAISE NOTICE '      * 4 evaluated (with scores)';
    RAISE NOTICE '  - Sample evaluations from multiple coaches';
    RAISE NOTICE '============================================';
END $$;
