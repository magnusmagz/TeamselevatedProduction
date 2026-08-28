import React from 'react';
import { PollView, describeDeadline } from './pollTypes';

interface Props {
  poll: PollView;
  onVote: (optionId: string) => void;
  /** Dark bubble (your own message) needs light text. */
  onDark?: boolean;
}

/**
 * A poll inside a chat message.
 *
 * Results are a bar per option rather than a number alone: at a glance you want
 * "Friday is winning", not arithmetic. The bar is sized from the leading option
 * so a two-vote poll still reads, rather than from the total, which would leave
 * every bar a sliver early on.
 *
 * Voter names appear when the poll is named and results are visible. The server
 * simply does not send them for an anonymous poll, so there is nothing here to
 * accidentally reveal.
 */
export const PollMessage: React.FC<Props> = ({ poll, onVote, onDark }) => {
  const deadline = describeDeadline(poll);
  const leading = Math.max(1, ...poll.options.map((o) => o.votes ?? 0));

  const muted = onDark ? 'text-brand-light' : 'text-gray-500';
  const rule = onDark ? 'border-white/25' : 'border-gray-200';

  return (
    <div className="min-w-[15rem]">
      <p className="text-sm font-semibold mb-2">{poll.question}</p>

      <div className="flex flex-col gap-1.5">
        {poll.options.map((option) => {
          const votes = option.votes ?? 0;
          const width = poll.showResults ? Math.round((votes / leading) * 100) : 0;

          return (
            <button
              key={option.id}
              type="button"
              onClick={() => onVote(option.id)}
              disabled={poll.closed}
              aria-pressed={option.youVoted}
              title={
                option.voters && option.voters.length
                  ? option.voters.map((v) => v.name).join(', ')
                  : undefined
              }
              className={`relative w-full text-left rounded-md border px-2.5 py-1.5 text-sm overflow-hidden transition-colors ${
                option.youVoted
                  ? onDark
                    ? 'border-white bg-white/15 font-semibold'
                    : 'border-brand-primary bg-brand-secondary/50 font-semibold'
                  : onDark
                    ? 'border-white/30 hover:bg-white/10'
                    : 'border-gray-200 hover:bg-gray-50'
              } ${poll.closed ? 'cursor-default opacity-90' : ''}`}
            >
              {poll.showResults && (
                <span
                  aria-hidden="true"
                  className={`absolute inset-y-0 left-0 ${onDark ? 'bg-white/15' : 'bg-brand-secondary/60'}`}
                  style={{ width: `${width}%` }}
                />
              )}
              <span className="relative flex items-center justify-between gap-2">
                <span className="truncate">
                  {option.youVoted && <span aria-hidden="true">✓ </span>}
                  {option.label}
                </span>
                {poll.showResults && (
                  <span className="tabular-nums text-xs shrink-0">{votes}</span>
                )}
              </span>
            </button>
          );
        })}
      </div>

      <div className={`mt-2 pt-1.5 border-t ${rule} flex items-center justify-between gap-2 text-xs ${muted}`}>
        <span>
          {poll.showResults
            ? `${poll.totalVotes ?? 0} ${poll.totalVotes === 1 ? 'vote' : 'votes'}`
            : 'Vote to see results'}
          {poll.isAnonymous && ' · anonymous'}
        </span>
        {deadline && <span>{deadline}</span>}
      </div>
    </div>
  );
};

export default PollMessage;
