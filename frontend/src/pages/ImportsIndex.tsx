import React from 'react';
import ImportTilesGrid from '../components/ImportTilesGrid';
import PageHeader from '../components/ui/PageHeader';

/**
 * Standalone bulk-import landing page. The same tile grid also lives under
 * Club Settings → Imports — this route stays for backward-compat with any
 * existing bookmarks and direct links.
 */
const ImportsIndex: React.FC = () => {
  return (
    <main className="max-w-5xl mx-auto px-4 py-8">
      <PageHeader
        title="Bulk Import"
        subtitle="Choose what you want to import. Each importer walks you through a CSV upload, column mapping, and live progress tracking."
      />

      <ImportTilesGrid />
    </main>
  );
};

export default ImportsIndex;
