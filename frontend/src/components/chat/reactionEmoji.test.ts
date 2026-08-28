import { REACTION_EMOJI, describeReactors } from './reactionEmoji';

/**
 * Reactions were half-built for seven months: table, server handlers and client
 * helpers all shipped in January, and nothing ever called them. Zero rows across
 * 366 production messages. Finished 2026-08-28.
 */
describe('reaction emoji', () => {
  it('is the six agreed, in order', () => {
    expect([...REACTION_EMOJI]).toEqual(['👍', '❤️', '🎉', '👏', '😂', '😮']);
  });

  /**
   * A thumbs-down or an angry face in a parent group chat creates conflict a
   * message never would, and once it is on screen it gets used. Adding one is a
   * product decision, not a tweak.
   */
  it('offers nothing negative', () => {
    for (const negative of ['👎', '😡', '🤬', '💩']) {
      expect(REACTION_EMOJI).not.toContain(negative);
    }
  });

  it('fits one row on a phone', () => {
    expect(REACTION_EMOJI.length).toBeLessThanOrEqual(6);
  });
});

describe('describeReactors', () => {
  const named = (...names: string[]) => names.map((name) => ({ name }));

  it('names one person', () => {
    expect(describeReactors(named('Pat'))).toBe('Pat');
  });

  it('joins two with and', () => {
    expect(describeReactors(named('Pat', 'Cora'))).toBe('Pat and Cora');
  });

  it('lists three', () => {
    expect(describeReactors(named('Pat', 'Cora', 'Alex'))).toBe('Pat, Cora and Alex');
  });

  /** Past three, names stop being the useful part and length starts to hurt. */
  it('summarises beyond three', () => {
    expect(describeReactors(named('Pat', 'Cora', 'Alex', 'Sam', 'Jo')))
      .toBe('Pat, Cora and 3 others');
  });

  it('says nothing when nobody has reacted', () => {
    expect(describeReactors([])).toBe('');
  });
});
