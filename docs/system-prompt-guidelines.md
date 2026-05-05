# System Prompt Guidelines

Internal reference for how we write system prompts in MarketMinded (chat orchestrators, sub-agents, tool prompts). Synthesised from Anthropic's prompting + context-engineering guides and the broader 2026 agent-prompting literature. Treat this as a checklist when adding or revising any prompt in `app/Services/`.

## 1. Find the right altitude

The prompt should sit between two failure modes:

- **Too low / hardcoded** — long if/then ladders, exhaustive rule lists. Brittle, hard to maintain, and the model still finds edge cases you didn't enumerate.
- **Too high / vague** — "be helpful, be concise". No concrete signal, output drifts.

Aim for the Goldilocks zone: a few sharp rules + concrete examples + clear role. If a section feels like documentation, cut it.

Practical sweet spot for the body of a system prompt is roughly **150–600 words**. Reasoning quality starts degrading around 3k tokens of instruction. Brand context / reference data lives in tagged blocks at the end and doesn't count against this budget.

## 2. Structure with XML-style tags

Claude is trained on tagged prompts. Wrap reference data and dynamic context in tags so the model can distinguish *instructions* from *data it's reasoning over*:

```
<brand-profile>...</brand-profile>
<existing-posts>...</existing-posts>
<brief-status>...</brief-status>
```

Always tell the model **not to echo tagged reference data back** — otherwise it will copy chunks verbatim into the user-facing reply.

## 3. Order content for prompt caching

Static first, dynamic last. The model (and the cache) reads top-to-bottom:

1. Date header / today's date
2. Role + core instructions
3. Tool list and rules
4. Worked examples (good/bad)
5. Dynamic reference blocks (`<brand-profile>`, `<existing-posts>`, brief state)
6. The user's turn

Anything that changes per-request belongs near the bottom. Anything stable across a session belongs near the top. This is worth real money on long conversations.

## 4. Tool-calling discipline (the big one)

This is where our prompts most often fail. Patterns that work:

- **Name the persistence boundary.** State explicitly that the user only sees data that went through a tool. Text in the message body is invisible to them.
- **Forbid claiming a save without a call.** "Never say 'saved', 'noted', 'captured', 'added' unless you called the tool in the same turn."
- **Default to saving.** "If unsure whether to save, save. The user can edit later." Hesitation is the failure mode, not over-saving.
- **Specify the trigger, not just the tool.** "When the user describes a persona, call X" beats "use X to save personas".
- **One concrete good example, one or two bad examples.** Condensed, single-line where possible. Bad examples should show the *exact* failure mode (claimed-but-didn't-save, narrated-instead-of-called).

Template:

```
## CRITICAL: nothing is saved unless you call the tool
The user only sees data persisted through <tool_name>. Text in your message is NOT saved.
- NEVER say "saved/noted/captured/added" unless you called <tool_name> this turn.
- The moment you learn something worth keeping, call <tool_name> BEFORE replying.
- When unsure, save. The user can edit later.

## Good vs bad loop
GOOD: <trigger> → <tool_name>(...) → reply: "<short confirmation + next question>"
BAD:  <trigger> → reply: "Got it, saved." (NO tool call — you just lied to the user.)
BAD:  <trigger> → reply: "Noted. Anything else?" (NO tool call — the data is gone.)
```

## 5. Role and voice

Lead with a one-line role: "You are a brand strategist…", "You orchestrate a blog writing pipeline…". Roles outperform instruction-only prompts measurably.

Also specify the *anti-role* when it matters: "You DO NOT do research yourself — you call sub-agent tools."

## 6. Negative constraints

Telling the model what NOT to do is as important as what to do. Examples that earn their keep:

- "Never output raw JSON or data structures in user-facing messages."
- "Do not narrate the content of tool results."
- "Do not propose duplicates of past topics."
- "Write in the same language as the brand profile. Do NOT mix languages."

One negative constraint per real failure you've seen. Don't pile on hypotheticals.

## 7. Calibration via past data

When prior runs exist, feed scored examples back in. Pattern from the topics prompt:

```
<past-topics>
- Topic title — score: 8/10
- Topic title — score: 3/10
</past-topics>
- Lean toward shapes that scored 7+. Avoid shapes that scored ≤4.
- Treat unrated as ~5/10.
- Do not propose duplicates.
```

This is cheap, effective context engineering — the user's ratings *are* the spec for "good" in their voice.

## 8. Pipelines and orchestrators

For multi-step orchestrators (writer, funnel):

- Number the pipeline steps explicitly.
- State "run back-to-back without pausing for approval" if that's the intent — otherwise the model will ask for confirmation between every step.
- Tell it how to handle `{status: skipped}` and `{status: error}` returns. Default: skipped → log + continue, error → retry once with `extra_context`, then surface.
- Forbid narrating what tools are doing. "Brief status lines like 'Researching…' are fine. Do NOT narrate the content of tool results."

## 9. Iteration discipline

Treat prompts like code:

- Change one thing at a time. Two simultaneous edits make A/B impossible.
- When you fix a real failure, add the bad example to the prompt so it doesn't regress.
- Keep the unit tests in `tests/Unit/Services/ChatPromptBuilder*Test.php` honest — they should pin down structural expectations (sections present, tools listed, profile injected), not exact wording.

## 10. Checklist before committing a prompt change

- [ ] Role stated in one line at the top
- [ ] Tools listed with one-line purpose each
- [ ] Persistence/tool-call discipline section if any tool writes data
- [ ] One GOOD and one or two BAD condensed examples
- [ ] Negative constraints for known failure modes
- [ ] Reference data in `<tagged-blocks>` at the bottom, with "do not echo" warning
- [ ] Static content first, dynamic last (cache-friendly order)
- [ ] Body is under ~600 words excluding reference blocks

## Sources

- [Anthropic — Effective context engineering for AI agents](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents)
- [Anthropic — Claude prompting best practices](https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/claude-prompting-best-practices)
- [Anthropic — Demystifying evals for AI agents](https://www.anthropic.com/engineering/demystifying-evals-for-ai-agents)
- [Prompt Engineering Guide — LLM Agents](https://www.promptingguide.ai/research/llm-agents)
- [Composio — Tool Calling Explained (2026)](https://composio.dev/content/ai-agent-tool-calling-guide)
- [Red Hat — Big vs. small prompts for AI agents (Feb 2026)](https://developers.redhat.com/articles/2026/02/23/prompt-engineering-big-vs-small-prompts-ai-agents)
- [promptengineering.org — 2026 Playbook for Reliable Agentic Workflows](https://promptengineering.org/agents-at-work-the-2026-playbook-for-building-reliable-agentic-workflows/)
