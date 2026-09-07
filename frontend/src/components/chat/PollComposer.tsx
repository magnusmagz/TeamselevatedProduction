import React from 'react';
import Button from '../ui/Button';

interface Props {
  onCreate: (input: {
    question: string; options: string[]; isAnonymous: boolean;
    allowMultiple: boolean; resultsBeforeVote: boolean; closesAt: string | null;
  }) => void;
  onCancel: () => void;
}

const MAX_OPTIONS = 10;

/**
 * Creating a poll.
 *
 * Two shapes offered: **Yes / No**, which is a choice poll with the options
 * filled in, and **Choice**, where you write them. Yes/No is not a separate
 * mechanism — it costs no extra storage and no extra rendering — but it is
 * offered as its own button because "Friday at 7?" is common enough that making
 * someone type Yes and No is friction for nothing.
 *
 * ⚠️ Anonymity is set here and NEVER afterwards. Flipping an anonymous poll to
 * named exposes votes cast under a promise; the other way discards what people
 * expected to see. The server refuses to change it, and the copy says so before
 * anyone commits.
 */
export const PollComposer: React.FC<Props> = ({ onCreate, onCancel }) => {
  const [question, setQuestion] = React.useState('');
  const [options, setOptions] = React.useState(['', '']);
  const [isAnonymous, setIsAnonymous] = React.useState(false);
  const [allowMultiple, setAllowMultiple] = React.useState(false);
  const [resultsBeforeVote, setResultsBeforeVote] = React.useState(true);
  const [closesAt, setClosesAt] = React.useState('');
  const [error, setError] = React.useState<string | null>(null);

  const setOption = (i: number, value: string) =>
    setOptions((prev) => prev.map((o, idx) => (idx === i ? value : o)));

  const useYesNo = () => setOptions(['Yes', 'No']);

  const submit = () => {
    const filled = options.map((o) => o.trim()).filter(Boolean);

    if (!question.trim()) return setError('Give the poll a question');
    if (filled.length < 2) return setError('A poll needs at least two options');
    if (new Set(filled.map((o) => o.toLowerCase())).size !== filled.length) {
      return setError('Two options are the same');
    }

    setError(null);
    onCreate({
      question: question.trim(),
      options: filled,
      isAnonymous,
      allowMultiple,
      resultsBeforeVote,
      // datetime-local has no zone; the browser's own offset is what the person
      // meant when they picked a time.
      closesAt: closesAt ? new Date(closesAt).toISOString() : null,
    });
  };

  return (
    <div className="p-3 border-t border-brand-secondary bg-white">
      <div className="flex items-center justify-between mb-2">
        <h3 className="text-sm font-semibold text-brand-primary">New poll</h3>
        <Button variant="link" size="sm" onClick={onCancel}>
          Cancel
        </Button>
      </div>

      <input
        value={question}
        onChange={(e) => setQuestion(e.target.value)}
        placeholder="Team dinner — which night?"
        maxLength={300}
        className="w-full border border-brand-secondary rounded-md px-2.5 py-1.5 text-sm mb-2"
      />

      <div className="flex flex-col gap-1.5 mb-2">
        {options.map((option, i) => (
          <div key={i} className="flex items-center gap-1.5">
            <input
              value={option}
              onChange={(e) => setOption(i, e.target.value)}
              placeholder={`Option ${i + 1}`}
              maxLength={100}
              className="flex-1 border border-brand-secondary rounded-md px-2.5 py-1.5 text-sm"
            />
            {options.length > 2 && (
              <Button
                variant="ghost"
                size="icon"
                onClick={() => setOptions((prev) => prev.filter((_, idx) => idx !== i))}
                aria-label={`Remove option ${i + 1}`}
              >
                ✕
              </Button>
            )}
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2 mb-3 text-sm">
        {options.length < MAX_OPTIONS && (
          <Button variant="link" size="sm" onClick={() => setOptions((prev) => [...prev, ''])}>
            Add option
          </Button>
        )}
        <Button variant="link" size="sm" onClick={useYesNo}>
          Use Yes / No
        </Button>
      </div>

      <div className="flex flex-col gap-1.5 mb-3 text-sm">
        <label className="flex items-center gap-2">
          <input type="checkbox" checked={isAnonymous} onChange={(e) => setIsAnonymous(e.target.checked)} />
          <span>Make anonymous</span>
        </label>
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={allowMultiple}
            onChange={(e) => setAllowMultiple(e.target.checked)}
          />
          <span>Let people pick more than one</span>
        </label>
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={!resultsBeforeVote}
            onChange={(e) => setResultsBeforeVote(!e.target.checked)}
          />
          <span>Hide results until someone votes</span>
        </label>
        <label className="flex items-center gap-2">
          <span className="text-gray-600">Closes</span>
          <input
            type="datetime-local"
            value={closesAt}
            onChange={(e) => setClosesAt(e.target.value)}
            className="border border-brand-secondary rounded-md px-2 py-1 text-sm"
          />
        </label>
      </div>

      {isAnonymous && (
        <p className="text-xs text-gray-500 mb-2">
          Names stay hidden for good — this cannot be changed once the poll is posted.
        </p>
      )}

      {error && <p className="text-sm text-red-700 mb-2" role="alert">{error}</p>}

      <Button fullWidth onClick={submit}>
        Post poll
      </Button>
    </div>
  );
};

export default PollComposer;
