-- 071: Case-insensitive lookup indexes for identity-by-email
--
-- Reported 2026-08-18: Emily Govier could sign in but the parent portal told her no
-- athletes were registered to her. Her guardians row carries `Emilygovier0@gmail.com`
-- and her users row `emilygovier0@gmail.com` — one capital letter.
--
-- Parent standing is derived by comparing those two columns, and ten query sites did
-- it with `=`, which is case-SENSITIVE in Postgres. So the guardian chain returned
-- zero rows and she reached an empty portal with a valid `parent` role. Measured
-- against production: `g.email = 'emilygovier0@gmail.com'` -> 0 rows;
-- `lower(g.email) = ...` -> 1. THREE accounts were in this state (users 152, 235, 253).
--
-- Every one of those sites is now LOWER() on both sides. That makes the existing
-- plain index on guardians.email unusable for them, hence these functional indexes.
-- At 426 guardians the seq scan is irrelevant today; the index is here so the fix
-- does not quietly become a performance problem as clubs are added, and so nobody
-- is tempted to "optimise" the LOWER() back out.
--
-- Deliberately NOT normalising the stored data. Lowercasing every guardians.email
-- would fix these three rows and hide the class of bug — the next row typed with a
-- capital would break again anywhere a site was missed. The comparison is the thing
-- that was wrong, so the comparison is what changed. GuardianEmailCaseTest scans for
-- a regression.

CREATE INDEX IF NOT EXISTS guardians_email_lower_idx ON guardians (LOWER(email));
CREATE INDEX IF NOT EXISTS users_email_lower_idx ON users (LOWER(email));
