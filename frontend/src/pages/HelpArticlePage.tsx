import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { fetchArticle } from '../services/helpApi';
import { HelpArticle } from '../types/help';
import HelpBreadcrumb from '../components/help/HelpBreadcrumb';
import HelpTableOfContents from '../components/help/HelpTableOfContents';
import HelpFeedback from '../components/help/HelpFeedback';
import HelpRoleBadge from '../components/help/HelpRoleBadge';
import PageHeader from '../components/ui/PageHeader';

function slugify(text: string): string {
  return String(text)
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/[\s]+/g, '-')
    .replace(/-+/g, '-')
    .trim();
}

const HelpArticlePage: React.FC = () => {
  const { categorySlug, articleSlug } = useParams<{ categorySlug: string; articleSlug: string }>();
  const [article, setArticle] = useState<HelpArticle | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!categorySlug || !articleSlug) return;
    setLoading(true);
    setError('');
    fetchArticle(categorySlug, articleSlug)
      .then(setArticle)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [categorySlug, articleSlug]);

  if (loading) {
    return <div className="text-center text-brand-primary py-12">Loading...</div>;
  }

  if (error || !article) {
    return (
      <div className="text-center py-12">
        <p className="text-gray-500">{error || 'Article not found'}</p>
      </div>
    );
  }

  return (
    <div className="flex gap-8 max-w-5xl mx-auto">
      {/* Main content */}
      <div className="flex-1 min-w-0">
        <HelpBreadcrumb
          items={[
            { label: article.category_name || '', to: `/help/${article.category_slug}` },
            { label: article.title },
          ]}
        />

        <PageHeader
          title={article.title}
          meta={
            article.role_tags.length > 0
              ? article.role_tags.map((tag) => <HelpRoleBadge key={tag} role={tag} size="md" />)
              : undefined
          }
          className="mb-4"
        />

        {article.summary && (
          <p className="text-gray-600 mb-6 text-base leading-relaxed">{article.summary}</p>
        )}

        {/* Markdown content */}
        <div className="prose prose-brand max-w-none">
          <ReactMarkdown
            remarkPlugins={[remarkGfm]}
            components={{
              h2: ({ children, ...props }) => {
                const text = extractText(children);
                return <h2 id={slugify(text)} {...props}>{children}</h2>;
              },
              h3: ({ children, ...props }) => {
                const text = extractText(children);
                return <h3 id={slugify(text)} {...props}>{children}</h3>;
              },
              table: ({ children, ...props }) => (
                <div className="overflow-x-auto">
                  <table className="min-w-full border border-gray-200" {...props}>{children}</table>
                </div>
              ),
              th: ({ children, ...props }) => (
                <th className="px-4 py-2 bg-gray-50 text-left text-sm font-semibold text-gray-700 border-b border-gray-200" {...props}>{children}</th>
              ),
              td: ({ children, ...props }) => (
                <td className="px-4 py-2 text-sm text-gray-600 border-b border-gray-100" {...props}>{children}</td>
              ),
              code: ({ children, className, ...props }) => {
                const isInline = !className;
                if (isInline) {
                  return <code className="bg-gray-100 text-brand-primary px-1.5 py-0.5 rounded text-sm font-mono" {...props}>{children}</code>;
                }
                return (
                  <code className={`${className} block bg-gray-900 text-gray-100 rounded-lg p-4 text-sm overflow-x-auto`} {...props}>
                    {children}
                  </code>
                );
              },
              a: ({ children, href, ...props }) => (
                <a href={href} className="text-brand-primary hover:underline" target={href?.startsWith('http') ? '_blank' : undefined} rel={href?.startsWith('http') ? 'noopener noreferrer' : undefined} {...props}>{children}</a>
              ),
            }}
          >
            {article.body_markdown}
          </ReactMarkdown>
        </div>

        <HelpFeedback articleId={article.id} initialFeedback={article.user_feedback} />

        <div className="text-xs text-gray-400 mt-6">
          Last updated: {new Date(article.updated_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
        </div>
      </div>

      {/* Table of contents — desktop only */}
      <div className="hidden xl:block w-52 flex-shrink-0">
        <HelpTableOfContents markdown={article.body_markdown} />
      </div>
    </div>
  );
};

// Extract plain text from React children
function extractText(children: React.ReactNode): string {
  if (typeof children === 'string') return children;
  if (Array.isArray(children)) return children.map(extractText).join('');
  if (children && typeof children === 'object' && 'props' in (children as any)) {
    return extractText((children as any).props.children);
  }
  return '';
}

export default HelpArticlePage;
