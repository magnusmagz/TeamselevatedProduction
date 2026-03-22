import React, { useState, useEffect } from 'react';

interface TocItem {
  id: string;
  text: string;
  level: number;
}

interface Props {
  markdown: string;
}

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/[\s]+/g, '-')
    .replace(/-+/g, '-')
    .trim();
}

function extractHeadings(markdown: string): TocItem[] {
  const headings: TocItem[] = [];
  const lines = markdown.split('\n');
  for (const line of lines) {
    const match = line.match(/^(#{2,3})\s+(.+)$/);
    if (match) {
      const text = match[2].replace(/\*\*/g, '').replace(/`/g, '');
      headings.push({
        id: slugify(text),
        text,
        level: match[1].length,
      });
    }
  }
  return headings;
}

const HelpTableOfContents: React.FC<Props> = ({ markdown }) => {
  const [activeId, setActiveId] = useState<string>('');
  const headings = extractHeadings(markdown);

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            setActiveId(entry.target.id);
          }
        }
      },
      { rootMargin: '-80px 0px -60% 0px' }
    );

    headings.forEach((h) => {
      const el = document.getElementById(h.id);
      if (el) observer.observe(el);
    });

    return () => observer.disconnect();
  }, [headings]);

  if (headings.length < 2) return null;

  return (
    <nav className="sticky top-24">
      <h4 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">On this page</h4>
      <ul className="space-y-1 border-l border-gray-200">
        {headings.map((h) => (
          <li key={h.id}>
            <a
              href={`#${h.id}`}
              onClick={(e) => {
                e.preventDefault();
                document.getElementById(h.id)?.scrollIntoView({ behavior: 'smooth' });
              }}
              className={`block text-sm py-1 transition-colors ${
                h.level === 3 ? 'pl-6' : 'pl-3'
              } ${
                activeId === h.id
                  ? 'text-brand-primary font-medium border-l-2 border-brand-primary -ml-px'
                  : 'text-gray-500 hover:text-gray-800'
              }`}
            >
              {h.text}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  );
};

export default HelpTableOfContents;
