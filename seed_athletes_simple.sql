-- Seed Athletes and Parents for Teams Elevated

-- Add parent users
INSERT INTO users (first_name, last_name, email, password, phone, role, created_at) VALUES
('David', 'Thompson', 'david.thompson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 101-0001', 'parent', NOW()),
('Sarah', 'Thompson', 'sarah.thompson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 101-0002', 'parent', NOW()),
('Michael', 'Rodriguez', 'michael.rodriguez@email.com', '$2y$10$YourHashedPasswordHere', '(555) 102-0001', 'parent', NOW()),
('Maria', 'Rodriguez', 'maria.rodriguez@email.com', '$2y$10$YourHashedPasswordHere', '(555) 102-0002', 'parent', NOW()),
('James', 'Chen', 'james.chen@email.com', '$2y$10$YourHashedPasswordHere', '(555) 103-0001', 'parent', NOW()),
('Lisa', 'Chen', 'lisa.chen@email.com', '$2y$10$YourHashedPasswordHere', '(555) 103-0002', 'parent', NOW()),
('Robert', 'Wilson', 'robert.wilson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 104-0001', 'parent', NOW()),
('Jennifer', 'Wilson', 'jennifer.wilson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 104-0002', 'parent', NOW()),
('William', 'Anderson', 'william.anderson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 105-0001', 'parent', NOW()),
('Patricia', 'Anderson', 'patricia.anderson@email.com', '$2y$10$YourHashedPasswordHere', '(555) 105-0002', 'parent', NOW());

-- Add player users
INSERT INTO users (first_name, last_name, email, password, phone, role, created_at) VALUES
('Emma', 'Thompson', 'emma.thompson@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Carlos', 'Rodriguez', 'carlos.rodriguez@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Sophie', 'Chen', 'sophie.chen@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Liam', 'Wilson', 'liam.wilson@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Olivia', 'Anderson', 'olivia.anderson@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Noah', 'Taylor', 'noah.taylor@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Isabella', 'Martinez', 'isabella.martinez@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Mason', 'Brown', 'mason.brown@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Ava', 'Davis', 'ava.davis@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Ethan', 'Garcia', 'ethan.garcia@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW());

-- Additional 6 more players to get to different ages
INSERT INTO users (first_name, last_name, email, password, phone, role, created_at) VALUES
('Taylor', 'Johnson', 'taylor.johnson@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW()),
('Jordan', 'Smith', 'jordan.smith@student.com', '$2y$10$YourHashedPasswordHere', NULL, 'player', NOW());