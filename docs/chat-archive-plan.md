# Chat: archive, moderation removal, retention

Branch `feature/chat-archive`, worktree `/Users/maggiemae_1/TeamsElevated/te-chat-archive/`.

## Decision

**There is no user-facing delete.** Coaches and parents get **archive** only. The one removal path
is an admin **moderation removal**, which tombstones rather than erases and writes `audit_log`.

A "delete" button that soft-deletes is a lie to the person clicking it — they believe the message
is gone and it is not. In a product carrying minors' communications, that gap between the label and
the database is the actual liability, worse than offering nothing. Archive promises exactly what it
does.

**COPPA is not the reason to retain.** COPPA pushes the other way: retain children's personal data
only as long as reasonably necessary, honor parental deletion requests, no indefinite retention.
What argues for keeping chat is **child-safety recordkeeping** — SafeSport-style expectations that
adult↔minor communication in a youth org stays transparent and preserved, plus club defensibility
in a dispute. COPPA then imposes the *ceiling*, which is what Phase 3 is for. Do not let a future
reader restate this as "we keep chat because COPPA."

## What already exists (verified 2026-07-30)

The read side is built and dead; only writes are missing.

| Thing | State |
|---|---|
| `chat_messages.deleted_at` | Column exists; all 7 read queries filter `deleted_at IS NULL`; **nothing writes it**. No PHP touches `chat_messages` at all. |
| `conversation_participants.left_at` | Honored by 6 queries; **nothing writes it**. |
| `conversations` archive flag | Does not exist. |
| Socket events | authenticate, loadConversations, joinConversation, leaveConversation, createConversation, sendMessage, getTeamMembers, typing, addReaction, removeReaction, markRead, disconnect. **No delete or archive.** |
| Frontend affordance | None — no menu, no swipe, no delete button. |

**Do not wire archive to `left_at`.** Its six read-side uses make it behave like Messenger's *leave
group*: you vanish from every other participant's roster. Leave and archive are different verbs.

### The fact that shapes the whole design

`ensureTeamConversation` (`chat-server/server.js:152`) creates a team conversation and **no
participant rows**. Team members reach it through the second branch of `getUserConversations`:

```sql
OR (c.type = 'team' AND c.team_id = ANY($2::int[]))
```

So for a team chat, most users have **no `conversation_participants` row at all**. Any per-user
state — including `archived_at` — therefore needs an **upsert**, not an update.

This also means `markRead` (`server.js:920`) is currently a **no-op on team chats**: it is a bare
`UPDATE ... WHERE conversation_id AND user_id` against a row that does not exist, so unread badges
on team conversations never clear. Pre-existing, not caused by this work, fixed here because the
archive upsert makes it inconsistent to leave alone.

---

## Phase 1 — Archive

### Migration 058

`058_chat_conversation_archive.sql`:

```sql
ALTER TABLE conversation_participants ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP;
CREATE INDEX IF NOT EXISTS idx_conv_participants_archived
  ON conversation_participants(user_id, archived_at);
```

057 is claimed by the `broadcast_campaigns.body` column blocking scheduled SMS — 058 is next free
across all five checkouts (highest existing is 056).

After applying to Neon, **add `archived_at` to `conversation_participants` in
`tests/fixtures/production-schema.json`** or the snapshot drifts from live.

### Server (`chat-server/server.js`)

1. `getUserConversations` — filter archived out. The join is already
   `LEFT JOIN conversation_participants cp ON cp.conversation_id = c.id AND cp.user_id = $1`, so
   `cp.archived_at IS NULL` reads correctly in both branches (no row ⇒ not archived):

   ```sql
   WHERE ( (cp.user_id IS NOT NULL AND cp.left_at IS NULL)
        OR (c.type = 'team' AND c.team_id = ANY($2::int[])) )
     AND cp.archived_at IS NULL
   ```

   It must sit outside the `OR` group — otherwise the team branch re-admits an archived team chat.

2. New `archiveConversation` / `unarchiveConversation` socket events. Upsert, because of the team
   case above:

   ```sql
   INSERT INTO conversation_participants (conversation_id, user_id, role, display_name, archived_at)
   VALUES ($1, $2, 'member', $3, NOW())
   ON CONFLICT (conversation_id, user_id) DO UPDATE SET archived_at = EXCLUDED.archived_at
   ```

   Both gated on `isConversationParticipant` — you cannot archive a chat you cannot see.

3. New `loadArchivedConversations` — same query with `cp.archived_at IS NOT NULL`.

4. `sendMessage` — auto-unarchive for **everyone** who had archived it, then tell them:

   ```sql
   UPDATE conversation_participants SET archived_at = NULL
   WHERE conversation_id = $1 AND archived_at IS NOT NULL
   RETURNING user_id
   ```

   The existing `conversationUpdated` broadcast is not enough: an archived conversation is absent
   from the client's list, so `handleConversationUpdated` maps over it and finds nothing. Emit
   `newConversation` to the returned users' sockets — that handler already dedupes by id.

5. `markRead` — same upsert shape, so team-chat unread badges clear.

### Frontend

- `types.ts` — `archivedAt?: string | null` on `Conversation`.
- `chatSocket.ts` — `archiveConversation`, `unarchiveConversation`, `loadArchivedConversations`.
- `useChat.ts` — `archivedConversations`, `showArchived`, `archiveConversation`,
  `unarchiveConversation`. Archiving removes from local list immediately (optimistic).
- `ConversationList.tsx` — per-row Archive action, plus an "Archived" toggle at the top.
  **Note:** each row is currently a `<button>`; a nested button is invalid HTML. Restructure the
  row into a `<div>` holding the existing main `<button>` plus a sibling menu button.

### Tests

The chat server has **no test framework and no devDependencies**. Use built-in `node:test`
(`engines: node >=18`) so this adds zero dependencies.

`chat-server/__tests__/archive.test.js`:
- conversation-list SQL filters `cp.archived_at IS NULL`, and that clause is outside the `OR` group
  (regression guard for the team-branch leak)
- archive upserts rather than updates — asserts `ON CONFLICT` present (the team-chat case)
- unarchive clears to NULL
- `sendMessage`'s unarchive targets all participants and returns `user_id`

`frontend/src/components/chat/__tests__/useChat.test.ts` (harness already exists — this is where
the real behavioral coverage goes):
- archiving drops the conversation from `conversations`
- unarchiving restores it
- an incoming message on an archived conversation brings it back (`newConversation` path)
- archiving the active conversation clears the active pane

---

## Phase 2 — Moderation removal (admin only)

Writes the `chat_messages.deleted_at` that already exists and is already filtered.

- Socket `removeMessage` — **club_admin only**, never the sender, never a coach. Explicitly not
  time-boxed: this is moderation, not unsend.
- Tombstone: the message row survives with `deleted_at` set; clients render "Message removed by an
  administrator" in place. Read queries currently drop the row entirely — the tombstone needs the
  list query to return removed rows with text nulled instead of filtering them out.
- Every removal writes `audit_log` via `lib/AuditLogger.php` (actor, message id, conversation,
  original text). A removal nobody can reconstruct is indistinguishable from data loss.

Tests: non-admin is refused; row is soft-deleted not hard-deleted; audit row written with the
original text; tombstone renders and cannot be read back through any list query.

---

## Phase 3 — Retention ✅ BUILT 2026-07-30 (before Phase 2, deliberately)

Built ahead of Phase 2 because it carries no policy questions, and because the gap it closes exists
today regardless of whether moderation removal ever ships. Two deviations from what is specced
below, both deliberate:

1. **`chat_messages_removed` ships `auto_delete = FALSE`, not TRUE.** All five pre-existing policies
   are FALSE, and migration 051 states the convention outright: "flag for review, don't silently
   destroy." With no scheduler running, `auto_delete` only decides whether a manual `--purge` may
   act, so nothing is lost by leaving it unarmed. Flipping it is a one-line UPDATE.
2. **Both plans needed a `before` step that this spec did not anticipate.**
   `chat_read_receipts.last_read_message_id` is a NO ACTION FK onto `chat_messages`; a naive purge
   raises 23503. `retentionPlans()` entries may now carry a `before` list run in the same
   transaction, and the rules moved to `lib/retention_plans.php` to be testable at all.



`scripts/retention-check.php` has plans for `athlete_medical`, `medical_records`,
`consent_records`, `audit_logs` — **nothing for chat**. Without this, Phase 2 creates a retention
obligation with no mechanism to discharge it.

Two policy rows, deliberately asymmetric:

| `data_type` | days | `auto_delete` | Effect |
|---|---|---|---|
| `chat_messages_removed` | 90 | **TRUE** | Hard-deletes rows already moderation-removed 90+ days ago. Invisible to everyone already, so retaining them is pure risk with no product value. The 90 days is the window to reverse a bad moderation call. |
| `chat_messages` | 1095 | **FALSE** | Reports only. Never deletes until Maggie sets a period and flips the flag. |

The second row is deliberately inert. Picking a retention period for children's communications is a
policy decision, not one to guess at in code — but the obligation should be *visible* in the
retention report rather than silently absent. The script already reports by default and requires
both `--purge` and `auto_delete = TRUE` to remove anything.

Tests (`tests/php/ChatRetentionPlanTest.php`): both plans registered; SQL targets `chat_messages`;
the `_removed` plan is constrained to `deleted_at IS NOT NULL` (never touches live messages); the
open-ended plan ships with `auto_delete = FALSE`.

---

## Order

Phase 1 end-to-end first, including the migration applied to Neon and the schema snapshot updated.
Phases 2 and 3 land together — the moderation path and the rule that ages its output out should not
ship apart.

Deploy: frontend (`git push origin main`) before the chat server. The chat server is its own Heroku
app — remote `chat` → `teamselevated-chat.git`, **not** the `heroku` backend remote.
