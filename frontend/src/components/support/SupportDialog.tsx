import React, { useEffect, useState } from 'react';
import {
  collectDeviceInfo,
  describeDevice,
  downscaleImage,
  dataUrlBytes,
  MAX_UPLOAD_BYTES,
} from './deviceInfo';
import { getPageTrail, redactPath, PageVisit } from './pageHistory';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

interface Props {
  open: boolean;
  onClose: () => void;
}

/**
 * "Report an issue" — one textarea, one optional screenshot.
 *
 * The device details are shown read-only rather than hidden. Partly courtesy —
 * people are entitled to know what they're sending — and partly because seeing
 * "Chrome on iPhone" reassures them they don't need to explain it.
 *
 * Sends with the auth token when there is one, and works fine without: "I can't
 * sign in" is the report we most need and it has to be filable.
 */
export const SupportDialog: React.FC<Props> = ({ open, onClose }) => {
  const [description, setDescription] = useState('');
  const [screenshot, setScreenshot] = useState<string | null>(null);
  const [screenshotName, setScreenshotName] = useState<string>('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ticketId, setTicketId] = useState<number | null>(null);
  // Snapshotted when the dialog OPENS, not when it mounts. `SupportButton`
  // renders this component permanently with `open={false}`, so a `useRef`
  // initializer would capture the trail at app start — always empty, forever.
  // Frozen from that point so it cannot shift under the reporter while they
  // type, and re-read on each open so a second report gets a fresh one.
  const [pageTrail, setPageTrail] = useState<PageVisit[]>([]);
  useEffect(() => {
    if (open) setPageTrail(getPageTrail());
  }, [open]);

  if (!open) return null;

  const device = collectDeviceInfo();

  const reset = () => {
    setDescription('');
    setScreenshot(null);
    setScreenshotName('');
    setError(null);
    setTicketId(null);
    setBusy(false);
  };

  const close = () => {
    reset();
    onClose();
  };

  const handleFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setError(null);
    try {
      const dataUrl = await downscaleImage(file);
      if (dataUrlBytes(dataUrl) > MAX_UPLOAD_BYTES) {
        setError('That image is still too large after resizing. Try a smaller one.');
        return;
      }
      setScreenshot(dataUrl);
      setScreenshotName(file.name);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not read that image');
    }
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!description.trim()) return;

    setBusy(true);
    setError(null);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(`${API_URL}/api/support-gateway.php?action=create`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({
          description: description.trim(),
          page_url: redactPath(window.location.pathname + window.location.search),
          device_info: device,
          page_history: pageTrail,
          screenshot,
          screenshot_name: screenshotName,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        setError(data.error || 'Could not send that. Please try again.');
        return;
      }
      setTicketId(data.ticket_id);
    } catch {
      setError('Could not reach the server. Check your connection and try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="support-title"
    >
      <div className="bg-white w-full sm:max-w-lg sm:rounded-lg rounded-t-2xl shadow-xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between px-5 py-4 border-b border-brand-secondary">
          <h2 id="support-title" className="text-lg font-semibold text-brand-primary">
            {ticketId ? 'Thanks — we got it' : 'Report an issue'}
          </h2>
          <button
            onClick={close}
            className="text-gray-500 hover:text-gray-800 text-xl px-2"
            aria-label="Close"
          >
            ✕
          </button>
        </div>

        {ticketId ? (
          <div className="px-5 py-6">
            <p className="text-gray-800">
              Your report is with the team. Reference{' '}
              <span className="font-semibold">#{ticketId}</span>.
            </p>
            <p className="text-sm text-gray-600 mt-2">
              If we need more detail, someone will get in touch.
            </p>
            <button
              onClick={close}
              className="mt-5 bg-brand-primary text-white rounded-md px-6 py-3 uppercase font-semibold text-sm"
            >
              Done
            </button>
          </div>
        ) : (
          <form onSubmit={submit} className="px-5 py-4">
            <label htmlFor="support-desc" className="block text-sm font-medium text-brand-primary mb-1">
              What went wrong? *
            </label>
            <textarea
              id="support-desc"
              required
              autoFocus
              rows={5}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Tell us what you were doing and what happened."
              className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
            />

            <label className="block text-sm font-medium text-brand-primary mt-4 mb-1">
              Screenshot (optional)
            </label>
            <input
              type="file"
              accept="image/*"
              onChange={handleFile}
              className="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border file:border-brand-secondary file:bg-white file:text-brand-primary file:text-sm"
            />
            {screenshot && (
              <div className="mt-3">
                <img
                  src={screenshot}
                  alt="Screenshot preview"
                  className="max-h-40 rounded border border-brand-secondary"
                />
                <button
                  type="button"
                  onClick={() => { setScreenshot(null); setScreenshotName(''); }}
                  className="text-sm text-red-600 hover:underline mt-1"
                >
                  Remove
                </button>
              </div>
            )}

            {/* Shown, not hidden — people should know what they're sending. */}
            <p className="text-xs text-gray-500 mt-4">
              We'll include: {describeDevice(device)} · {device.route}
            </p>
            {pageTrail.length > 0 && (
              <details className="text-xs text-gray-500 mt-1">
                <summary className="cursor-pointer">
                  …and the last {pageTrail.length} page{pageTrail.length === 1 ? '' : 's'} you
                  visited
                </summary>
                {/* Listed in full rather than counted. "The pages you visited"
                    is the sort of thing people are entitled to actually read
                    before they send it. */}
                <ul className="mt-1 ml-4 list-disc">
                  {pageTrail.map((v, i) => (
                    <li key={`${v.path}-${i}`}>{v.path}</li>
                  ))}
                </ul>
              </details>
            )}

            {error && (
              <p className="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 mt-3">
                {error}
              </p>
            )}

            <div className="mt-5 flex gap-2">
              <button
                type="submit"
                disabled={busy || !description.trim()}
                className="bg-brand-primary text-white rounded-md px-6 py-3 uppercase font-semibold text-sm disabled:opacity-50"
              >
                {busy ? 'Sending…' : 'Send report'}
              </button>
              <button
                type="button"
                onClick={close}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-3 uppercase font-semibold text-sm"
              >
                Cancel
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};

export default SupportDialog;
