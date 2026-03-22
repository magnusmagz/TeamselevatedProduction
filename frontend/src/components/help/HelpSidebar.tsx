import React, { useState, useEffect } from 'react';
import { Link, useParams, useLocation } from 'react-router-dom';
import { fetchCategories, fetchCategoryArticles } from '../../services/helpApi';
import { HelpCategory, HelpArticle } from '../../types/help';
import HelpRoleBadge from './HelpRoleBadge';

interface Props {
  isOpen: boolean;
  onClose: () => void;
}

const HelpSidebar: React.FC<Props> = ({ isOpen, onClose }) => {
  const [categories, setCategories] = useState<HelpCategory[]>([]);
  const [articlesByCategory, setArticlesByCategory] = useState<Record<string, HelpArticle[]>>({});
  const [expandedCategories, setExpandedCategories] = useState<Set<string>>(new Set());
  const { categorySlug, articleSlug } = useParams<{ categorySlug?: string; articleSlug?: string }>();
  const location = useLocation();

  useEffect(() => {
    fetchCategories().then(setCategories).catch(() => {});
  }, []);

  // Auto-expand the active category
  useEffect(() => {
    if (categorySlug) {
      setExpandedCategories((prev) => new Set(prev).add(categorySlug));
      if (!articlesByCategory[categorySlug]) {
        fetchCategoryArticles(categorySlug)
          .then((articles) => setArticlesByCategory((prev) => ({ ...prev, [categorySlug]: articles })))
          .catch(() => {});
      }
    }
  }, [categorySlug]);

  const toggleCategory = (slug: string) => {
    setExpandedCategories((prev) => {
      const next = new Set(prev);
      if (next.has(slug)) {
        next.delete(slug);
      } else {
        next.add(slug);
        if (!articlesByCategory[slug]) {
          fetchCategoryArticles(slug)
            .then((articles) => setArticlesByCategory((p) => ({ ...p, [slug]: articles })))
            .catch(() => {});
        }
      }
      return next;
    });
  };

  const isReleaseNotesActive = location.pathname.includes('/help/release-notes');

  const sidebarContent = (
    <div className="py-4">
      {/* Search shortcut hint */}
      <div className="px-4 mb-4">
        <button
          onClick={() => {
            onClose();
            window.dispatchEvent(new CustomEvent('open-help-search'));
          }}
          className="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-500 bg-gray-50 border border-gray-200 rounded-lg hover:bg-gray-100 transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          Search docs...
          <span className="ml-auto text-xs text-gray-400 hidden sm:inline">
            {navigator.platform.includes('Mac') ? '\u2318K' : 'Ctrl+K'}
          </span>
        </button>
      </div>

      {/* Categories */}
      <nav className="space-y-0.5">
        {categories.map((cat) => {
          const isExpanded = expandedCategories.has(cat.slug);
          const articles = articlesByCategory[cat.slug] || [];

          return (
            <div key={cat.slug}>
              <button
                onClick={() => toggleCategory(cat.slug)}
                className={`w-full flex items-center justify-between px-4 py-2 text-sm font-medium transition-colors ${
                  categorySlug === cat.slug
                    ? 'text-brand-primary bg-brand-secondary/30'
                    : 'text-gray-700 hover:bg-gray-50'
                }`}
              >
                <span className="flex items-center gap-2">
                  {cat.name}
                  {cat.role_tag && <HelpRoleBadge role={cat.role_tag} />}
                </span>
                <svg
                  className={`w-3.5 h-3.5 text-gray-400 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
                  fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </button>

              {isExpanded && articles.length > 0 && (
                <div className="ml-4 border-l border-gray-200">
                  {articles.map((article) => (
                    <Link
                      key={article.slug}
                      to={`/help/${cat.slug}/${article.slug}`}
                      onClick={onClose}
                      className={`block pl-4 pr-4 py-1.5 text-sm transition-colors ${
                        articleSlug === article.slug && categorySlug === cat.slug
                          ? 'text-brand-primary font-medium border-l-2 border-brand-primary -ml-px bg-brand-secondary/20'
                          : 'text-gray-600 hover:text-brand-primary'
                      }`}
                    >
                      {article.title}
                    </Link>
                  ))}
                </div>
              )}

              {isExpanded && articles.length === 0 && (
                <p className="ml-4 pl-4 py-1.5 text-xs text-gray-400">No articles yet</p>
              )}
            </div>
          );
        })}

        {/* Release Notes */}
        <div className="pt-2 mt-2 border-t border-gray-200">
          <Link
            to="/help/release-notes"
            onClick={onClose}
            className={`flex items-center gap-2 px-4 py-2 text-sm font-medium transition-colors ${
              isReleaseNotesActive
                ? 'text-brand-primary bg-brand-secondary/30'
                : 'text-gray-700 hover:bg-gray-50'
            }`}
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            Release Notes
          </Link>
        </div>
      </nav>
    </div>
  );

  return (
    <>
      {/* Desktop sidebar */}
      <aside className="hidden lg:block w-64 flex-shrink-0 border-r border-gray-200 overflow-y-auto h-[calc(100vh-5rem)]">
        {sidebarContent}
      </aside>

      {/* Mobile drawer */}
      {isOpen && (
        <>
          <div className="fixed inset-0 bg-black/40 z-40 lg:hidden" onClick={onClose} />
          <aside className="fixed left-0 top-0 bottom-0 w-72 bg-white z-50 overflow-y-auto lg:hidden shadow-xl">
            <div className="flex items-center justify-between px-4 h-14 border-b border-gray-200">
              <span className="font-bold text-brand-primary">Help</span>
              <button onClick={onClose} className="p-1 text-gray-500 hover:text-gray-700">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            {sidebarContent}
          </aside>
        </>
      )}
    </>
  );
};

export default HelpSidebar;
