-- ========================================
-- Sponsors Migration
-- ========================================
-- Purpose: Add sponsors table for club sponsor management
-- Date: 2026-01-17
-- ========================================

-- Create sponsors table
CREATE TABLE IF NOT EXISTS sponsors (
    id SERIAL PRIMARY KEY,
    club_id INTEGER NOT NULL,
    name VARCHAR(255) NOT NULL,
    website VARCHAR(500),
    contact_name VARCHAR(100),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(20),
    logo_data TEXT,
    logo_filename VARCHAR(255),
    link_1_label VARCHAR(100),
    link_1_url VARCHAR(500),
    link_2_label VARCHAR(100),
    link_2_url VARCHAR(500),
    link_3_label VARCHAR(100),
    link_3_url VARCHAR(500),
    display_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER,
    updated_by INTEGER,
    deleted_at TIMESTAMP NULL,
    deleted_by INTEGER,
    FOREIGN KEY (club_id) REFERENCES club_profile(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id),
    FOREIGN KEY (deleted_by) REFERENCES users(id)
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_sponsors_club_id ON sponsors(club_id);
CREATE INDEX IF NOT EXISTS idx_sponsors_active ON sponsors(is_active) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_sponsors_display_order ON sponsors(club_id, display_order);

-- Print summary
DO $$
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE 'SPONSORS MIGRATION COMPLETE';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Created sponsors table with:';
    RAISE NOTICE '  - Basic info: name, website';
    RAISE NOTICE '  - Contact: name, email, phone';
    RAISE NOTICE '  - Logo: data (base64), filename';
    RAISE NOTICE '  - 3 promotional links';
    RAISE NOTICE '  - Soft delete support';
    RAISE NOTICE '========================================';
END $$;
