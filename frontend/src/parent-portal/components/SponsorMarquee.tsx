import React, { useEffect, useState } from 'react';
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
  const [sponsors, setSponsors] = useState<Sponsor[]>([]);

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

  if (sponsors.length === 0) return null;

  // Duplicate the list so the marquee loops seamlessly
  const reel = [...sponsors, ...sponsors];

  return (
    <div className="bg-white border-t border-gray-200 overflow-hidden">
      <div className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400 text-center">
        Our Sponsors
      </div>
      <div className="relative overflow-hidden">
        <div
          className="flex items-center gap-8 py-2 whitespace-nowrap will-change-transform"
          style={{
            animation: `sponsor-marquee ${Math.max(20, sponsors.length * 6)}s linear infinite`,
          }}
        >
          {reel.map((s, i) => {
            const content = (
              <div className="flex items-center gap-2 px-2">
                {s.logo_data ? (
                  <img
                    src={s.logo_data}
                    alt={s.name}
                    className="h-8 w-auto object-contain"
                  />
                ) : (
                  <span className="text-sm font-medium text-gray-700">{s.name}</span>
                )}
              </div>
            );
            return s.website ? (
              <a
                key={`${s.id}-${i}`}
                href={s.website}
                target="_blank"
                rel="noopener noreferrer"
                className="hover:opacity-80 transition-opacity"
              >
                {content}
              </a>
            ) : (
              <div key={`${s.id}-${i}`}>{content}</div>
            );
          })}
        </div>
        <style>{`
          @keyframes sponsor-marquee {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
          }
        `}</style>
      </div>
    </div>
  );
};

export default SponsorMarquee;
