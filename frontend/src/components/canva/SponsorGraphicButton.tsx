import React, { useCallback, useEffect, useRef, useState } from 'react';

/**
 * "Make a graphic" for one sponsor.
 *
 * The button HIDES ITSELF when the club has no brand template configured, rather
 * than rendering a control that can only ever return an error. `?action=status`
 * answers that, and "not configured" is a normal answer there — most clubs have
 * no template yet.
 *
 * The PNG is fetched with the bearer token and turned into an object URL, not
 * pointed at with a plain <img src>. api/canva-graphics.php requires auth on the
 * image route (unlike api/club-logo.php, which is public because email clients
 * cannot send headers), so a bare <img> would render a broken image over a JSON
 * 401. Same reasoning as RosterDownloadButton.
 */

interface Props {
  clubId: number;
  sponsorId: number;
  sponsorName: string;
  apiUrl: string;
}

interface Asset {
  id: number;
  width: number | null;
  height: number | null;
  file_size: number | null;
}

const GRAPHIC_TYPE = 'sponsor_thanks';

const SponsorGraphicButton: React.FC<Props> = ({ clubId, sponsorId, sponsorName, apiUrl }) => {
  const [available, setAvailable] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [asset, setAsset] = useState<Asset | null>(null);
  const [imageUrl, setImageUrl] = useState<string | null>(null);

  // Object URLs are a real allocation, not a string. Held in a ref so the
  // cleanup path can revoke the previous one without the effect depending on it.
  const objectUrlRef = useRef<string | null>(null);

  const token = localStorage.getItem('auth_token');

  const releaseImage = useCallback(() => {
    if (objectUrlRef.current) {
      URL.revokeObjectURL(objectUrlRef.current);
      objectUrlRef.current = null;
    }
  }, []);

  useEffect(() => releaseImage, [releaseImage]);

  useEffect(() => {
    let cancelled = false;

    const check = async () => {
      try {
        const res = await fetch(
          `${apiUrl}/api/canva-graphics.php?action=status&club_id=${clubId}&graphic_type=${GRAPHIC_TYPE}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        if (!res.ok) return;
        const data = await res.json();
        if (!cancelled) setAvailable(Boolean(data?.available));
      } catch {
        // A failed check hides the button. It is an enhancement on a page that
        // works without it, so silence is the right failure here.
      }
    };

    check();
    return () => {
      cancelled = true;
    };
  }, [apiUrl, clubId, token]);

  const generate = async () => {
    setBusy(true);
    setError(null);
    releaseImage();
    setImageUrl(null);

    try {
      const res = await fetch(`${apiUrl}/api/canva-graphics.php?action=generate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          club_id: clubId,
          graphic_type: GRAPHIC_TYPE,
          subject_id: sponsorId,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data?.success) {
        // The backend's 422 messages are written for a club admin to read, so
        // they are shown rather than replaced with something generic.
        throw new Error(data?.error || 'The graphic could not be generated.');
      }

      setAsset(data.asset);

      const imgRes = await fetch(
        `${apiUrl}/api/canva-graphics.php?action=image&id=${data.asset.id}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      if (!imgRes.ok) throw new Error('The graphic was made but could not be loaded.');

      const url = URL.createObjectURL(await imgRes.blob());
      objectUrlRef.current = url;
      setImageUrl(url);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  };

  if (!available) return null;

  return (
    <>
      <button
        onClick={generate}
        disabled={busy}
        className="text-brand-primary hover:text-brand-primary uppercase text-xs font-semibold disabled:opacity-50"
      >
        {busy ? 'Making…' : 'Make a graphic'}
      </button>

      {(imageUrl || error) && (
        <div
          className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4"
          onClick={() => {
            releaseImage();
            setImageUrl(null);
            setError(null);
          }}
        >
          <div
            className="bg-white rounded-lg p-5 max-w-lg w-full"
            onClick={(e) => e.stopPropagation()}
          >
            {error ? (
              <>
                <h3 className="font-semibold mb-2">Could not make the graphic</h3>
                <p className="text-sm text-gray-700">{error}</p>
              </>
            ) : (
              <>
                <h3 className="font-semibold mb-3">Thank you graphic — {sponsorName}</h3>
                <img
                  src={imageUrl as string}
                  alt={`Thank you graphic for ${sponsorName}`}
                  className="w-full rounded border border-gray-200"
                />
                {asset?.width && asset?.height && (
                  <p className="text-xs text-gray-500 mt-2">
                    {asset.width} × {asset.height}
                  </p>
                )}
                <a
                  href={imageUrl as string}
                  download={`${sponsorName.replace(/[^\w-]+/g, '-').toLowerCase()}-thank-you.png`}
                  className="inline-block mt-4 bg-brand-primary text-white px-4 py-2 rounded text-sm"
                >
                  Download
                </a>
              </>
            )}
            <button
              onClick={() => {
                releaseImage();
                setImageUrl(null);
                setError(null);
              }}
              className="ml-3 mt-4 text-sm text-gray-600"
            >
              Close
            </button>
          </div>
        </div>
      )}
    </>
  );
};

export default SponsorGraphicButton;
