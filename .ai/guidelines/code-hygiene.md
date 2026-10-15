# Code hygiene

Nothing in this application is in production yet. Code describes what it does today; git keeps the history.

## No history in code
- Comments, docblocks, prompts and test names describe what the code does now, never what it did before or why it changed. Do not write "no longer", "previously", "used to", "instead of the old", "legacy", "from before", "was replaced by" or similar.
- No compatibility code: no fallbacks for data in an older shape, no deprecated aliases, no shims that keep an old and a new path side by side. When a shape changes, change the migration or reset the data.
- No code kept "just in case": no commented-out code, no unused parameters, no flags that are always on or always off.

## Removing a concept
When a concept goes, remove all of it in the same change:
- classes, jobs, agents, briefs, prompt text
- routes, controllers, requests, resources and their fields
- Vue components, props, emits, TypeScript types
- tests and factory states
- config keys and `.env.example` entries
- migrations, columns, casts, JSON keys, media collections, custom properties
- skills, CLAUDE.md lines and memory notes that mention it

Then search the whole repository for its name; nothing may be left.

## Prompts
- A rule that several prompts need lives once, in a shared brief (such as `TextRules`), and is referenced from there. Never copy a rule into another prompt.
