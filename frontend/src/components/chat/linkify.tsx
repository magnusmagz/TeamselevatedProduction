import React from 'react';

/**
 * Turn the URLs inside a chat message into links. Everything else is emitted
 * as plain text nodes, so nothing a sender types can become markup — only the
 * anchors this file creates exist.
 *
 * Matches http(s):// and bare www. addresses. Trailing punctuation that is
 * almost always sentence punctuation rather than part of the address
 * (`.`, `,`, `!`, `?`, `:`, `;`, a closing paren or quote) is left outside the
 * link. Nothing else — no javascript:, no mailto:, no bare domains without
 * www. — is linked, on purpose.
 */
const URL_PATTERN = /((?:https?:\/\/|www\.)[^\s<]+)/gi;
const TRAILING = /[.,!?:;)'"\]]+$/;

export function splitLinks(text: string): Array<{ kind: 'text' | 'link'; value: string; href?: string }> {
  const out: Array<{ kind: 'text' | 'link'; value: string; href?: string }> = [];
  let last = 0;
  const re = new RegExp(URL_PATTERN.source, 'gi');
  let m: RegExpExecArray | null;
  while ((m = re.exec(text)) !== null) {
    const start = m.index;
    let raw = m[0];
    const trail = raw.match(TRAILING)?.[0] ?? '';
    raw = raw.slice(0, raw.length - trail.length);
    if (start > last) out.push({ kind: 'text', value: text.slice(last, start) });
    if (raw) {
      const href = /^https?:\/\//i.test(raw) ? raw : `https://${raw}`;
      out.push({ kind: 'link', value: raw, href });
    }
    if (trail) out.push({ kind: 'text', value: trail });
    last = start + m[0].length;
  }
  if (last < text.length) out.push({ kind: 'text', value: text.slice(last) });
  return out;
}

export function linkify(text: string, className = 'underline break-all'): React.ReactNode {
  const parts = splitLinks(text ?? '');
  if (parts.every((p) => p.kind === 'text')) return text;
  return parts.map((p, i) =>
    p.kind === 'link' ? (
      <a
        key={i}
        href={p.href}
        target="_blank"
        rel="noopener noreferrer"
        className={className}
        onClick={(e) => e.stopPropagation()}
      >
        {p.value}
      </a>
    ) : (
      <React.Fragment key={i}>{p.value}</React.Fragment>
    )
  );
}
