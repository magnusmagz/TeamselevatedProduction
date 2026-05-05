import React from 'react';
import { Link } from 'react-router-dom';

interface EntityTile {
  slug: string;
  name: string;
  description: string;
  enabled: boolean;
}

const TILES: EntityTile[] = [
  {
    slug: 'athletes',
    name: 'Athletes',
    description: 'One row per athlete with up to two guardians. Supports shared household emails.',
    enabled: true,
  },
  {
    slug: 'facilities',
    name: 'Facilities',
    description: 'Venues + fields/courts. One row = one field with inline venue info; venues are auto-created by name.',
    enabled: true,
  },
  {
    slug: 'volunteers',
    name: 'Volunteers',
    description: 'Team managers, team parents, assistant coaches. Each row is assigned to a team by name — one CSV can span multiple teams in your club.',
    enabled: true,
  },
  {
    slug: 'coaches',
    name: 'Coaches',
    description: 'Coaches at the club level. Creates a user account if needed and grants the coach role on your club. Team assignments happen later in the team UI.',
    enabled: true,
  },
  {
    slug: 'teams',
    name: 'Teams',
    description: 'Teams in your club. Identified by (name + age_group). Optional foreign keys (season, primary coach, home field) are looked up by name or email if provided.',
    enabled: true,
  },
];

/**
 * Reusable tile grid for the bulk import hub. Used by:
 *  - The standalone /imports page (ImportsIndex)
 *  - The Imports tab inside Club Settings (ClubProfilePage)
 *
 * Tiles link to /imports/{slug} so the underlying DataImport routes
 * stay where they are — existing bookmarks keep working.
 */
const ImportTilesGrid: React.FC = () => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      {TILES.map((tile) => {
        const content = (
          <div
            className={`bg-white border rounded-lg p-5 h-full transition-colors ${
              tile.enabled
                ? 'border-gray-200 hover:border-brand-primary hover:shadow'
                : 'border-gray-200 opacity-60'
            }`}
          >
            <div className="flex justify-between items-start mb-2">
              <h2 className="text-lg font-semibold text-brand-primary">{tile.name}</h2>
              {!tile.enabled && (
                <span className="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">
                  Coming soon
                </span>
              )}
            </div>
            <p className="text-sm text-gray-600">{tile.description}</p>
          </div>
        );

        return tile.enabled ? (
          <Link key={tile.slug} to={`/imports/${tile.slug}`} className="block">
            {content}
          </Link>
        ) : (
          <div key={tile.slug}>{content}</div>
        );
      })}
    </div>
  );
};

export default ImportTilesGrid;
