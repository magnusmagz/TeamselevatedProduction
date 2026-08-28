import { canCreatePoll, describeDeadline, PollView } from './pollTypes';

const poll = (over: Partial<PollView> = {}): PollView => ({
  id: '5', question: 'Team dinner?', isAnonymous: false, allowMultiple: false,
  closesAt: null, closed: false, youVoted: false, showResults: true,
  totalVotes: 0, options: [], ...over,
});

describe('canCreatePoll', () => {
  it('allows coaches and club admins', () => {
    expect(canCreatePoll('coach')).toBe(true);
    expect(canCreatePoll('club_admin')).toBe(true);
    expect(canCreatePoll('super_admin')).toBe(true);
  });

  /**
   * Families cannot create polls. The button is hidden for them, and the server
   * refuses independently — a hidden button is not an access control.
   */
  it('does not allow families or an unknown role', () => {
    expect(canCreatePoll('parent')).toBe(false);
    expect(canCreatePoll('player')).toBe(false);
    expect(canCreatePoll(undefined)).toBe(false);
  });
});

describe('describeDeadline', () => {
  it('says nothing when a poll never closes', () => {
    expect(describeDeadline(poll())).toBe('');
  });

  it('says Closed once it has', () => {
    expect(describeDeadline(poll({ closesAt: '2020-01-01T00:00:00Z', closed: true }))).toBe('Closed');
  });

  it('names the deadline while it is still open', () => {
    expect(describeDeadline(poll({ closesAt: '2099-01-01T18:00:00Z' }))).toMatch(/^Closes /);
  });

  /** A malformed date must not put "Invalid Date" in front of a parent. */
  it('says nothing rather than showing a broken date', () => {
    expect(describeDeadline(poll({ closesAt: 'not a date' }))).toBe('');
  });
});
