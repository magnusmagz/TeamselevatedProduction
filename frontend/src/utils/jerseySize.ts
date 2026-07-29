// Canonical jersey sizes, frontend side. Keep in sync with lib/jersey_size.php
// and the athletes_jersey_size_check constraint (migration 054).
//
// Codes are always Y/A-prefixed — 'YM' and 'AM', never a bare 'M'. Youth Medium
// and Adult Medium are very different garments, so an unprefixed size is the
// classic way a uniform order goes wrong. The prefix keeps every value readable
// on its own, including in a vendor CSV.

export type JerseySizeGroup = 'Youth' | 'Adult';

export interface JerseySizeOption {
  /** Stored value, e.g. 'YM'. */
  value: string;
  /** Label inside its optgroup, e.g. 'Medium (10-12)'. */
  label: string;
  /** Standalone label, e.g. 'Youth Medium (10-12)' — for read-only display. */
  fullLabel: string;
  group: JerseySizeGroup;
}

// Age hints are on the youth sizes only, where they map to garment sizing
// reliably enough to help. Adult sizes deliberately carry no hint: adult fit
// varies too much by brand and cut for a number here to be anything but wrong.
export const JERSEY_SIZE_OPTIONS: JerseySizeOption[] = [
  { value: 'YXS', label: 'X-Small (4-5)', fullLabel: 'Youth X-Small (4-5)', group: 'Youth' },
  { value: 'YS', label: 'Small (6-8)', fullLabel: 'Youth Small (6-8)', group: 'Youth' },
  { value: 'YM', label: 'Medium (10-12)', fullLabel: 'Youth Medium (10-12)', group: 'Youth' },
  { value: 'YL', label: 'Large (14-16)', fullLabel: 'Youth Large (14-16)', group: 'Youth' },
  { value: 'YXL', label: 'X-Large (18-20)', fullLabel: 'Youth X-Large (18-20)', group: 'Youth' },
  { value: 'AXS', label: 'X-Small', fullLabel: 'Adult X-Small', group: 'Adult' },
  { value: 'AS', label: 'Small', fullLabel: 'Adult Small', group: 'Adult' },
  { value: 'AM', label: 'Medium', fullLabel: 'Adult Medium', group: 'Adult' },
  { value: 'AL', label: 'Large', fullLabel: 'Adult Large', group: 'Adult' },
  { value: 'AXL', label: 'X-Large', fullLabel: 'Adult X-Large', group: 'Adult' },
  { value: 'A2XL', label: '2X-Large', fullLabel: 'Adult 2X-Large', group: 'Adult' },
  { value: 'A3XL', label: '3X-Large', fullLabel: 'Adult 3X-Large', group: 'Adult' },
];

export const JERSEY_SIZE_GROUPS: JerseySizeGroup[] = ['Youth', 'Adult'];

/** Options for one optgroup of a jersey-size <select>. */
export function jerseySizesInGroup(group: JerseySizeGroup): JerseySizeOption[] {
  return JERSEY_SIZE_OPTIONS.filter((o) => o.group === group);
}

/**
 * Human label for a stored jersey size. Empty string when there is no size, so
 * callers can fall back to their own "Not specified" wording.
 *
 * Unrecognized codes are returned as-is rather than blanked: if a legacy or
 * imported value ever shows up, showing it is more useful than hiding it.
 */
export function formatJerseySize(size: string | null | undefined): string {
  if (!size) return '';
  const match = JERSEY_SIZE_OPTIONS.find((o) => o.value === size.toUpperCase());
  return match ? match.fullLabel : size;
}

/** Short label for tight spots (roster rows, chips): 'Youth M', 'Adult 2XL'. */
export function formatJerseySizeShort(size: string | null | undefined): string {
  if (!size) return '';
  const v = size.toUpperCase();
  const match = JERSEY_SIZE_OPTIONS.find((o) => o.value === v);
  if (!match) return size;
  return `${match.group} ${v.slice(1)}`;
}
