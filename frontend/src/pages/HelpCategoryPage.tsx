import React, { useState, useEffect } from 'react';
import { Link, useParams } from 'react-router-dom';
import { fetchCategoryArticles, fetchCategories } from '../services/helpApi';
import HelpRoleBadge from '../components/help/HelpRoleBadge';
import HelpBreadcrumb from '../components/help/HelpBreadcrumb';

const HelpCategoryPage: React.FC = () => {
  const { categorySlug } = useParams<{ categorySlug: string }>();
  const [articles, setArticles] = useState<{ id: number; title: string; slug: string; summary: string | null; role_tags: string[] }[]>([]);
  const [categoryName, setCategoryName] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!categorySlug) return;
    setLoading(true);
    Promise.all([
      fetchCategoryArticles(categorySlug).then(setArticles).catch(() => {}),
      fetchCategories().then((cats) => {
        const cat = cats.find((c) => c.slug === categorySlug);
        if (cat) setCategoryName(cat.name);
      }).catch(() => {}),
    ]).finally(() => setLoading(false));
  }, [categorySlug]);

  if (loading) {
    return <div className="text-center text-brand-primary py-12">Loading...</div>;
  }

  return (
    <div className="max-w-3xl mx-auto">
      <HelpBreadcrumb items={[{ label: categoryName }]} />
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

export default HelpCategoryPage;
