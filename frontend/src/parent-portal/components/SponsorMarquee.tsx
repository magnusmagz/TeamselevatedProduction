import React, { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useOrg } from '../../contexts/OrgContext';

interface Sponsor {
  id: number;
  name: string;
  website?: string;
  logo_data?: string;
  logo_filename?: string;
}

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

export const SponsorMarquee: React.FC = () => {
  const { currentClubId } = useOrg();
  const location = useLocation();
  const [sponsors, setSponsors] = useState<Sponsor[]>([]);

  // Hide on the chat screen — the chat input needs the full bottom of the viewport
  const onChatRoute = location.pathname.startsWith('/parent/chat');

  useEffect(() => {
    if (!currentClubId) return;
    let cancelled = false;
    const load = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        const res = await fetch(`${API_URL}/api/sponsors.php?club_id=${currentClubId}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const data = await res.json();
        if (!cancelled && Array.isArray(data)) setSponsors(data);
      } catch (err) {
        console.error('Failed to load sponsors:', err);
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, [currentClubId]);

  if (sponsors.length === 0 || onChatRoute) return null;

  return (
    <div className="fixed bottom-16 left-0 right-0 z-40 bg-white border-t border-gray-200">
      <div className="px-3 pt-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400 text-center">
        Our Sponsors
      </div>
      <div className="flex items-center justify-around flex-wrap gap-x-4 gap-y-1 px-3 py-2">
        {sponsors.map((s) => {
          const content = s.logo_data ? (
            <img src={s.logo_data} alt={s.name} className="h-7 w-auto object-contain" />
          ) : (
            <span className="text-sm font-medium text-gray-700">{s.name}</span>
          );
          return s.website ? (
            <a
              key={s.id}
              href={s.website}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center hover:opacity-80 transition-opacity"
              title={s.name}
            >
              {content}
            </a>
          ) : (
            <div key={s.id} className="flex items-center" title={s.name}>
              {content}
            </div>
          );
        })}
      </div>
    </div>
  );
};

export default SponsorMarquee;
