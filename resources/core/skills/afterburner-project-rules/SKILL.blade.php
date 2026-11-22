---
name: afterburner-project-rules
description: "Use before planning or editing files to find the project rules in .ai/rules that apply to them, and when the user asks you to remember, record, or document a durable convention, decision, or constraint for this application (Boost's record-rule). Rules live as Markdown in .ai/rules and are indexed with an Artisan command."
---
# Project rules

Project rules are durable, application-specific conventions, decisions, and constraints, each scoped to the paths where it applies. They live in `.ai/rules` as Markdown files with `paths` frontmatter, and `.ai/rules/index.md` maps those paths to rule files.

## Finding the rules that apply

1. If `.ai/rules/index.md` does not exist, there are no indexed rules; continue.
2. Read the index and find every row whose globs match a path you will plan for or edit. Several rows can match one path.
3. Read each matching rule file and follow all of them. Then search the rule files for terms from the task, for example `grep -ril 'invoice' .ai/rules`, because a relevant rule can be scoped to a different path than the one you are editing.

## Recording a rule

Record a rule only when the user asks you to remember or document something durable about this application, or approves a rule you proposed. Do not record one-off task details, personal preferences of the moment, or framework defaults.

1. Choose the most specific glob that covers where the rule applies, for example `app/Models/**`, `app/Http/Controllers/**`, `database/migrations/**`, or `tests/**`. Use `app/**` only for truly application-wide rules.
2. Look for an existing file in `.ai/rules` whose `paths` contains exactly that glob (and nothing broader). If there is one, add the rule to it. Otherwise create `.ai/rules/<area>.md` named after the area, for example `.ai/rules/models.md`.
3. Keep the existing frontmatter and entries. When the rule needs an additional glob, append it to the `paths` list rather than replacing the list. Never remove someone else's entry.
4. Add the rule as a `##` heading with one or two imperative lines stating the convention, with no detection evidence, counts, or file lists.
5. Run `{{ $assist->artisanCommand('afterburner:index-rules') }}`. Fix any file it reports as skipped or conflicted, then run it again until it succeeds.

A new rule file looks like this:

```markdown
---
paths:
  - 'app/Models/**'
---

# Models

## Primary keys
Models use UUID primary keys. Add the `HasUuids` trait to new models.
```

Do not edit `.ai/rules/index.md` or files in `.ai/rules/boost/`; they are generated and are replaced on the next refresh. A rule that matters in two places, such as model classes and the migrations that create their tables, needs an entry under each glob; naming the other location in the text does not make the rule appear there.
