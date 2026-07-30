/**
 * The 10 template tags, in display order.
 *
 * Shared by the email template library and the SMS template library so the two
 * cannot drift: SMS shipped with its own four-item list (`General`, `Game Day`,
 * `Practice`, `Administrative`) that matched almost nothing in the data, so its
 * category filter silently returned empty for most of the library and its editor
 * wrote slugs no page could read back.
 *
 * Ordered by how often a club reaches for them: onboarding first, then the
 * week-to-week rhythm, with tournament last since only some clubs run them.
 * `slug` is what's stored in `email_templates.category` (both channels live in
 * that table); `label` / `color` drive the UI.
 */
export interface CategoryMeta {
  slug: string;
  label: string;
  color: string;
}

export const CATEGORY_META: CategoryMeta[] = [
  { slug: 'registration', label: 'Registration & Welcome', color: 'bg-blue-100 text-blue-700' },
  { slug: 'schedule', label: 'Schedule & Weather', color: 'bg-cyan-100 text-cyan-700' },
  { slug: 'team_events', label: 'Team Events', color: 'bg-purple-100 text-purple-700' },
  { slug: 'game_day', label: 'Game Day', color: 'bg-orange-100 text-orange-700' },
  { slug: 'community', label: 'Community & Fundraising', color: 'bg-pink-100 text-pink-700' },
  { slug: 'awards', label: 'Awards & Milestones', color: 'bg-amber-100 text-amber-800' },
  { slug: 'health', label: 'Health & Wellness', color: 'bg-green-100 text-green-700' },
  { slug: 'season', label: 'Season & Offseason', color: 'bg-teal-100 text-teal-700' },
  { slug: 'holidays', label: 'Holidays', color: 'bg-indigo-100 text-indigo-700' },
  { slug: 'tournament', label: 'Tournament', color: 'bg-red-100 text-red-700' },
];

export const OTHER_META: CategoryMeta = {
  slug: 'other',
  label: 'Other',
  color: 'bg-gray-100 text-gray-700',
};

const find = (slug: string) =>
  CATEGORY_META.find((c) => c.slug === (slug || '').toLowerCase());

export const categoryLabel = (slug: string): string => find(slug)?.label ?? OTHER_META.label;
export const categoryColor = (slug: string): string => find(slug)?.color ?? OTHER_META.color;

/** A stored category, normalized to a known slug — anything unknown becomes "other". */
export const categorySlug = (category: string | null | undefined): string =>
  find(category || '') ? (category as string).toLowerCase() : OTHER_META.slug;

/** Per-slug counts, for the chip cluster. */
export const countByCategory = <T extends { category: string }>(
  items: T[]
): Record<string, number> =>
  items.reduce((acc, item) => {
    const slug = categorySlug(item.category);
    acc[slug] = (acc[slug] || 0) + 1;
    return acc;
  }, {} as Record<string, number>);

/**
 * Bucket items into CATEGORY_META order, with anything uncategorized collected
 * under "Other" at the end. Empty categories are omitted.
 */
export const groupByCategory = <T extends { category: string }>(
  items: T[]
): Array<CategoryMeta & { items: T[] }> => {
  const buckets = new Map<string, T[]>();
  items.forEach((item) => {
    const slug = categorySlug(item.category);
    if (!buckets.has(slug)) buckets.set(slug, []);
    buckets.get(slug)!.push(item);
  });
  return [...CATEGORY_META, OTHER_META]
    .filter((c) => buckets.has(c.slug))
    .map((c) => ({ ...c, items: buckets.get(c.slug)! }));
};
