# Claude notes

Read [AGENTS.md](AGENTS.md) first. It holds the architecture, the rules that must not break, the commands and the checklists. Update that file rather than adding project knowledge here.

Notes specific to Claude Code:

- Claude Code also loads `CLAUDE.local.md` when it exists. It holds this machine's setup and is not committed. Put paths, URLs, containers, accounts and other local details there or in `AGENTS.local.md`, never in this file or `AGENTS.md`.
- Say plainly what was and wasn't verified. The integration checks don't exercise browser behaviour, so edit screen changes need a real browser run or a clear note that none happened.
