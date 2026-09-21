# Lavka Project Workflow

Owner instruction, confirmed 2026-09-21:

- After completing and verifying a requested change, commit the task's code and
  documentation and push to the configured remote without asking again, unless
  the user explicitly asks not to commit or push for that task.
- Keep commits scoped to the task. Do not include unrelated or unfinished work,
  secrets, generated local data, or changes from other tasks.
- Preserve remote work. Do not force-push or rewrite shared history. If a push is
  blocked, report the blocker and the local commit hash instead of claiming success.
- Commit/push permission does not authorize production deployment, database
  changes, financial operations, or other production actions.
- Report the commit hash and confirmed push result when finishing.

Project skills and documentation remain under `.agents/skills/` and `docs/`.
