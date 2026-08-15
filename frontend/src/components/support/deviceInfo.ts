/**
 * What we collect about the reporter's setup, and what we downscale before sending.
 *
 * The user types ONE thing: what went wrong. Everything else is captured, because
 * "what browser are you using?" is a question nobody should have to answer to
 * report a bug.
 */

export interface DeviceInfo {
  user_agent: string;
  viewport: string;
  screen: string;
  pixel_ratio: number;
  language: string;
  timezone: string;
  online: boolean;
  route: string;
}

export function collectDeviceInfo(): DeviceInfo {
  return {
    user_agent: navigator.userAgent,
    viewport: `${window.innerWidth}x${window.innerHeight}`,
    screen: `${window.screen.width}x${window.screen.height}`,
    pixel_ratio: window.devicePixelRatio || 1,
    language: navigator.language,
    // Wrapped: Intl can throw on some locked-down/older browsers, and failing to
    // read a timezone must never stop someone filing a report.
    timezone: (() => {
      try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'unknown';
      } catch {
        return 'unknown';
      }
    })(),
    online: navigator.onLine,
    route: window.location.pathname + window.location.search,
  };
}

/** Human-readable one-liner, shown to the reporter so they can see what they're sending. */
export function describeDevice(d: DeviceInfo): string {
  const ua = d.user_agent;
  const os =
    /iPhone/i.test(ua) ? 'iPhone' :
    /iPad/i.test(ua) ? 'iPad' :
    /Android/i.test(ua) ? 'Android' :
    /Mac OS X/i.test(ua) ? 'macOS' :
    /Windows/i.test(ua) ? 'Windows' :
    /Linux/i.test(ua) ? 'Linux' : 'Unknown OS';

  // Order matters: Edge's UA contains "Chrome", and Chrome's contains "Safari".
  const browser =
    /Edg\//i.test(ua) ? 'Edge' :
    /CriOS/i.test(ua) ? 'Chrome' :
    /FxiOS/i.test(ua) ? 'Firefox' :
    /Firefox/i.test(ua) ? 'Firefox' :
    /Chrome/i.test(ua) ? 'Chrome' :
    /Safari/i.test(ua) ? 'Safari' : 'your browser';

  return `${browser} on ${os} · ${d.viewport}`;
}

/** Longest edge, in pixels, after downscaling. */
const MAX_EDGE = 1600;

/** Server rejects anything larger; we aim well under it. */
export const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

/**
 * Downscale a screenshot to something a database can reasonably hold.
 *
 * A modern phone screenshot is 3–6 MB; this lands around 300 KB. That reduction
 * is what makes storing these in Postgres viable at all — without it the 2 MB
 * server cap would reject most real screenshots from the devices families use.
 *
 * Falls back to the original file if anything in the canvas path fails (very old
 * browsers, exotic formats). The size check still applies afterwards, so a
 * failed downscale surfaces as "too large" rather than a broken upload.
 */
export function downscaleImage(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const readAsDataUrl = () => {
      const fr = new FileReader();
      fr.onload = () => resolve(String(fr.result));
      fr.onerror = () => reject(new Error('Could not read that file'));
      fr.readAsDataURL(file);
    };

    if (!file.type.startsWith('image/')) {
      reject(new Error('Please choose an image'));
      return;
    }

    const url = URL.createObjectURL(file);
    const img = new Image();

    img.onload = () => {
      try {
        const scale = Math.min(1, MAX_EDGE / Math.max(img.width, img.height));
        // Already small enough — re-encoding would only lose quality.
        if (scale >= 1 && file.size <= MAX_UPLOAD_BYTES) {
          URL.revokeObjectURL(url);
          readAsDataUrl();
          return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);

        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('no 2d context');
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

        // JPEG: screenshots of UI compress well and we do not need alpha.
        const out = canvas.toDataURL('image/jpeg', 0.8);
        URL.revokeObjectURL(url);
        resolve(out);
      } catch {
        URL.revokeObjectURL(url);
        readAsDataUrl();
      }
    };

    img.onerror = () => {
      URL.revokeObjectURL(url);
      readAsDataUrl();
    };

    img.src = url;
  });
}

/** Rough byte size of a data URI's payload, for the pre-send size check. */
export function dataUrlBytes(dataUrl: string): number {
  const comma = dataUrl.indexOf(',');
  const b64 = comma >= 0 ? dataUrl.slice(comma + 1) : dataUrl;
  // 4 base64 chars encode 3 bytes; subtract padding.
  const padding = b64.endsWith('==') ? 2 : b64.endsWith('=') ? 1 : 0;
  return Math.floor((b64.length * 3) / 4) - padding;
}
