---
description: "Publish local Markdown guidelines and skills with managed sections, shared destinations, and tracked cleanup."
status: active
---

# Application asset publication

## Purpose

Application-authored Markdown becomes agent-visible context and skills. Output ownership is established here so every later source uses the same preservation and cleanup behavior.

## Dependencies

[02 — Configuration](02-configuration-and-agent-selection.md). Tests for this stage use `base: none`, so bundled presets are never mistaken for application content.

## Configuration

Publication uses the selected `agents` and updates the generated `published` inventory only after successful publication. The source and destination conventions below are public compatibility contracts.

## Application sources

Guidelines are Markdown files beneath `.ai/guidelines` (any depth, excluding folders named `skill`), composed in sorted relative-path order under the identity `.ai/<relative path without extension>`. Custom skills are folders beneath `.ai/skills/<directory>/` with a `SKILL.md` entry point. A skill requires YAML frontmatter with nonempty `name` and `description`; its frontmatter `name` is its published identity even when the source folder is named differently. Every supporting file is copied with its relative layout and file mode, so references, scripts, and templates remain usable. A folder below `.ai/skills` without an entry point is skipped with a warning.

Guidelines are standing text; skill bodies stay in their skill folders and are never expanded into guideline documents. Authored prose is published unchanged; Boost tool references are not rewritten.

Stage 04 adds dependency-specific and versioned source paths; stage 05 adds Blade rendering. While Blade rendering was unavailable, a selected Blade source failed publication rather than being copied as an agent instruction.

## Guideline output

Claude Code receives `CLAUDE.md`; Codex, Cursor, Pi, and OpenCode receive `AGENTS.md`. Each target document holds one managed block:

```text
<afterburner-guidelines>
=== .ai/architecture rules ===

…

</afterburner-guidelines>
```

Each composed guideline appears under a `=== <identity> rules ===` heading, and the composed text is normalized with Boost's Markdown formatter. Later publications replace the block in place without touching text before or after it. A missing document is created; an existing document without a block gets one appended after its existing text. When Claude Code and another agent are selected, both documents receive identical blocks.

Delimiters only count when they occupy a whole line, so prose that mentions the tag inline is not treated as managed content. Lines may end in LF or CRLF; a written block uses the document's line endings (CRLF when the document has any), so a document converted to CRLF is updated in place and repeated updates leave it unchanged. A document with an unpaired, repeated, or reversed delimiter line fails with an error naming the document, and nothing is written. Guideline content that itself contains a delimiter line is rejected for the same reason. An existing `<laravel-boost-guidelines>` block is left alone; only explicit replacement (stage 11) adopts it.

Ownership covers the block, not the file. When a document is no longer needed by any selected agent, or no guidelines remain, only the block is removed and surrounding manual text is kept; a document that contained nothing but the block is deleted.

## Skill output and cleanup

Claude Code skills publish to `.claude/skills`; all other integrations share `.agents/skills`. Destinations are computed once per distinct directory, so moving from Codex+Cursor to Cursor keeps the shared skills, and removing the last agent that needs a destination cleans up its managed skills. A skill directory left empty by cleanup is removed, as is its parent (`.claude` or `.agents`) when that also becomes empty.

`published.skills` records the skill names in each destination. Tracked skills are replaced on refresh (including removal of supporting files that no longer exist in the source), and tracked skills that leave the effective selection are removed. Each skill is written to a temporary sibling folder and then moved into place. Untracked sibling folders are never modified. A selected skill whose name matches an untracked folder in a destination fails with a conflict error naming both; it is never claimed or deleted. Only explicit Boost adoption (stage 11) claims existing output.

Cleanup is limited to the known guideline documents and skill directories. A tracked entry outside them is dropped from tracking with a warning and left untouched.

Generated output is not a customization source: application sources are where lasting changes belong, and a managed skill's published files are replaced or removed with that skill.

## Validation and errors

Every selected source is read and validated, and every output conflict is detected, before any generated file or ownership state changes. Missing or empty skill metadata, missing or unclosed frontmatter, invalid YAML, unusable skill names, unreadable selected files, duplicate names among `.ai/skills` folders, malformed delimiters, and untracked collisions each fail with the source or target path, leave previous output and `afterburner.json` unchanged, and say that nothing was published.

If a filesystem operation fails while applying a validated plan, the command fails and names the operation and destination. `published` is then updated to record exactly the output Afterburner owns at that point (including a partially written skill) so the next run can replace or remove it, and the new agent/preset choices are not saved. This guarantee covers selected-input validation; it is not a transaction over arbitrary filesystem failures.

## Testing

`tests/Feature/ApplicationPublicationTest.php` runs install/update in fixture applications and inspects guideline blocks, skill files, supporting files and modes, and `published`. It covers Claude only, each shared integration, all agents with identical blocks, frontmatter-named skills, repeated refresh producing an identical tree, manual text before and after the block, a document converted to CRLF (updated in place, unchanged on repeat, and cleaned on deselection), changed sources and removed supporting files, removed skills and removal of all sources, untracked siblings through refresh and cleanup, untracked same-name collisions, four malformed-delimiter shapes, inline delimiter mentions, invalid selected sources (missing name/description, missing or unclosed frontmatter, invalid YAML, duplicate identities, unsafe names, unreadable guidelines), the pre-rendering Blade guard, keeping a shared root while another agent needs it, last-agent deselection in both directions, an explicit empty agent list, update without saved agents, and a filesystem failure. Invalid-input cases seed output with a successful publication first and compare the whole tree and configuration afterward. Assertions target stable generated content rather than command banners.

## Acceptance criteria

- [x] Local Markdown guidelines and complete skill folders publish to selected destinations.
- [x] Both guideline documents receive equivalent managed content when required.
- [x] Shared roots are written once and remain while any selected agent needs them.
- [x] Manual outer text and untracked skills survive refresh and cleanup.
- [x] Source removal and selection changes remove only stale managed output.
- [x] Input validation failures leave prior generated output and ownership unchanged.
- [x] Repeating an unchanged publication does not duplicate sections or skill content.

## Dev plan

1. Add local source collection and required metadata validation.
2. Implement managed sections, skill publication, and ownership tracking through the existing commands.
3. Add update/cleanup and shared-destination handling.
4. Add public-command preservation, idempotence, and failure regressions.

## Out of scope

Dependency discovery, Blade rendering, bundle fetching, and Boost migration belong to other stages. There are no writer-only public commands.
