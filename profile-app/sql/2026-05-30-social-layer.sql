-- profile-app — SOCIAL LAYER schema (connections + messaging).
--
-- ⚠️ STUB — NOT YET APPLIED. Joins the spine as a dev-FINAL migration target,
-- reviewed before any crib runs. CUT-DAY-REQUIRED (P-list blocker, on the
-- critical path with the spine). Plan: docs/plan-profile-2.0-social-layer.md.
-- Do not run until Ian approves.
--
-- Keyed on looth_id (= users.uuid) so the graph is queryable next to the
-- directory. Build-thin in-house on postgres; async, NOT realtime. History is a
-- migration target — bp_* provenance columns make the import idempotent.

BEGIN;

-- ---------- connections (friends / follow / requests / blocks) ----------
CREATE TABLE connections (
    id              bigserial PRIMARY KEY,
    requester_uuid  uuid NOT NULL REFERENCES users(uuid),   -- "a" (initiator)
    addressee_uuid  uuid NOT NULL REFERENCES users(uuid),   -- "b"
    status          text NOT NULL CHECK (status IN ('pending','accepted','blocked')),
    type            text NOT NULL DEFAULT 'friend' CHECK (type IN ('friend','follow')),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (requester_uuid <> addressee_uuid),
    UNIQUE (requester_uuid, addressee_uuid, type)
);
-- friend = symmetric (ONE row; query both directions). follow = directional.
CREATE INDEX idx_connections_addressee ON connections (addressee_uuid, status);
CREATE INDEX idx_connections_requester ON connections (requester_uuid, status);
CREATE INDEX idx_connections_pending   ON connections (addressee_uuid) WHERE status = 'pending';

CREATE TRIGGER connections_touch BEFORE UPDATE ON connections
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------- messaging (thin, async) ----------
CREATE TABLE message_threads (
    id              bigserial PRIMARY KEY,
    uuid            uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    subject         text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    last_message_at timestamptz NOT NULL DEFAULT now(),
    bp_thread_id    bigint UNIQUE          -- provenance for idempotent re-import
);

CREATE TABLE messages (
    id            bigserial PRIMARY KEY,
    thread_id     bigint NOT NULL REFERENCES message_threads(id) ON DELETE CASCADE,
    sender_uuid   uuid NOT NULL REFERENCES users(uuid),
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    bp_message_id bigint UNIQUE            -- provenance for idempotent re-import
);
CREATE INDEX idx_messages_thread ON messages (thread_id, created_at);

CREATE TABLE message_recipients (
    thread_id    bigint NOT NULL REFERENCES message_threads(id) ON DELETE CASCADE,
    user_uuid    uuid NOT NULL REFERENCES users(uuid),
    unread_count integer NOT NULL DEFAULT 0,
    is_deleted   boolean NOT NULL DEFAULT false,   -- per-user soft delete
    last_read_at timestamptz,
    PRIMARY KEY (thread_id, user_uuid)
);
CREATE INDEX idx_recipients_user ON message_recipients (user_uuid) WHERE is_deleted = false;

COMMIT;
