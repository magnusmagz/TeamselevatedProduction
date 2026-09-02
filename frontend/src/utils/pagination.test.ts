import { PageMeta, pageQuery, readPage, rowsFrom } from './pagination';

/**
 * The client half of the pagination contract.
 *
 * The case that matters most is the one in the middle: a response with NO `page`
 * key. That is an older backend, which was not paginating, so "no more pages" is
 * the truthful reading — and getting it the other way round would show a
 * Load more button that fetches nothing forever.
 */
describe('readPage', () => {
  it('reads a page block', () => {
    const page = readPage({ page: { limit: 200, next_cursor: 'abc', truncated: true } });
    expect(page).toEqual<PageMeta>({ limit: 200, nextCursor: 'abc', truncated: true });
  });

  it('reports the last page as not truncated, with no cursor', () => {
    expect(readPage({ page: { limit: 200, next_cursor: null, truncated: false } })).toEqual({
      limit: 200,
      nextCursor: null,
      truncated: false,
    });
  });

  it('returns null when there is no page block — an older backend is not truncating', () => {
    expect(readPage({ athletes: [] })).toBeNull();
    expect(readPage([])).toBeNull();
    expect(readPage(null)).toBeNull();
    expect(readPage(undefined)).toBeNull();
    expect(readPage('nope')).toBeNull();
  });

  it('treats an empty-string cursor as no cursor', () => {
    const page = readPage({ page: { limit: 10, next_cursor: '', truncated: false } });
    expect(page?.nextCursor).toBeNull();
  });

  it('falls back to the cursor when truncated is not a boolean', () => {
    expect(readPage({ page: { limit: 10, next_cursor: 'x' } })?.truncated).toBe(true);
    expect(readPage({ page: { limit: 10, next_cursor: null } })?.truncated).toBe(false);
  });
});

describe('pageQuery', () => {
  it('adds nothing for a default first page', () => {
    expect(pageQuery(null)).toBe('');
  });

  it('prefixes with & so it appends to an existing query string', () => {
    expect(pageQuery(null, 1000)).toBe('&limit=1000');
    expect(pageQuery('abc')).toBe('&cursor=abc');
    expect(pageQuery('abc', 50)).toBe('&limit=50&cursor=abc');
  });

  it('encodes the cursor', () => {
    expect(pageQuery('a b&c=d')).toBe('&cursor=a%20b%26c%3Dd');
  });
});

describe('rowsFrom', () => {
  it('accepts the OLD bare-array shape and the NEW keyed one', () => {
    // legacy/coaches-gateway.php?action=available changed shape in this slice,
    // and the frontend ships before the backend — so both must work.
    expect(rowsFrom<number>([1, 2, 3], 'coaches')).toEqual([1, 2, 3]);
    expect(rowsFrom<number>({ success: true, coaches: [1, 2] }, 'coaches')).toEqual([1, 2]);
  });

  it('returns an empty list for an error body rather than throwing', () => {
    expect(rowsFrom({ error: 'Access denied' }, 'coaches')).toEqual([]);
    expect(rowsFrom(null, 'coaches')).toEqual([]);
  });
});
