-- billing-svc — single-writer DB grants (design §5b-A, the enforcement).
--
-- These GRANTs are where the single-writer rule becomes real: NOT applied this
-- milestone (no live DB changes); the coordinator runs them as a superuser when
-- step 1 lands, after schema.sql.
--
-- Model (STRANGLER-COORDINATION.md §3i): each strangler's web pool runs as its
-- own pg role named after its OS user (peer auth over the unix socket). The
-- billing-svc OS user => billing pg role.

-- ── The billing-svc role owns the billing schema ────────────────────────────
-- (Role/login is created by the coordinator with peer auth; hyphenated to match
--  the OS user, so it needs quoting: "billing-svc".)
CREATE ROLE "billing-svc" WITH LOGIN;  -- peer auth; no password (unix socket)

GRANT USAGE, CREATE ON SCHEMA billing TO "billing-svc";

-- Source rows: billing-svc reads + writes its own source map.
GRANT SELECT, INSERT, UPDATE, DELETE ON billing.lg_role_sources TO "billing-svc";

-- Idempotency guard: insert-once, read.
GRANT SELECT, INSERT ON billing.processed_events TO "billing-svc";

-- ── Audit table is APPEND-ONLY, even for billing-svc (§5b-E) ─────────────────
-- INSERT + SELECT only. No UPDATE, no DELETE: history cannot be rewritten by
-- the service itself. A bad grant is corrected by a NEW grant, never by editing
-- the past.
GRANT SELECT, INSERT ON billing.tier_grant_audit TO "billing-svc";
-- (No GRANT of UPDATE/DELETE on tier_grant_audit to anyone but a DBA.)

-- ── What every OTHER role must NOT get (the boundary, stated) ────────────────
-- profile-app's web role ("profile-app") gets NOTHING on the billing schema.
-- It must never read source rows or the audit, and — critically for step 2 —
-- it must never write tier:
--   REVOKE ALL ON SCHEMA billing FROM "profile-app";   -- (implicit; documented)
--
-- STEP-2 grants (planned, NOT here): when member_tier lands in profile_app,
--   GRANT SELECT, INSERT, UPDATE ON profile_app.member_tier TO "billing-svc";
--   GRANT SELECT                  ON profile_app.member_tier TO "profile-app";
-- i.e. billing-svc is the SOLE writer; the fat app can only read. A profile-app
-- RCE then cannot self-grant Pro because it holds no write privilege.
