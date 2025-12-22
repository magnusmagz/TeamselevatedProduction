-- Migration: Add Coach Profile Fields
-- Description: Adds phone, profile_image_url, coaching_background, and archived fields to users table
-- Date: 2025-12-16

-- Add phone number field
ALTER TABLE users
ADD COLUMN IF NOT EXISTS phone VARCHAR(20);

-- Add profile image URL (supports both uploaded files and external URLs)
ALTER TABLE users
ADD COLUMN IF NOT EXISTS profile_image_url TEXT;

-- Add coaching background/bio
ALTER TABLE users
ADD COLUMN IF NOT EXISTS coaching_background TEXT;

-- Add archived status for soft delete
ALTER TABLE users
ADD COLUMN IF NOT EXISTS archived BOOLEAN DEFAULT FALSE;

-- Create index for archived users (for performance when filtering)
CREATE INDEX IF NOT EXISTS idx_users_archived ON users(archived) WHERE archived = TRUE;

-- Create index for coaches with phone numbers
CREATE INDEX IF NOT EXISTS idx_users_phone ON users(phone) WHERE phone IS NOT NULL;

-- Add comment to columns
COMMENT ON COLUMN users.phone IS 'Coach contact phone number (optional)';
COMMENT ON COLUMN users.profile_image_url IS 'URL to profile image - can be uploaded file or external URL';
COMMENT ON COLUMN users.coaching_background IS 'Coach biography and background information';
COMMENT ON COLUMN users.archived IS 'Soft delete flag - archived coaches hidden from active lists';
