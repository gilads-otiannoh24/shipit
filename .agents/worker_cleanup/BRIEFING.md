# BRIEFING — 2026-06-05T14:48:09+03:00

## Mission
Identify and terminate hung PHP development servers and PHPUnit processes.

## 🔒 My Identity
- Archetype: worker
- Roles: implementer, qa, specialist
- Working directory: /home/ian/Desktop/Packages/shipit/.agents/worker_cleanup
- Original parent: d19656b4-04fb-40aa-877d-aae44dcc635b
- Milestone: process cleanup

## 🔒 Key Constraints
- CODE_ONLY network mode. No external network requests.
- Only write within worker_cleanup agent directory.
- Report all findings and actions to the parent agent.

## Current Parent
- Conversation ID: d19656b4-04fb-40aa-877d-aae44dcc635b
- Updated: not yet

## Task Summary
- **What to build**: Process monitoring and termination of hung PHP/PHPUnit processes.
- **Success criteria**: All hung PHP/PHPUnit processes identified and terminated. Reports provided.
- **Interface contracts**: N/A
- **Code layout**: N/A

## Key Decisions Made
- Use bash commands via `run_command` to list processes, identify matches, and terminate them.

## Artifact Index
- /home/ian/Desktop/Packages/shipit/.agents/worker_cleanup/ORIGINAL_REQUEST.md — Original User Request Verbatim

## Change Tracker
- **Files modified**: None
- **Build status**: N/A
- **Pending issues**: None

## Quality Status
- **Build/test result**: N/A
- **Lint status**: N/A
- **Tests added/modified**: None

## Loaded Skills
- None
