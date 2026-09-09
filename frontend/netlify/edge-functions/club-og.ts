// Open Graph tags for /club/<slug> (2026-09-09).
//
// The site is a single-page app: every route is served the same index.html and
// the page fills itself in with JavaScript. Link previews (iMessage, Facebook,
// Instagram, Slack, X) never run that JavaScript, so a shared club link showed
// "Teams Elevated" and no image. This edge function runs on every request to
// /club/*, asks the backend for the club's public payload, and writes the
// club's name, tagline and logo into <head> before the HTML leaves Netlify.
//
// It fails OPEN: any error, a 404 for the slug, or a slow backend returns the
// untouched page, so a broken preview can never become a broken page. The
// backend call carries a short timeout for the same reason.
//
// Public data only — the same payload lib/club_public_page.php serves to
// anyone. Nothing here can see more than a stranger can.

import type { Context } from "https://edge.netlify.com";

const BACKEND = "https://teamselevated-backend-0485388bd66e.herokuapp.com";
const TIMEOUT_MS = 2500;

function escapeAttr(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

export default async function handler(request: Request, context: Context) {
  const url = new URL(request.url);
  const m = url.pathname.match(/^\/club\/([a-z0-9-]{3,60})\/?$/i);
  const response = await context.next();
  if (!m) return response;

  const contentType = response.headers.get("content-type") || "";
  if (!contentType.includes("text/html")) return response;

  let club: {
    name: string; slug: string; tagline: string | null; city: string | null; state: string | null;
    og_image: string | null;
  } | null = null;
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    const r = await fetch(`${BACKEND}/api/club-public-gateway.php?action=page&slug=${encodeURIComponent(m[1])}`, {
      signal: ctrl.signal,
      headers: { accept: "application/json" },
    });
    clearTimeout(t);
    if (r.ok) {
      const j = await r.json();
      if (j && j.club) club = j.club;
    }
  } catch {
    return response;
  }
  if (!club) return response;

  const html = await response.text();
  const pageUrl = `${url.origin}/club/${club.slug}`;
  const place = [club.city, club.state].filter(Boolean).join(", ");
  const description = club.tagline || (place ? `${club.name} · ${place} · schedule, coaches and contact` : `${club.name} · schedule, coaches and contact`);
  const image = club.og_image || `${url.origin}/logo.png`;

  const tags = [
    `<meta property="og:type" content="website">`,
    `<meta property="og:site_name" content="Teams Elevated">`,
    `<meta property="og:title" content="${escapeAttr(club.name)}">`,
    `<meta property="og:description" content="${escapeAttr(description)}">`,
    `<meta property="og:url" content="${escapeAttr(pageUrl)}">`,
    `<meta property="og:image" content="${escapeAttr(image)}">`,
    `<meta name="twitter:card" content="summary">`,
    `<meta name="twitter:title" content="${escapeAttr(club.name)}">`,
    `<meta name="twitter:description" content="${escapeAttr(description)}">`,
    `<meta name="twitter:image" content="${escapeAttr(image)}">`,
  ].join("\n    ");

  const out = html
    .replace(/<title>[^<]*<\/title>/, `<title>${escapeAttr(club.name)} — Teams Elevated</title>`)
    .replace(/<meta name="description" content="[^"]*"\s*\/?>/, `<meta name="description" content="${escapeAttr(description)}">`)
    .replace("</head>", `    ${tags}\n  </head>`);

  const headers = new Headers(response.headers);
  headers.delete("content-length");
  headers.set("cache-control", "public, max-age=300");
  return new Response(out, { status: response.status, headers });
}

export const config = { path: "/club/*" };
