import React, { useState, useEffect } from 'react';
import { Outlet, useLocation, Link } from 'react-router-dom';
import HelpSidebar from '../components/help/HelpSidebar';
import HelpSearchModal from '../components/help/HelpSearchModal';
import { fetchCategories } from '../services/helpApi';
import { HelpCategory } from '../types/help';
import HelpRoleBadge from '../components/help/HelpRoleBadge';

const categoryIcons: Record<string, React.ReactNode> = {
  'getting-started': (
    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 10V3L4 14h7v7l9-11h-7z" />
    </svg>
  ),
  'for-admins': (
    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
    </svg>
  ),
  'for-coaches': (
    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
    </svg>
  ),
  'for-parents': (
    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
    </svg>
  ),
  communications: (
    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
    </svg>
  ),
  'billing-payments': (
    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
    </svg>
  ),
};

// Landing page shown at /help (no sub-route)
const HelpLanding: React.FC = () => {
  const [categories, setCategories] = useState<HelpCategory[]>([]);

  useEffect(() => {
    fetchCategories().then(setCategories).catch(() => {});
  }, []);

  return (
    <div className="max-w-3xl mx-auto">
      <div className="text-center mb-10">
        <h1 className="text-3xl font-bold text-brand-primary mb-2">How can we help?</h1>
        <p className="text-gray-600">
          Browse guides and articles, or press{' '}
          <kbd className="text-xs border border-gray-300 rounded px-1.5 py-0.5 font-mono bg-gray-50">
            {navigator.platform.includes('Mac') ? '\u2318' : 'Ctrl'}+K
          </kbd>{' '}
          to search.
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {categories.map((cat) => (
          <Link
            key={cat.slug}
            to={`/help/${cat.slug}`}
            className="border border-gray-200 rounded-lg p-5 hover:border-brand-accent hover:shadow-sm transition-all group"
          >
            <div className="flex items-start gap-3">
              <div className="text-brand-primary/70 group-hover:text-brand-primary transition-colors">
                {categoryIcons[cat.slug] || (
                  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                  </svg>
                )}
              </div>
              <div>
                <h3 className="font-semibold text-gray-900 flex items-center gap-2">
                  {cat.name}
                  {cat.role_tag && <HelpRoleBadge role={cat.role_tag} />}
                </h3>
                {cat.description && (
                  <p className="text-sm text-gray-500 mt-1">{cat.description}</p>
                )}
                <p className="text-xs text-gray-400 mt-2">
                  {cat.article_count} {cat.article_count === 1 ? 'article' : 'articles'}
                </p>
              </div>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
};

// Category listing page shown at /help/:categorySlug (no articleSlug)
const HelpCategoryListing: React.FC = () => {
  const location = useLocation();
  // Extract category slug from URL
  const pathParts = location.pathname.replace('/help/', '').split('/');
  const categorySlug = pathParts[0];
  const [articles, setArticles] = useState<{ id: number; title: string; slug: string; summary: string | null; role_tags: string[] }[]>([]);
  const [categoryName, setCategoryName] = useState('');

  useEffect(() => {
    if (categorySlug) {
      import('../services/helpApi').then(({ fetchCategoryArticles, fetchCategories }) => {
        fetchCategoryArticles(categorySlug).then(setArticles).catch(() => {});
        fetchCategories().then((cats) => {
          const cat = cats.find((c) => c.slug === categorySlug);
          if (cat) setCategoryName(cat.name);
        }).catch(() => {});
      });
    }
  }, [categorySlug]);

  return (
    <div className="max-w-3xl mx-auto">
      <h1 className="text-2xl font-bold text-brand-primary mb-6">{categoryName}</h1>
      <div className="space-y-3">
        {articles.map((article) => (
          <Link
            key={article.slug}
            to={`/help/${categorySlug}/${article.slug}`}
            className="block border border-gray-200 rounded-lg p-4 hover:border-brand-accent hover:shadow-sm transition-all"
          >
            <h3 className="font-medium text-gray-900 flex items-center gap-2">
              {article.title}
              {article.role_tags.map((tag) => (
                <HelpRoleBadge key={tag} role={tag} />
              ))}
            </h3>
            {article.summary && (
              <p className="text-sm text-gray-500 mt-1">{article.summary}</p>
            )}
          </Link>
        ))}
        {articles.length === 0 && (
          <p className="text-gray-500 text-center py-8">No articles in this category yet.</p>
        )}
      </div>
    </div>
  );
};

const HelpPortal: React.FC = () => {
  const [searchOpen, setSearchOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();

  // Keyboard shortcut for search
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setSearchOpen(true);
      }
    };
    window.addEventListener('keydown', handler);

    // Custom event from sidebar search button
    const customHandler = () => setSearchOpen(true);
    window.addEventListener('open-help-search', customHandler);

    return () => {
      window.removeEventListener('keydown', handler);
      window.removeEventListener('open-help-search', customHandler);
    };
  }, []);

  // Determine if we're on a sub-route or the landing page
  const isLanding = location.pathname === '/help' || location.pathname === '/help/';
  const isCategory = !isLanding && location.pathname.split('/').filter(Boolean).length === 2 && !location.pathname.includes('release-notes') && !location.pathname.includes('admin');

  return (
    <div className="flex min-h-[calc(100vh-5rem)]">
      <HelpSidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div className="flex-1 overflow-y-auto">
        {/* Mobile header */}
        <div className="lg:hidden flex items-center gap-3 px-4 py-3 border-b border-gray-200">
          <button
            onClick={() => setSidebarOpen(true)}
            className="p-1.5 text-gray-600 hover:text-gray-900"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <span className="text-sm font-medium text-gray-700">Help</span>
        </div>

        <main className="px-6 py-8 lg:px-10">
          {isLanding ? (
            <HelpLanding />
          ) : isCategory ? (
            <HelpCategoryListing />
          ) : (
            <Outlet />
          )}
        </main>
      </div>

      <HelpSearchModal isOpen={searchOpen} onClose={() => setSearchOpen(false)} />
    </div>
  );
};

export default HelpPortal;
