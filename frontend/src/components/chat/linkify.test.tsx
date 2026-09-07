import React from 'react';
import { render, screen } from '@testing-library/react';
import { linkify, splitLinks } from './linkify';

describe('linkify', () => {
  test('plain text comes back unchanged', () => {
    expect(linkify('Practice moved to 6pm')).toBe('Practice moved to 6pm');
  });

  test('an https URL becomes a new-tab link and the sentence punctuation stays outside it', () => {
    render(<p>{linkify('Field map: https://maps.example.com/rac?f=3. See you there!')}</p>);
    const a = screen.getByRole('link') as HTMLAnchorElement;
    expect(a.getAttribute('href')).toBe('https://maps.example.com/rac?f=3');
    expect(a.textContent).toBe('https://maps.example.com/rac?f=3');
    expect(a.getAttribute('target')).toBe('_blank');
    expect(a.getAttribute('rel')).toContain('noopener');
    expect(screen.getByText(/See you there!/)).toBeInTheDocument();
  });

  test('a www. address gets https:// on the href but keeps its text', () => {
    const parts = splitLinks('see www.cku.org/tryouts today');
    expect(parts).toEqual([
      { kind: 'text', value: 'see ' },
      { kind: 'link', value: 'www.cku.org/tryouts', href: 'https://www.cku.org/tryouts' },
      { kind: 'text', value: ' today' },
    ]);
  });

  test('nothing a sender types can become markup, and javascript: is never a link', () => {
    render(<p>{linkify('<img src=x onerror=alert(1)> javascript:alert(1) http://ok.example')}</p>);
    expect(document.querySelector('img')).toBeNull();
    const links = screen.getAllByRole('link');
    expect(links).toHaveLength(1);
    expect(links[0].getAttribute('href')).toBe('http://ok.example');
    expect(screen.getByText(/<img src=x onerror=alert\(1\)>/)).toBeInTheDocument();
  });
});
