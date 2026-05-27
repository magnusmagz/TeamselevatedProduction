-- Migration 040: deleted_at audit timestamp on athletes
-- Applied manually to Neon 2026-05-27.
--
-- Soft-delete remains driven by athletes.active_status (the existing, load-bearing
-- mechanism). deleted_at is an AUDIT companion that records WHEN an athlete was
-- soft-deleted — it does not change query logic.
--
-- Behavior: the athlete delete endpoint sets active_status=false + deleted_at=NOW();
-- re-registration that matches a soft-deleted athlete reactivates it
-- (active_status=true, deleted_at=NULL) rather than creating a duplicate.

ALTER TABLE athletes ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP;
