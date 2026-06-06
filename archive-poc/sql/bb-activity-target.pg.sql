-- archive-poc/sql/bb-activity-target.pg.sql
--
-- CROSS-LANE CONTRACT: bp_activity → Hub-card target map.
--
-- The legacy BuddyBoss reactions (wp_bb_user_reactions) hang on bp_activity items
-- (item_type='activity', item_id = wp_bp_activity.id). The discovery reaction store
-- (card_reactions) is keyed by the Hub-card target (post_type, item_id). Resolving
-- one to the other requires knowing how bb-mirror keyed its topic/content cards —
-- that mapping is the **bb-mirror/Hub lane's** to own and populate.
--
-- This staging table is the agreed drop point: bb-mirror INSERTs one row per
-- reactable bp_activity id it can resolve to a card; the ENGINE migration
-- (bin/migrate-bb-reactions.php) SELECTs it to place each migrated reaction on the
-- right card. Activities with no card (pure activity_update / activity_share) simply
-- have no row here → their reactions are reported as unmapped and skipped.
--
-- bb-mirror can derive rows from wp_bp_activity.type:
--   blogs/new_blog_<cpt>  → post_type=<cpt>,   item_id = secondary_item_id (post ID)
--   {groups,bbpress}/bbp_topic_create → post_type='topic', item_id = the topic id
--   bbp_reply_create / activity_update / activity_share → bb-mirror's call (likely no
--     card, or fold replies into their topic) — ENGINE just consumes what lands here.
--
-- Apply as the schema OWNER (archive-poc). search_path = discovery, public.

CREATE TABLE IF NOT EXISTS bb_activity_target (
  activity_id  BIGINT PRIMARY KEY,        -- wp_bp_activity.id the legacy reaction hangs on
  post_type    TEXT   NOT NULL,           -- resolved card target type (CPT slug or 'topic')
  item_id      BIGINT NOT NULL,           -- resolved card target id (= card_reactions.item_id)
  src_type     TEXT,                       -- optional audit: wp_bp_activity.type it came from
  CONSTRAINT bb_activity_target_type_ck
    CHECK (post_type <> '' AND item_id > 0)
);
CREATE INDEX IF NOT EXISTS idx_bb_activity_target_card ON bb_activity_target (post_type, item_id);

-- bb-mirror populates it (the mapping owner); looth-dev (the migration's WP pool) reads it.
GRANT USAGE  ON SCHEMA discovery TO "bb-mirror", "looth-dev";
GRANT SELECT, INSERT, UPDATE, DELETE ON bb_activity_target TO "bb-mirror";
GRANT SELECT ON bb_activity_target TO "looth-dev";
