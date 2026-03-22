import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { fetchReleaseNote } from '../services/helpApi';
import { HelpReleaseNote } from '../types/help';
import HelpBreadcrumb from '../components/help/HelpBreadcrumb';

const tagColors: Record<string, string> = {
  feature: 'bg-blue-100 text-blue-700',
  bugfix: 'bg-red-100 text-red-700',
  improvement: 'bg-green-100 text-green-700',
  breaking: 'bg-orange-100 text-orange-700',
};

const ReleaseNotePage: React.FC = () => {
  const { slug } = useParams<{ slug: string }>();
  const [note, setNote] = useState<HelpReleaseNote | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!slug) return;
    setLoading(true);
    fetchReleaseNote(slug)
      .then(setNote)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [slug]);

  if (loading) {
    return <div className="text-center text-brand-primary py-12">Loading...</div>;
  }

  if (!note) {
    return <div className="text-center text-gray-500 py-12">Release note not found</div>;
  }

  const date = new Date(note.release_date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });

  return (
    <div className="max-w-3xl mx-auto">
      <HelpBreadcrumb
        items={[
          { label: 'Release Notes', to: '/help/release-notes' },
          { label: note.title },
        ]}
      />

      <div className="flex items-center gap-3 mb-2">
        {note.version && (
          <span className="text-sm font-mono font-medium text-brand-primary bg-brand-secondary/30 px-2.5 py-0.5 rounded">
            v{note.version}
          </span>
        )}
        <span className="text-sm text-gray-500">{date}</span>
        {note.tags.map((tag) => (
          <span
            key={tag}
            className={`text-xs px-2 py-0.5 rounded-full font-medium ${tagColors[tag] || 'bg-gray-100 text-gray-600'}`}
          >
            {tag}
          </span>
        ))}
      </div>

      <h1 className="text-2xl font-bold text-brand-primary mb-6">{note.title}</h1>

      <div className="prose prose-brand max-w-none">
        <ReactMarkdown remarkPlugins={[remarkGfm]}>
          {note.body_markdown}
        </ReactMarkdown>
      </div>
    </div>
  );
};

export default ReleaseNotePage;
