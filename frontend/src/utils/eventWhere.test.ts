import { eventWhere } from './eventWhere';

describe('eventWhere — "Venue · Field" everywhere a game is shown', () => {
  it('prefers venue and field', () => {
    expect(eventWhere({ venue_name: 'North Park', field_name: 'Field 2', location: 'ignored' })).toBe('North Park · Field 2');
  });
  it('falls back to the venue, then the free-text location', () => {
    expect(eventWhere({ venue_name: 'North Park', field_name: null, location: 'x' })).toBe('North Park');
    expect(eventWhere({ venue_name: null, field_name: null, location: 'Away at Rivals' })).toBe('Away at Rivals');
    expect(eventWhere({ venue_name: ' ', field_name: '', location: '' })).toBeNull();
    expect(eventWhere(null)).toBeNull();
  });
});
