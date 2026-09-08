/**
 * Where an event is — one rule for every surface that shows a game.
 *
 * "Venue · Field" when a pitch is chosen (calendar_events.field_id, migration
 * 100); otherwise the venue; otherwise the free-text location; otherwise null.
 * The server-side twin is te_event_place_label() in lib/event_field.php —
 * keep the two in step.
 *
 * `location` is deliberately NOT appended after a venue. It is the free-text
 * fallback for an event booked somewhere without a venue record ("Away at
 * Rivals"), and the calendar cards have never shown both; the referee page
 * still shows both because a referee reads directions there
 * (see whereLine in pages/RefereeHome.tsx).
 */
export interface EventPlace {
  venue_name?: string | null;
  field_name?: string | null;
  location?: string | null;
}

const clean = (s?: string | null): string => (s ?? '').trim();

export function eventWhere(e: EventPlace | null | undefined): string | null {
  if (!e) return null;
  const venue = clean(e.venue_name);
  const field = clean(e.field_name);
  if (venue && field) return `${venue} · ${field}`;
  if (venue) return venue;
  if (field) return field;
  return clean(e.location) || null;
}
