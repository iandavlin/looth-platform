<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;
use Throwable;

/**
 * Identity provisioning + reconciliation — the profile-app side of the
 * user-lifecycle CREATE / email-change contract (USER-LIFECYCLE-AUDIT.md
 * gaps G4 + G7; briefing-login-identity.md).
 *
 * Two operations, both keyed on **wp_user_id** (the stable WP account id),
 * both fully idempotent so the poller's blocking provision() can retry them
 * until they stick:
 *
 *   ensure()           — create-or-heal: a new WP user always ends up with a
 *                        users row + a wp_user_bridge row (+ email alias).
 *                        Safe to call repeatedly; self-heals a recycled
 *                        wp_user_id (WP reuses ids after a delete) by moving
 *                        the unique bridge to the current identity.
 *
 *   applyEmailChange() — on a WP email change, KEEP users.uuid stable (never
 *                        re-key identity off email — that is the G4 silent-
 *                        logout bug). Update primary_email + add the new email
 *                        as an alias; the stored uuid the JWT carries is
 *                        untouched, so the member stays authed as the same
 *                        identity. Falls back to ensure() if somehow unbridged.
 *
 * uuid is UUIDv5(namespace, normalized-email) ONLY at first create — it is the
 * seed, never recomputed thereafter. The stored users.uuid is the identity.
 */
final class Provision
{
    /**
     * Idempotent create-or-heal for a WP user. Returns
     * ['user_id'=>int, 'uuid'=>string, 'created'=>bool].
     *
     * avatar_url is INTENTIONALLY left NULL on create: NULL renders as the
     * member's initials (Block.php), which is the canonical no-avatar state.
     * Do not default it to a Gravatar/placeholder URL here — that was the
     * avatar-rot bug (1,300+ rows stamped with fake gravatars the BB backfill
     * then had to repair). Real avatars arrive only via me-avatar.php upload
     * or the BB-upload crib (bin/backfill-avatars.php, NULL-only).
     */
    public static function ensure(int $wpUserId, string $email, ?string $displayName): array
    {
        if ($wpUserId < 1) {
            throw new \InvalidArgumentException('ensure: wp_user_id required');
        }
        $normalized = Identity::normalizeEmail($email);
        if ($normalized === '') {
            throw new \InvalidArgumentException('ensure: email required');
        }
        $uuid = Identity::computeUuid($email);

        $pg = Db::pg();
        $pg->beginTransaction();
        try {
            // Identity row, keyed on the stable uuid seed. Re-create is a no-op
            // beyond filling in a missing display_name.
            $stmt = $pg->prepare('
                INSERT INTO users (uuid, primary_email, billing_email, contact_email, display_name)
                VALUES (:uuid, :email, :email, :email, :name)
                ON CONFLICT (uuid) DO UPDATE
                    SET display_name = COALESCE(users.display_name, EXCLUDED.display_name)
                RETURNING id, (xmax = 0) AS inserted
            ');
            $stmt->execute([':uuid' => $uuid, ':email' => $normalized, ':name' => $displayName]);
            $row      = $stmt->fetch();
            $userId   = (int) $row['id'];
            $inserted = (bool) $row['inserted'];

            // Self-heal a recycled wp_user_id: the bridge's wp_user_id is UNIQUE,
            // so if it currently points at a DIFFERENT (stale) identity, free it
            // before we (re)bind it to this one. WP ids are unique among live
            // accounts, so a collision means the other row is stale.
            $pg->prepare('DELETE FROM wp_user_bridge WHERE wp_user_id = :wp AND user_id <> :uid')
               ->execute([':wp' => $wpUserId, ':uid' => $userId]);

            $pg->prepare('
                INSERT INTO wp_user_bridge (user_id, wp_user_id)
                VALUES (:uid, :wp)
                ON CONFLICT (user_id) DO UPDATE
                    SET wp_user_id = EXCLUDED.wp_user_id, synced_at = now()
            ')->execute([':uid' => $userId, ':wp' => $wpUserId]);

            $pg->prepare('
                INSERT INTO email_aliases (email_normalized, user_id, source)
                VALUES (:e, :u, :s)
                ON CONFLICT (email_normalized) DO NOTHING
            ')->execute([':e' => $normalized, ':u' => $userId, ':s' => 'wp']);

            $pg->commit();
        } catch (Throwable $e) {
            $pg->rollBack();
            throw $e;
        }

        return ['user_id' => $userId, 'uuid' => $uuid, 'created' => $inserted];
    }

    /**
     * Reconcile a WP email change WITHOUT re-keying identity. Returns
     * ['user_id'=>int, 'uuid'=>string, 'email_changed'=>bool, 'created'=>bool].
     *
     * `email_changed` = we updated an existing bridged identity in place.
     * `created`       = no bridge existed, so we self-healed via ensure()
     *                   (uuid is then seeded from the new email — first-create
     *                   semantics, not a re-key).
     */
    public static function applyEmailChange(int $wpUserId, string $email): array
    {
        if ($wpUserId < 1) {
            throw new \InvalidArgumentException('applyEmailChange: wp_user_id required');
        }
        $normalized = Identity::normalizeEmail($email);
        if ($normalized === '') {
            throw new \InvalidArgumentException('applyEmailChange: email required');
        }

        $pg = Db::pg();
        $stmt = $pg->prepare('
            SELECT u.id, u.uuid
            FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
            WHERE b.wp_user_id = :w
        ');
        $stmt->execute([':w' => $wpUserId]);
        $found = $stmt->fetch();

        if (!$found) {
            // Unbridged — heal by creating. uuid seeds from the new email.
            $res = self::ensure($wpUserId, $email, null);
            return [
                'user_id'       => $res['user_id'],
                'uuid'          => $res['uuid'],
                'email_changed' => false,
                'created'       => $res['created'],
            ];
        }

        $userId = (int) $found['id'];
        $uuid   = strtolower((string) $found['uuid']);   // STABLE — never reassigned

        // primary_email is UNIQUE + NOT NULL: if another (stale) row already
        // holds this email we can't move it here. Identity (uuid) is what
        // matters — keep our primary_email as-is, still record the alias, and
        // flag the conflict for the coordinator rather than failing the change.
        $owner = $pg->prepare('SELECT id FROM users WHERE primary_email = :e');
        $owner->execute([':e' => $normalized]);
        $emailOwner = $owner->fetchColumn();
        $emailTaken = ($emailOwner !== false && (int) $emailOwner !== $userId);

        $pg->beginTransaction();
        try {
            if (!$emailTaken) {
                $pg->prepare('UPDATE users SET primary_email = :e WHERE id = :uid')
                   ->execute([':e' => $normalized, ':uid' => $userId]);
            }

            // Add the new email as an alias (keep the old alias as history).
            // Re-point the alias to us if it lingered on a stale identity.
            $pg->prepare('
                INSERT INTO email_aliases (email_normalized, user_id, source)
                VALUES (:e, :u, :s)
                ON CONFLICT (email_normalized) DO UPDATE SET user_id = EXCLUDED.user_id
            ')->execute([':e' => $normalized, ':u' => $userId, ':s' => 'wp']);

            $pg->commit();
        } catch (Throwable $e) {
            $pg->rollBack();
            throw $e;
        }

        if ($emailTaken) {
            error_log("[provision] email-change conflict: '$normalized' held by user_id=$emailOwner, "
                . "kept primary_email on live user_id=$userId (uuid stable); alias re-pointed");
        }

        return [
            'user_id'        => $userId,
            'uuid'           => $uuid,
            'email_changed'  => true,
            'created'        => false,
            'email_conflict' => $emailTaken,
        ];
    }
}
