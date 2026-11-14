# Legacy Vault — Engineering Handbook

This folder is the source of truth for Legacy Vault. Read `../AI_RULES.md` first, always —
every AI session (Claude Desktop or Claude in an IDE) starts there.

## Contents

| Doc | Covers |
|---|---|
| [00_Project_Vision.md](00_Project_Vision.md) | What this is, who it's for, the core zero-access-vs-zero-knowledge decision |
| [01_Product_Requirements.md](01_Product_Requirements.md) | Full requirements by feature area, priority, phase |
| [02_User_Flows.md](02_User_Flows.md) | Every flow, step by step, including edge cases |
| [03_UI_UX_Guidelines.md](03_UI_UX_Guidelines.md) | Design system, RTL/Arabic, accessibility, tone |
| [04_System_Architecture.md](04_System_Architecture.md) | Component map, shared-hosting constraints, data flow |
| [05_Security_Architecture.md](05_Security_Architecture.md) | Auth, sessions, web-layer defenses, OWASP mapping, checklist |
| [06_Cryptography.md](06_Cryptography.md) | Key hierarchy, Argon2id/AES-GCM parameters, the zero-access mechanism in full |
| [07_Database_Design.md](07_Database_Design.md) | Every table, column, index, FK, deletion strategy |
| [08_API_Specification.md](08_API_Specification.md) | Endpoint index + fully specified representative endpoints |
| [09_Backend_Architecture.md](09_Backend_Architecture.md) | Laravel structure, DTOs, policies, shared-hosting queue strategy |
| [10_Frontend_Architecture.md](10_Frontend_Architecture.md) | React structure, state, crypto module contract, i18n |
| [11_CheckIn_Engine.md](11_CheckIn_Engine.md) | Dead man's switch state machine, schedules, edge cases |
| [12_Notification_System.md](12_Notification_System.md) | Push/email/SMS architecture, templates, retries |
| [13_Delivery_Engine.md](13_Delivery_Engine.md) | Trigger execution, recipient access, undo, audit |
| [14_Deployment.md](14_Deployment.md) | Hostinger shared-hosting deployment, cron, backups, key rotation |
| [15_Testing.md](15_Testing.md) | Test strategy across every layer, including fixed crypto test vectors |
| [16_Coding_Standards.md](16_Coding_Standards.md) | PHP/TS conventions, DTOs, naming, dependency policy |
| [17_Roadmap.md](17_Roadmap.md) | Phase-by-phase plan with exit criteria |
| [18_Threat_Model.md](18_Threat_Model.md) | Worked attacker scenarios with concrete answers |
| [19_Decision_Log.md](19_Decision_Log.md) | Every major decision with pros/cons/alternatives |

## The one thing to understand before anything else

Legacy Vault is **zero-access, not zero-knowledge.** The server cannot read vault content
during normal operation, but it holds a key-encrypted copy it can unwrap through exactly one
audited code path when a verified delivery trigger fires — because recipients need to receive
readable content automatically, with no one present to unlock anything. This is explained in
full in `00_Project_Vision.md` §4 and `06_Cryptography.md` §3, and it's the thread that runs
through the security, crypto, database, API, and check-in/delivery documents. Get this wrong
in an implementation and the product's core security claim is misrepresented to users.

## How to use this with Claude

**Claude Desktop (architect mode):** planning, architecture, database, security, API design,
UI specification. No coding — this folder is the output.

**Claude in an IDE (implementer mode):** start a session with:

> Read `AI_RULES.md` and the relevant docs in `docs/`. Implement [specific phase/feature]
> exactly as specified. Don't invent new architecture. Follow the coding standards.

Implement one phase at a time (`17_Roadmap.md`), reviewing each phase's exit criteria before
starting the next.

## Keeping this handbook honest

Per `AI_RULES.md` #9: if an implementation detail changes from what a doc says, update the doc
in the same change. This folder should never silently drift from what the code actually does.
