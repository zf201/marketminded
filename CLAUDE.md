# MarketMinded — Repo Notes for Claude

Laravel 13 SaaS. All commands run via Sail (host lacks `pdo_pgsql`):

```
./vendor/bin/sail artisan ...
./vendor/bin/sail test
./vendor/bin/sail composer ...
```

## Branching

- `main` is production — Forge auto-deploys on merge.
- Always feature-branch → test → merge. Never push directly to main.
- Never run destructive DB commands (`migrate:fresh`, `db:wipe`, `rollback`) without explicit confirmation.

## Where things live

- **System prompts for chat / agents** live in `app/Services/ChatPromptBuilder.php` (chat orchestrators: brand / topics / writer / funnel) and `app/Services/Writer/Agents/*Agent.php` (writer sub-agents).
- **Reusable prompt content** (copy frameworks, social references, post templates) lives in `prompts/`.
- **Pipeline state** flows through `app/Services/Writer/Brief.php`.
- **Queue worker** runs as `queue:work database_writer --queue=writer --timeout=1800` (matches Forge daemon).

## Editing system prompts

Read [`docs/system-prompt-guidelines.md`](docs/system-prompt-guidelines.md) before changing any prompt in `app/Services/`. It covers altitude, XML structure, cache-friendly ordering, the tool-call discipline pattern (with a reusable template), and a pre-commit checklist. The guidelines are synthesised from Anthropic's 2026 prompting + context-engineering docs.

When you change a prompt:
- Update the unit tests in `tests/Unit/Services/ChatPromptBuilder*Test.php` if structural expectations changed (sections present, tools listed). Don't pin exact wording.
- One change at a time so A/B is possible.
- If the change fixes a real failure mode, add the bad example to the prompt so it doesn't regress.

## Dead code on the way out

`docs/TODO.md` lists components scheduled for removal (the old `ai_tasks` system: `PositioningAgent`, `PersonaAgent`, `VoiceProfileAgent`, related jobs/models). Don't invest in those — brand intelligence now flows through the chat.

## Frontend conventions

- Flux UI components. Single `flux:main` per layout; pages output content directly.
- Use framework defaults (starter kits, artisan generators, official packages) over hand-written equivalents.
