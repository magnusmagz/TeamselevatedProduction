/** A poll option as the server renders it for one viewer. */
export interface PollOption {
  id: string;
  label: string;
  youVoted: boolean;
  /** null when results are hidden until you vote. */
  votes: number | null;
  /** Absent entirely on an anonymous poll — never blanked, never sent. */
  voters?: { id: string; name: string }[];
}

export interface PollView {
  id: string;
  question: string;
  isAnonymous: boolean;
  allowMultiple: boolean;
  closesAt: string | null;
  closed: boolean;
  youVoted: boolean;
  showResults: boolean;
  totalVotes: number | null;
  options: PollOption[];
}

/** Roles that may create a poll. Mirrors canCreatePoll in chat-server/lib/polls.js. */
export const POLL_CREATOR_ROLES = ['super_admin', 'owner', 'club_admin', 'admin', 'coach'];

export function canCreatePoll(role?: string): boolean {
  return !!role && POLL_CREATOR_ROLES.includes(role);
}

/** "Closes Friday 18:00", "Closed" — enough to know whether to hurry. */
export function describeDeadline(poll: PollView): string {
  if (!poll.closesAt) return '';
  const when = new Date(poll.closesAt);
  if (Number.isNaN(when.getTime())) return '';

  if (poll.closed) return 'Closed';

  return `Closes ${when.toLocaleString(undefined, {
    weekday: 'short', hour: '2-digit', minute: '2-digit',
  })}`;
}
