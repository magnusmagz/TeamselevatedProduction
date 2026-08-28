import React, { useEffect, useState } from 'react';
import CanvaGraphicButton from './CanvaGraphicButton';

/**
 * Every graphic a club can make about one record.
 *
 * Asks the backend which templates exist for this kind of subject and renders a
 * button per template, rather than hard-coding a graphic type at each call site.
 * Registering a new brand template therefore makes a button appear with no
 * frontend change — which matters because the catalog is expected to churn
 * weekly during the pilot, and a redeploy per template would guarantee it does
 * not.
 *
 * Renders nothing when the club has no templates, which is most clubs today.
 */

interface Props {
  clubId: number;
  /** 'sponsor' | 'event' | 'team' | 'program' — what subjectId refers to. */
  subjectKind: string;
  subjectId: number;
  subjectName: string;
  apiUrl: string;
  className?: string;
}

interface Template {
  graphic_type: string;
  title: string;
}

const CanvaGraphicActions: React.FC<Props> = ({
  clubId,
  subjectKind,
  subjectId,
  subjectName,
  apiUrl,
  className,
}) => {
  const [templates, setTemplates] = useState<Template[]>([]);
  const token = localStorage.getItem('auth_token');

  useEffect(() => {
    let cancelled = false;

    const load = async () => {
      try {
        const res = await fetch(
          `${apiUrl}/api/canva-graphics.php?action=available&club_id=${clubId}` +
            `&subject_kind=${encodeURIComponent(subjectKind)}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        if (!res.ok) return;
        const data = await res.json();
        if (!cancelled && Array.isArray(data?.templates)) setTemplates(data.templates);
      } catch {
        // These buttons are an enhancement on a page that works without them, so
        // a failed lookup renders nothing rather than an error state.
      }
    };

    load();
    return () => {
      cancelled = true;
    };
  }, [apiUrl, clubId, subjectKind, token]);

  if (templates.length === 0) return null;

  return (
    <>
      {templates.map((t) => (
        <CanvaGraphicButton
          key={t.graphic_type}
          clubId={clubId}
          graphicType={t.graphic_type}
          subjectId={subjectId}
          subjectName={subjectName}
          apiUrl={apiUrl}
          // The template's own title is the label, so a club that names a
          // template "Game day post" gets a button that says that. With a single
          // template the generic wording reads better than repeating the noun.
          label={templates.length === 1 ? 'Make a graphic' : t.title}
          className={className}
          skipCheck
        />
      ))}
    </>
  );
};

export default CanvaGraphicActions;
