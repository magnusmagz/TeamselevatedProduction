-- Seed Athletes and Guardians for Teams Elevated
-- This script adds 10 athletes with their guardians

-- First, add guardian users (parents)
INSERT INTO users (first_name, last_name, email, password, phone, role, created_at) VALUES
('David', 'Thompson', 'david.thompson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 101-0001', 'guardian', NOW()),
('Sarah', 'Thompson', 'sarah.thompson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 101-0002', 'guardian', NOW()),
('Michael', 'Rodriguez', 'michael.rodriguez@email.com', '$2y$10$YourHashedPasswordHere', '(555) 102-0001', 'guardian', NOW()),
('Maria', 'Rodriguez', 'maria.rodriguez@email.com', '$2y$10$YourHashedPasswordHere', '(555) 102-0002', 'guardian', NOW()),
('James', 'Chen', 'james.chen@email.com', '$2y$10$YourHashedPasswordHere', '(555) 103-0001', 'guardian', NOW()),
('Lisa', 'Chen', 'lisa.chen@email.com', '$2y$10$YourHashedPasswordHere', '(555) 103-0002', 'guardian', NOW()),
('Robert', 'Wilson', 'robert.wilson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 104-0001', 'guardian', NOW()),
('Jennifer', 'Wilson', 'jennifer.wilson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 104-0002', 'guardian', NOW()),
('William', 'Anderson', 'william.anderson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 105-0001', 'guardian', NOW()),
('Patricia', 'Anderson', 'patricia.anderson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 105-0002', 'guardian', NOW()),
('Christopher', 'Taylor', 'chris.taylor@email.com', '$2y$10$YourHashedPasswordHere', '(555) 106-0001', 'guardian', NOW()),
('Amanda', 'Taylor', 'amanda.taylor@email.com', '$2y$10$YourHashedPasswordHere', '(555) 106-0002', 'guardian', NOW()),
('Daniel', 'Martinez', 'daniel.martinez@email.com', '$2y$10$YourHashedPasswordHere', '(555) 107-0001', 'guardian', NOW()),
('Michelle', 'Martinez', 'michelle.martinez@email.com', '$2y$10$YourHashedPasswordHere', '(555) 107-0002', 'guardian', NOW()),
('Kevin', 'Brown', 'kevin.brown@email.com', '$2y$10$YourHashedPasswordHere', '(555) 108-0001', 'guardian', NOW()),
('Laura', 'Brown', 'laura.brown@email.com', '$2y$10$YourHashedPasswordHere', '(555) 108-0002', 'guardian', NOW()),
('Steven', 'Davis', 'steven.davis@email.com', '$2y$10$YourHashedPasswordHere', '(555) 109-0001', 'guardian', NOW()),
('Nancy', 'Davis', 'nancy.davis@email.com', '$2y$10$YourHashedPasswordHere', '(555) 109-0002', 'guardian', NOW()),
('Mark', 'Garcia', 'mark.garcia@email.com', '$2y$10$YourHashedPasswordHere', '(555) 110-0001', 'guardian', NOW()),
('Susan', 'Garcia', 'susan.garcia@email.com', '$2y$10$YourHashedPasswordHere', '(555) 110-0002', 'guardian', NOW());

-- Get the IDs of the guardians we just inserted (assuming they start from a certain ID)
-- We'll use a subquery to get them

-- Add athlete users
INSERT INTO users (first_name, last_name, email, password, phone, role, date_of_birth, gender, school, grade, created_at) VALUES
('Emma', 'Thompson', 'emma.thompson@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2012-03-15', 'female', 'Lincoln Middle School', '7', NOW()),
('Carlos', 'Rodriguez', 'carlos.rodriguez@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2013-07-22', 'male', 'Lincoln Elementary', '6', NOW()),
('Sophie', 'Chen', 'sophie.chen@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2011-11-08', 'female', 'Washington Middle School', '8', NOW()),
('Liam', 'Wilson', 'liam.wilson@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2014-01-30', 'male', 'Lincoln Elementary', '5', NOW()),
('Olivia', 'Anderson', 'olivia.anderson@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2012-09-12', 'female', 'Jefferson Middle School', '7', NOW()),
('Noah', 'Taylor', 'noah.taylor@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2013-05-18', 'male', 'Roosevelt Elementary', '6', NOW()),
('Isabella', 'Martinez', 'isabella.martinez@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2011-12-25', 'female', 'Washington Middle School', '8', NOW()),
('Mason', 'Brown', 'mason.brown@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2014-04-03', 'male', 'Lincoln Elementary', '5', NOW()),
('Ava', 'Davis', 'ava.davis@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2012-06-20', 'female', 'Jefferson Middle School', '7', NOW()),
('Ethan', 'Garcia', 'ethan.garcia@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'athlete', '2013-10-14', 'male', 'Roosevelt Elementary', '6', NOW());

-- Create guardian relationships
-- Note: You'll need to adjust these IDs based on the actual IDs assigned
-- This assumes the guardians and athletes were inserted in order

-- Get the base IDs dynamically
SET @guardian_base_id = (SELECT MIN(id) FROM users WHERE email = 'david.thompson@email.com');
SET @athlete_base_id = (SELECT MIN(id) FROM users WHERE email = 'emma.thompson@student.com');

-- Create guardian_athlete relationships
INSERT INTO guardian_athlete (guardian_id, athlete_id, relationship, is_primary, created_at)
SELECT
    g.id as guardian_id,
    a.id as athlete_id,
    CASE
        WHEN g.gender = 'male' THEN 'Father'
        WHEN g.gender = 'female' THEN 'Mother'
        ELSE 'Parent'
    END as relationship,
    CASE
        WHEN g.email LIKE '%thompson%' AND a.email LIKE '%thompson%' THEN 1
        WHEN g.email LIKE '%rodriguez%' AND a.email LIKE '%rodriguez%' THEN 1
        WHEN g.email LIKE '%chen%' AND a.email LIKE '%chen%' THEN 1
        WHEN g.email LIKE '%wilson%' AND a.email LIKE '%wilson%' THEN 1
        WHEN g.email LIKE '%anderson%' AND a.email LIKE '%anderson%' THEN 1
        WHEN g.email LIKE '%taylor%' AND a.email LIKE '%taylor%' THEN 1
        WHEN g.email LIKE '%martinez%' AND a.email LIKE '%martinez%' THEN 1
        WHEN g.email LIKE '%brown%' AND a.email LIKE '%brown%' THEN 1
        WHEN g.email LIKE '%davis%' AND a.email LIKE '%davis%' THEN 1
        WHEN g.email LIKE '%garcia%' AND a.email LIKE '%garcia%' THEN 1
        ELSE 0
    END as is_primary,
    NOW() as created_at
FROM users g
CROSS JOIN users a
WHERE g.role = 'guardian'
    AND a.role = 'athlete'
    AND (
        (g.last_name = 'Thompson' AND a.last_name = 'Thompson') OR
        (g.last_name = 'Rodriguez' AND a.last_name = 'Rodriguez') OR
        (g.last_name = 'Chen' AND a.last_name = 'Chen') OR
        (g.last_name = 'Wilson' AND a.last_name = 'Wilson') OR
        (g.last_name = 'Anderson' AND a.last_name = 'Anderson') OR
        (g.last_name = 'Taylor' AND a.last_name = 'Taylor') OR
        (g.last_name = 'Martinez' AND a.last_name = 'Martinez') OR
        (g.last_name = 'Brown' AND a.last_name = 'Brown') OR
        (g.last_name = 'Davis' AND a.last_name = 'Davis') OR
        (g.last_name = 'Garcia' AND a.last_name = 'Garcia')
    );