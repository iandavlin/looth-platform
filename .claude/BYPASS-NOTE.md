# Bypass-permissions note

**Set:** 2026-05-24
**Scope:** project-local (this file's `.claude/settings.json`), NOT user-global
**Why:** initial phase of the archive POC — see [docs/archive-poc-plan.md](../docs/archive-poc-plan.md)

`settings.json` has `permissions.defaultMode = "bypassPermissions"`. This auto-approves all tool calls in this project's Claude sessions.

**When archive POC initial phase ends, revert by:**

```bash
jq 'del(.permissions.defaultMode)' /home/ubuntu/projects/.claude/settings.json \
  > /tmp/settings.json && mv /tmp/settings.json /home/ubuntu/projects/.claude/settings.json
rm /home/ubuntu/projects/.claude/BYPASS-NOTE.md
```

Note: even in bypassPermissions, Claude still won't auto-approve writes to `.git`, `.vscode`, `.idea`, or most of `.claude/` — those still prompt by design.
