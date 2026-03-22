import React from 'react';
import { Link } from 'react-router-dom';
import { HelpReleaseNote } from '../../types/help';

const tagColors: Record<string, string> = {
  feature: 'bg-blue-100 text-blue-700',
  bugfix: 'bg-red-100 text-red-700',
  improvement: 'bg-green-100 text-green-700',
  breaking: 'bg-orange-100 text-orange-700',
};

interface Props {
  note: HelpReleaseNote;
}

const ReleaseNoteCard: React.FC<Props> = ({ note }) => {
  const date = new Date(note.release_date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });

  return (
    <Link
      to={`/help/release-notes/${note.slug}`}
      className="block border border-gray-200 rounded-lg p-5 hover:border-brand-accent hover:shadow-sm transition-all"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 mb-1">
            {note.version && (
              <span className="text-xs font-mono font-medium text-brand-primary bg-brand-secondary/30 px-2 py-0.5 rounded">
                v{note.version}
              </span>
            )}
            <span className="text-xs text-gray-500">{date}</span>
          </div>
          <h3 className="text-base font-semibold text-gray-900">{note.title}</h3>
        </div>
        <div className="flex gap-1.5 flex-shrink-0">
          {note.tags.map((tag) => (
            <span
              key={tag}
              className={`text-xs px-2 py-0.5 rounded-full font-medium ${tagColors[tag] || 'bg-gray-100 text-gray-600'}`}
            >
              {tag}
            </span>
          ))}
        </div>
      </div>
    </Link>
  );
};

export default ReleaseNoteCard;
