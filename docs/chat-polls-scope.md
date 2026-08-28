# Polls in chat — scope

Drafted 2026-08-28 for Maggie's review. **Nothing built.** Read this instead of
re-deriving it.

Related: `chat-notifications-scope.md`, `chat-moderation-plan.md`, `chat-archive-plan.md`.

---

## 1. What this is for

**Choosing between options.** "Team dinner — Friday at 7 or Thursday at 6:30?"
Today that is a thread of replies nobody can total, and the coach counts by hand
and gets it wrong.

⚠️ **"Who is bringing oranges" is NOT a poll** (Maggie, 2026-08-28). That is
assignment — a signup sheet where each answer is a commitment to do something,
and where the useful view is a list of names against tasks. Building polls to
serve it produces a bad poll and a worse signup sheet. If signups are wanted they
are a separate feature with a separate shape.

So: a fixed set of options, people pick, the group sees the result. **Not a
survey tool, not a form.** If it grows a text answer, a file upload or branching,
it has become the registration form and belongs there instead.

---

## 2. Decisions — SETTLED with Maggie 2026-08-28

Answers below are hers. The pattern in them is worth naming: **almost everything
became a per-poll choice at creation rather than a product-wide rule.** That is a
better answer than the one this document originally proposed, and it is what
shapes the schema — each of these is a column on the poll, not a constant.

### 2.1 Named or anonymous — **per poll, chosen at creation**

An **Make anonymous** toggle in the composer. Not a global rule.

This removes the argument entirely: a date-choice poll can show who picked what,
and anything opinion-shaped can be created anonymous. The creator decides with
the specific poll in front of them, which is the only place the answer is
actually knowable.

⚠️ **Anonymity is fixed once created and MUST NOT be editable.** Flipping an
anonymous poll to named exposes votes cast under a promise; flipping the other
way discards what people expected to see. The toggle is disabled the moment the
first vote lands — or simpler and safer, from creation.

⚠️ **Anonymous must mean anonymous in the DATABASE too**, not just hidden on
screen. A vote row still has a `user_id` (needed for "change your vote" and for
the uniqueness constraint), so the protection is that nothing ever selects it for
an anonymous poll. That is a discipline, and it needs a test rather than a
convention.

### 2.2 Who can create one — **coaches and club admins**

Not parents, not players.

⚠️ Needs its **own** predicate. `canModerate()` in
`chat-server/lib/moderation.js` is `['super_admin','owner','club_admin','admin']`
— **coach is not in it**. `canInitiateConversation()` includes coach but also
`parent`. Neither list is right, and reusing one because it is close is the
"which predicate got called" mistake CLAUDE.md records four times over. Write
`canCreatePoll()`.

### 2.3 Changing a vote — **allowed**

Until the poll closes.

### 2.4 Results before voting — **per poll, chosen at creation**

A second toggle in the composer, alongside anonymity. Hiding results stops early
answers anchoring later ones, which matters for some polls and not others — so
again the creator decides, not us.

Unlike anonymity this one **is** safe to edit after the fact: revealing results
later exposes nothing that was promised private, since the votes themselves are
already whatever the anonymity setting says.

### 2.5 Poll type — **multiple choice AND yes/no, chosen at creation**

Two shapes offered in the composer:

- **Choice** — two or more options, pick one.
- **Yes / No** — the same thing with the options supplied for you. Not a separate
  mechanism: it is a Choice poll seeded with two options, so it costs no extra
  schema and no extra rendering path. Offering it as its own button is a
  composer-level convenience, and worth it — "Friday at 7?" is a common enough
  poll that making people type Yes and No is friction for nothing.

Selecting more than one option is deliberately **not** in this list — see 2.6.

### 2.6 Selecting several options — **schema ready, UI not offered yet**

`allow_multiple` exists on the poll and the uniqueness constraint is on
(option, user) so it supports several selections; the composer simply does not
offer it at launch. One column now avoids a migration later.

### 2.7 Closing — **a close date set at creation, editable afterwards**

Evaluated at read time, never by a background job flipping a status column —
that is a second thing to break, and a closed-ness that depends on a worker
having run is wrong whenever the worker is behind. `closes_at` is data; "is it
closed" is `NOW() > closes_at`.

⚠️ **Editable, which means it can be moved EARLIER as well as later.** Shortening
a deadline past votes already cast must not discard them: the close date gates
new votes, and never removes existing ones. Extending is unremarkable; shortening
is the case to test.

## 3. What exists that this builds on

- **`chat_reactions`** is a per-message, per-user table with `addReaction` /
  `removeReaction` socket events, and a vote is the same shape — so the pattern
  is worth following.

  ⚠️ **But reactions were never finished, and never worked.** Investigated
  2026-08-28 after Maggie asked whether they had been lost in an update. They had
  not: the table exists, the server handlers exist, `chatSocket.addReaction` /
  `removeReaction` exist — and **nothing calls them**. There is no listener for
  `reactionAdded`, no UI anywhere, and the server never sends a message's
  existing reactions when a conversation loads. **0 rows across 366 messages** in
  production. Nobody could ever have used it.

  So treat that code as a *sketch of the pattern*, not as working precedent, and
  do not assume the parts it is missing are somewhere else. Finishing reactions
  is a separate small piece of work (listener, loading, UI) that polls do not
  depend on.
- **`removeMessage`** is a working moderation action with a role gate, club
  confinement for club admins, and idempotency. The poll's own gate mirrors it.
- **Notifications** ride along for free: a poll IS a `chat_message`, so the
  dispatcher already covers it.

## 4. What does not exist

- ⚠️ **`chat_messages` has NO type column.** Every message is text, and
  `ChatMessageList` renders `{msg.text}` with no branching. The client
  `ChatMessage` interface has no type either. Adding a discriminator and a render
  branch is the bulk of the frontend work — and the first thing that makes chat
  extensible for anything after this.
- No poll tables, no vote storage, no composer.

---

## 5. Schema — migration 079

```
chat_polls
  id, message_id -> chat_messages(id) ON DELETE CASCADE
  question
  is_anonymous        bool  -- fixed at creation, never editable
  results_before_vote bool  -- editable
  allow_multiple      bool  -- schema-ready, no UI yet
  closes_at           timestamptz null  -- editable, may move earlier
  created_by -> users(id), created_at

chat_poll_options
  id, poll_id -> chat_polls(id) ON DELETE CASCADE
  label, sort_order

chat_poll_votes
  id, option_id -> chat_poll_options(id) ON DELETE CASCADE
  user_id -> users(id) ON DELETE CASCADE, created_at
  UNIQUE (option_id, user_id)
```

Plus `chat_messages.message_type` defaulting to `'text'`, CHECK-constrained to
`('text','poll')`. A default rather than a backfill: 300+ existing rows are all
text, and a default means no migration of live data.

**The uniqueness constraint is the correctness boundary, not the app.** Two taps
on a slow connection is the ordinary case, not the edge case, and a vote counted
twice is the one bug that makes the whole feature untrustworthy. Enforce it in
the database and let the insert conflict.

**Results are computed, never counted incrementally.** No `vote_count` column to
drift out of step with the rows.

---

## 6. Two things that will break if we forget them

### 6.1 Removing a poll must remove its votes

`removeMessage` tombstones a message. A poll is a message, so a removed poll
would otherwise leave a tombstone with a live vote attached and results still
totalling. `ON DELETE CASCADE` covers a hard delete; the tombstone path is a soft
delete, so the poll must **also** be hidden when `chat_messages.deleted_at` is
set. Test it.

### 6.2 Retention has a foreign-key trap, and it has bitten before

`lib/retention_plans.php` documents that a naive `DELETE FROM chat_messages`
raises SQLSTATE 23503 because `chat_read_receipts` holds a NO ACTION foreign key
onto it — verified against live Neon, not inferred. Poll tables add three more
inbound references. Every one must cascade or be cleared in the plan's `before`
list, or the first real purge fails on the whole run.

---

## 7. Build order

1. **Schema + the message type discriminator.** Nothing user-visible. This is
   also what makes any future non-text message possible, so it is worth doing
   properly rather than special-casing polls.
2. **Server: create, vote, close.** Reuses the reaction pattern for votes and the
   moderation pattern for the create gate. Broadcasts so a vote updates for
   everyone watching, the way reactions already do.
3. **Client: render and vote.** The render branch, the poll itself, results, and
   your own vote state.
4. **Composer.** Deliberately last: it is the easiest piece and the one most
   likely to need reworking once the poll is visible on a real phone.
5. **Notification wording.** "Cora Coach posted a poll" rather than "1 new
   message". Small, and it is the difference between a notification worth opening
   and one that is not.

**Estimate: three days.** Up from two-to-three now that anonymity, results-visibility, poll type and an editable close date are all per-poll settings — the composer carries real choices rather than a question and some options.

---

## 8. Deliberately not in scope

- **Editing a poll after votes exist.** Changing the question under people who
  have answered makes the result meaningless. Close it and post a new one.
- **Anonymous polls**, per 2.1. Revisit only with a real case.
- **Text answers, file uploads, branching.** That is the registration form.
- **Polls in DMs.** Team and group only at launch; a two-person poll is a
  question.
- **Reminders to non-voters.** Plausible later, and it is a nag feature — it
  needs its own thought about who can send one and how often.

---

## 9. Testing that matters

- **A double vote is refused by the database**, not merely by the UI.
- **Changing a vote replaces it**, leaving exactly one.
- **A closed poll refuses votes**, evaluated against `closes_at` at read time —
  including a vote arriving one second after close.
- **A removed poll stops totalling** and shows a tombstone.
- **A coach can create one; a parent cannot** — the predicate, not the button.
- **Results match the rows** after a burst of concurrent votes.
- **An anonymous poll never returns a voter identity** from any endpoint — not
  merely hidden in the UI. This is the one where a convention is not enough.
- **Anonymity cannot be changed** after creation.
- **Shortening a close date does not discard votes already cast**, only stops new
  ones.
- **A Yes/No poll is a Choice poll underneath** — same storage, same rendering,
  so it cannot drift into a second code path.
