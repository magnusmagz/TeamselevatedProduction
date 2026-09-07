import React, { useState, useEffect } from 'react';
import { fetchReleaseNotes } from '../services/helpApi';
import { HelpReleaseNote } from '../types/help';
import ReleaseNoteCard from '../components/help/ReleaseNoteCard';
import HelpBreadcrumb from '../components/help/HelpBreadcrumb';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';

const ReleaseNotes: React.FC = () => {
  const [notes, setNotes] = useState<HelpReleaseNote[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const perPage = 20;

  useEffect(() => {
    setLoading(true);
    fetchReleaseNotes(page)
      .then((data) => {
        setNotes(data.release_notes);
        setTotal(data.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [page]);

  const hasMore = page * perPage < total;

  return (
    <div className="max-w-3xl mx-auto">
      <HelpBreadcrumb items={[{ label: 'Release Notes' }]} />

      <PageHeader title="Release Notes" subtitle="Stay up to date with what's new in Teams Elevated." className="mb-8" />

      {loading && notes.length === 0 ? (
        <div className="text-center text-brand-primary py-12">Loading...</div>
      ) : notes.length === 0 ? (
        <p className="text-center text-gray-500 py-12">No release notes yet.</p>
      ) : (
        <>
          <div className="space-y-3">
            {notes.map((note) => (
              <ReleaseNoteCard key={note.id} note={note} />
            ))}
          </div>

          {hasMore && (
            <div className="text-center mt-6">
              <Button onClick={() => setPage((p) => p + 1)} variant="secondary" loading={loading}>
                Load more
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
};

export default ReleaseNotes;
