-- 016_help_portal.sql
-- Help Portal: categories, articles, release notes, feedback

-- Categories
CREATE TABLE IF NOT EXISTS help_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(50),
    sort_order INTEGER DEFAULT 0,
    role_tag VARCHAR(50),
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Articles
CREATE TABLE IF NOT EXISTS help_articles (
    id SERIAL PRIMARY KEY,
    category_id INTEGER REFERENCES help_categories(id),
    title VARCHAR(500) NOT NULL,
    slug VARCHAR(500) NOT NULL,
    summary TEXT,
    body_markdown TEXT NOT NULL,
    body_html TEXT,
    role_tags TEXT[] DEFAULT '{}',
    related_feature VARCHAR(255),
    search_keywords TEXT[],
    sort_order INTEGER DEFAULT 0,
    is_published BOOLEAN DEFAULT true,
    author_user_id INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(category_id, slug)
);

-- Release Notes
CREATE TABLE IF NOT EXISTS help_release_notes (
    id SERIAL PRIMARY KEY,
    version VARCHAR(50),
    title VARCHAR(500) NOT NULL,
    slug VARCHAR(500) NOT NULL UNIQUE,
    body_markdown TEXT NOT NULL,
    body_html TEXT,
    release_date DATE NOT NULL DEFAULT CURRENT_DATE,
    tags TEXT[] DEFAULT '{}',
    is_published BOOLEAN DEFAULT true,
    author_user_id INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Article Feedback
CREATE TABLE IF NOT EXISTS help_article_feedback (
    id SERIAL PRIMARY KEY,
    article_id INTEGER REFERENCES help_articles(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id),
    is_helpful BOOLEAN NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(article_id, user_id)
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_help_articles_category ON help_articles(category_id);
CREATE INDEX IF NOT EXISTS idx_help_articles_slug ON help_articles(slug);
CREATE INDEX IF NOT EXISTS idx_help_articles_published ON help_articles(is_published);
CREATE INDEX IF NOT EXISTS idx_help_articles_feature ON help_articles(related_feature);
CREATE INDEX IF NOT EXISTS idx_help_release_notes_slug ON help_release_notes(slug);
CREATE INDEX IF NOT EXISTS idx_help_release_notes_date ON help_release_notes(release_date DESC);

-- Full-text search index
CREATE INDEX IF NOT EXISTS idx_help_articles_search
    ON help_articles
    USING gin(to_tsvector('english', title || ' ' || COALESCE(summary, '') || ' ' || body_markdown));

-- Seed initial categories
INSERT INTO help_categories (name, slug, description, icon, sort_order, role_tag) VALUES
    ('Getting Started', 'getting-started', 'Learn the basics of Teams Elevated', 'rocket', 1, NULL),
    ('For Admins', 'for-admins', 'Club administration guides', 'shield', 2, 'admin'),
    ('For Coaches', 'for-coaches', 'Coaching tools and workflows', 'whistle', 3, 'coach'),
    ('For Parents', 'for-parents', 'Parent portal guides', 'users', 4, 'parent'),
    ('Communications', 'communications', 'Email and SMS messaging guides', 'mail', 5, NULL),
    ('Billing & Payments', 'billing-payments', 'Payment and invoicing guides', 'credit-card', 6, NULL)
ON CONFLICT (slug) DO NOTHING;
