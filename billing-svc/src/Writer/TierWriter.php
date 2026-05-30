<?php

declare(strict_types=1);

namespace LGBilling\Writer;

/**
 * THE SINGLE-WRITER SEAM (design §5b-A — the new keystone is the new target).
 *
 * This interface is the ONE path through which entitlement is granted. Whoever
 * holds a TierWriter can change a member's tier; nothing else may. The whole
 * security posture of the rebuild rests on keeping implementations of THIS
 * interface the sole mutators of tier state:
 *
 *   - Today (step 1): the concrete writer targets WP roles (wp_capabilities).
 *     NOTE: this milestone does NOT write WP from outside WP — see
 *     WpRoleTierWriter, which is a documented stub. The live WP plugin keeps
 *     writing roles untouched.
 *   - Step 2: the concrete writer targets profile-app's member_tier table.
 *     At the DB-grant level (§3i) ONLY the billing-svc role gets INSERT/UPDATE
 *     on member_tier; profile-app's web role gets SELECT only. A profile-app
 *     RCE must not be able to self-grant Pro because it has no writer and no
 *     DB privilege — the seam is enforced in OS users + pg grants, not just code.
 *
 * Contract every implementation MUST honour:
 *   - IDEMPOTENT + REPLAY-SAFE: applying the same TierGrant.eventId twice is a
 *     no-op (§5b-A). Return TierGrantResult::duplicate() on replay.
 *   - AUDITED: every non-replay apply() writes an immutable audit row
 *     (user, old, new, source, event_id, actor, ts) before/with the mutation
 *     (§5b-E), so a fraudulent or buggy grant is detectable and reversible.
 *   - FAIL-CLOSED: a null/floor newTier downgrades to looth1; an error must
 *     never leave the user on a higher tier than the decision warrants (§5b-C).
 */
interface TierWriter
{
    public function apply(TierGrant $grant): TierGrantResult;
}
