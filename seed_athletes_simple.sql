-- Seed Athletes and Parents for Teams Elevated

-- Add parent users
INSERT INTO users (first_name, last_name, email, password_hash, phone, role, created_at) VALUES
('David', 'Thompson', 'david.thompson@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 101-0001', 'parent', NOW()),
('Sarah', 'Thompson', 'sarah.thompson@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 101-0002', 'parent', NOW()),
('Michael', 'Rodriguez', 'michael.rodriguez@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 102-0001', 'parent', NOW()),
('Maria', 'Rodriguez', 'maria.rodriguez@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 102-0002', 'parent', NOW()),
('James', 'Chen', 'james.chen@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 103-0001', 'parent', NOW()),
('Lisa', 'Chen', 'lisa.chen@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 103-0002', 'parent', NOW()),
('Robert', 'Wilson', 'robert.wilson@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 104-0001', 'parent', NOW()),
('Jennifer', 'Wilson', 'jennifer.wilson@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 104-0002', 'parent', NOW()),
('William', 'Anderson', 'william.anderson@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 105-0001', 'parent', NOW()),
('Patricia', 'Anderson', 'patricia.anderson@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '(555) 105-0002', 'parent', NOW());

-- Add player users
INSERT INTO users (first_name, last_name, email, password_hash, phone, role, created_at) VALUES
('Emma', 'Thompson', 'emma.thompson@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Carlos', 'Rodriguez', 'carlos.rodriguez@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Sophie', 'Chen', 'sophie.chen@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Liam', 'Wilson', 'liam.wilson@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Olivia', 'Anderson', 'olivia.anderson@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Noah', 'Taylor', 'noah.taylor@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Isabella', 'Martinez', 'isabella.martinez@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Mason', 'Brown', 'mason.brown@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Ava', 'Davis', 'ava.davis@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Ethan', 'Garcia', 'ethan.garcia@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW());

-- Additional 6 more players to get to different ages
INSERT INTO users (first_name, last_name, email, password_hash, phone, role, created_at) VALUES
('Taylor', 'Johnson', 'taylor.johnson@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW()),
('Jordan', 'Smith', 'jordan.smith@student.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'player', NOW());

-- Add guardian records for demo parents (links to guardians + athlete_guardians tables)
-- Note: is_parent is determined by having entries in guardians table matched by email,
-- with linked athletes via athlete_guardians. The user_club_access table provides the
-- club-level role, but guardians/athlete_guardians is what triggers the parent portal view.

-- Create guardian records (email must match the users table email)
INSERT INTO guardians (first_name, last_name, email, mobile_phone) VALUES
('David', 'Thompson', 'david.thompson@email.com', '(555) 101-0001'),
('Sarah', 'Thompson', 'sarah.thompson@email.com', '(555) 101-0002'),
('Michael', 'Rodriguez', 'michael.rodriguez@email.com', '(555) 102-0001'),
('Maria', 'Rodriguez', 'maria.rodriguez@email.com', '(555) 102-0002'),
('James', 'Chen', 'james.chen@email.com', '(555) 103-0001');

-- Link guardians to existing athletes via athlete_guardians
-- Note: athlete IDs reference athletes already in the database (e.g. from prior seeding).
-- Adjust these IDs to match your actual athletes table.
-- Thompson parents (guardian IDs from above) -> athletes 151 (Emily Thompson), 211 (Madison Thompson)
-- Rodriguez parents -> athletes 164 (Grace Garcia), 165 (Gabriel Miller)
-- Chen parent -> athlete 181 (Olivia Thompson)

-- Also add parent role in user_club_access for club_profile_id = 32 (Teams Elevated)
-- User IDs reference the users inserted above (adjust if needed)