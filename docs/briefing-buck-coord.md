# Briefing — Buck merge-coordinator (profile-app + member-map lanes)

**Paste this into a fresh chat to bootstrap a dedicated coordinator for Buck's branch reviews/merges.** Keep this chat narrow: only Buck's lanes. Everything else (DB, cutover, nginx, lg-shell, archive-poc, bb-mirror, events) belongs to the main coordinator — ping it, don't cross.

## Your job
Review and merge Buck's branches to canonical, and report back to Buck. That's the whole loop. Stay small — do NOT pull in DB/cutover/architecture context; this lane is just merges.

- **Canonical repo (you merge here):** `/home/ubuntu/projects` (branch `main`)
- **Buck's source repo (his branches `buck/*`):** `/home/buck/looth-platform`
- **Standing rule:** everything stays **local on `main`** — *nothing is pushed*. Present commits + diffstat for joint review before any push (there is none right now).
- **Comms:** you are `ubuntu`, in the devmsg group → `msg send buck "..."`. Visual QA: the `chrome-dev-login` skill.

## The merge loop (every Buck branch)
1. `git remote add bucktmp /home/buck/looth-platform` (sudo `chmod -R a+rx /home/buck/looth-platform/.git` if fetch is denied), then `git fetch bucktmp <branch>`.
2. `git log --oneline main..bucktmp/<branch>` and `git merge-base main bucktmp/<branch>` — see what's new and what base it sits on.
3. **Review the diff:** `php -l` both files, confirm scope matches Buck's description, check the real risks (escaping, infinite handlers, owner-gating, no dead markup).
4. **Land it:** if merge-base == current HEAD → `git merge --ff-only` (linear, preserves authorship). Otherwise `git cherry-pick -x <sha>` and resolve conflicts **by union**.
5. Verify: no conflict markers, `php -l` clean.
6. `git remote remove bucktmp`, then `msg send buck` a tight report (what landed, the SHAs, what you checked).

## ⚠ The one trap that WILL bite you: lineage divergence
Buck builds on `buck/preview-all-phases`, which merged his phase branches at SHAs that **diverge from how the same work landed on canonical** (canonical's `bk/gallery`, `bk/ffblock`, `bk/ffcaddy`, etc.). So **a commit's own diff is NOT self-contained** — it can reference markup/handlers/CSS that exist on his base but NOT on canonical.

**Rule:** don't trust the delta commit. Diff the branch **TIP** against canonical for the touched feature area, and verify any markup the new code references (handlers, buttons, CSS classes) actually exists on canonical. This already caused one real regression (freeform delete) that had to be hand-fixed.

(Memory: `project_profile_app_buck_lineage_divergence`.)

## Decisions already settled — do NOT re-litigate
- **Freeform delete = in-block `.lg-freeform__rm` ✕** (canonical commit `67b83a0`). The caddy-trash (`data-freeform-del`) model is **retired**; its dead CSS was swept in `91ba6d3`. If a Buck branch reintroduces caddy-trash rows, that's the stale model — keep canonical's in-block ✕.
- Buck's old `buck/profile-builder-reskin` and `-reskin-v2` are **dropped/stale** — ignore them. The reskin already landed as `8b71d87`.

## Canonical state at handoff (2026-06-03)
HEAD ≈ `6ada453`. Recent profile-app merges: reskin `8b71d87`, freeform-delete model `67b83a0`, dead-CSS sweep `91ba6d3`, caddy-toggle-label `8dea267`+`6ada453`.

## Comms format (symmetric relay)
Report back to Buck per `feedback_chat_report_back_format`; relay packets per `feedback_relay_link_format`. Keep `feedback_review_commits_before_push` in mind. Route anything cross-cutting (shared files, contract changes, out-of-lane blockers) to the main coordinator via Ian — don't resolve it here.
