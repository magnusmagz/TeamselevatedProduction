import React from 'react';
import { PageMeta } from '../utils/pagination';

interface LoadMoreProps {
  page: PageMeta | null;
  loading?: boolean;
  onLoadMore: () => void;
  /** Plural noun for the message, e.g. "athletes". */
  label: string;
  /** How many rows are on screen right now. */
  shown: number;
}

/**
 * "Showing N — there are more" plus the button that fetches them.
 *
 * ⚠️ This exists because a paginated list that says nothing is a list that LIES.
 * Every one of these endpoints used to return every row; they now return 200 by
 * default, so without this the same screen shows a prefix of the data and looks
 * exactly like a complete one. A club admin checking who still owes a background
 * check cannot tell the difference, and neither can anyone reviewing the screen.
 *
 * Renders nothing when there is no next page — including when `page` is null,
 * which is what an older backend that is not paginating returns. That is the
 * correct silence: there really is nothing more to fetch.
 */
export default function LoadMore({ page, loading, onLoadMore, label, shown }: LoadMoreProps) {
  if (!page || !page.truncated) return null;

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '12px',
        padding: '16px',
        borderTop: '1px solid #e5e7eb',
        fontSize: '14px',
        color: '#4b5563',
      }}
    >
      <span>
        Showing the first {shown} {label}. There are more.
      </span>
      <button
        type="button"
        onClick={onLoadMore}
        disabled={loading}
        style={{
          padding: '6px 14px',
          borderRadius: '6px',
          border: '1px solid #d1d5db',
          background: loading ? '#f3f4f6' : '#ffffff',
          cursor: loading ? 'default' : 'pointer',
          fontSize: '14px',
        }}
      >
        {loading ? 'Loading…' : 'Load more'}
      </button>
    </div>
  );
}
