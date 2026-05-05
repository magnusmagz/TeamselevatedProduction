import React from 'react';
import ImportTilesGrid from '../components/ImportTilesGrid';

/**
 * Standalone bulk-import landing page. The same tile grid also lives under
 * Club Settings → Imports — this route stays for backward-compat with any
 * existing bookmarks and direct links.
 */
const ImportsIndex: React.FC = () => {
  return (
    <main className="max-w-5xl mx-auto px-4 py-8">
      <h1 className="text-2xl font-bold text-brand-primary mb-2">Bulk Import</h1>
      <p className="text-sm text-gray-600 mb-6">
        Choose what you want to import. Each importer walks you through a
        CSV upload, column mapping, and live progress tracking.
      </p>

      <ImportTilesGrid />
    </main>
  );
};

export default ImportsIndex;
