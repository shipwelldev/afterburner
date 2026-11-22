---
description: "Index Boost-compatible project rules and optionally extract scoped package guidance into managed rules."
status: active
---

# Project rules

## Purpose

Durable application knowledge and scoped discovery work without a recording MCP tool. The index command supplies the behavior the rule-recording skill (stage 08) runs after editing rules.

## Dependencies

[05 — Rendering and validation](05-blade-rendering-and-validation.md) supplies the selected scoped bodies. Extraction reuses [03 — Publication ownership](03-application-asset-publication.md) and the single configuration/tracking file.

## Configuration

`rules.scoped_guidelines` is `false` unless explicitly enabled. The index command is available under every preset. Presets control bundled guidance and skills; they never touch user-authored rules.

## Rule storage and discovery

User rules are Markdown files directly in `.ai/rules`, with YAML frontmatter declaring `paths` as a list of applicable globs (a single string is accepted as one glob). Existing Boost rule files work unchanged. The generated `.ai/rules/index.md` maps scopes to rule files and is never indexed as a rule.

Discovery matches the researched Boost surface: top-level `.ai/rules/*.md` plus managed scoped rules in `.ai/rules/boost/*.md`. Deeper folders are not discovered. Rows use project-relative paths in byte-order relative-path order, so the index is identical regardless of file creation order. Pipe characters in globs or paths are escaped for the table.

The index text tells agents to find every row whose globs match a file's path and read each of those rule files, noting that a file can match several rows; it is not first-match. Generating an index does not by itself make an agent consult it: the `afterburner-project-rules` skill (stage 08) tells agents to read it before planning or editing, and the Boost guidance in the `boost` and `afterburner` presets also points to it.

## Index command

`afterburner:index-rules` and `boost:index-rules` run the same operation with identical output. Without a `.ai/rules` directory the command succeeds with "No project rules found in .ai/rules." and creates nothing. Otherwise it regenerates the index from eligible rules; an empty directory gets an index stating that no rules are recorded yet, and no placeholder rules are created.

Files without usable `paths` frontmatter (no frontmatter, no `paths` key, or an empty list) are reported as skipped with a warning and left out of the index. Frontmatter that is not valid YAML is reported the same way with the parse error, rather than disappearing silently. Lines beginning with seven `<` or `>` characters in any discovered rule file are unresolved merge conflicts: the command fails, lists the affected files, and leaves the previous index unchanged.

Indexing never invents conventions and never rewrites rule files. Manual edits become discoverable the next time the command runs.

## Scoped guideline extraction

With extraction off, scoped guideline bodies stay in the standing guidance. With `rules.scoped_guidelines: true`, every recorded `@scoped` block from the selected guidelines (application, dependency, and bundled) is removed from the standing guidance and written to a managed rule file in `.ai/rules/boost`. Blocks with the same set of globs (compared after trimming, deduplicating, and sorting) share one file. File names and titles follow Boost's rule composer: the slug comes from the last non-glob segment of each glob (`app/Models/**` + `database/migrations/**` → `models-migrations.md`), with a numeric suffix when taken, and a file gets a `# Title` heading unless its content already starts with one. A guideline whose content was entirely scoped is omitted from the standing guidance. The index is regenerated whenever generated rules change.

`published.rules` tracks the generated files. User rules and generated rules have different ownership: user rules are never modified, a generated rule whose path is occupied by an untracked file fails with a conflict error instead of overwriting it, and stale generated rules are removed when a package or preset is excluded, a package is removed, a scope changes, or extraction is disabled. Disabling extraction restores the bodies inline on the same refresh. When no rules remain at all, the generated index and the empty directories are removed. `core` and `none` add no bundled rule guidance; the index command and application rules remain available under every preset.

Selected scoped content is rendered and validated before any rule, guideline, index, or tracking change. If the index must be rewritten while a rule file contains unresolved merge conflicts, publication fails before writing anything. A successful refresh never leaves the index pointing at a removed generated file, and extraction never silently drops content.

## Rule authoring contract for skills

The recording skill (stage 08) edits rule Markdown directly, preserving existing scope metadata and entries, and then runs the index command. There is no Artisan command whose only purpose is appending prose. Convention inference uses the same workflow with Boost's evidence, user-request, and approval behavior. Installation never runs a convention scanner.

## Testing

`tests/Feature/ProjectRulesTest.php` covers the index command through both names: no directory, an empty directory, a single rule, multiple scopes, several rules matching one path, deterministic ordering across creation order and repeated runs, managed rule discovery, skipped files with warnings (no frontmatter, no paths, empty paths, invalid YAML) and undiscovered nested folders, conflict markers in user and managed rules preserving the previous index, existing Boost rule files and manual additions with unchanged bodies, and identical canonical/alias output.

For extraction it covers inline defaults, opt-in grouping and file contents, a guideline that was only scoped content, idempotent refresh, changed scopes, disabling extraction (restoring inline content and preserving the user rule), removal of the index when no rules remain, package exclusion and removal, the validation gate after seeding extracted output, an untracked file in the managed directory, and unresolved conflicts blocking an index rewrite.

## Acceptance criteria

- [x] Canonical and compatibility index commands behave identically without MCP.
- [x] Existing user rule scopes and bodies remain usable and unchanged by indexing.
- [x] Index output is deterministic and calls for all matching rules.
- [x] No-rules, invalid metadata warnings, and conflict-marker failures are observable.
- [x] Scoped bodies remain inline by default and can be extracted explicitly.
- [x] Successful extraction/cleanup keeps guidelines, generated rules, index, and tracking consistent.
- [x] User rules survive generated-rule changes and disabling extraction.

## Dev plan

1. Implement compatible rule discovery, metadata parsing, and index generation.
2. Add command/alias behavior and warning/failure cases.
3. Integrate scoped extraction with the validated publication plan and cleanup.
4. Add round-trip extraction/disable and user-rule preservation tests.

## Out of scope

There is no rule-recording MCP endpoint, automatic convention discovery during installation, recursive rule system, or prose-append command.
