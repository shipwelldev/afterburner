# Afterburner specifications

Start with [00 — Project overview](00-project-overview.md). [Boost compatibility notes](boost-compatibility-notes.md) record the pinned Boost baseline and the evidence derived from it. It defines the product boundaries, shared terminology, implementation order, and testing contract. Each numbered feature document then specifies a runnable development increment with its own acceptance criteria.

The numbering expresses implementation order, rather than issue creation order. A feature's Dependencies section identifies which earlier contracts it consumes. Do not implement a later spec by changing a decision owned by an earlier spec without updating that earlier document and its tests.

Feature documents have a one-sentence `description` and one of these statuses:

- `proposed`: implementation is not complete.
- `active`: implementation and tests satisfy the specification.
- `superseded`: another document replaces this specification; identify the replacement.

Each feature document's frontmatter records its current status. The overview describes the intended project and is not itself an implementation task. Mark a feature active only after its acceptance criteria and relevant tests pass; an intermediate stage does not imply the package is release-ready.

Each feature includes behavior, configuration, failure handling, testing guidance, acceptance criteria, an implementation sequence, and boundaries. Prefer observable contracts to prescribed private classes. Public asset locations and command names are compatibility interfaces and are specified explicitly.

The confirmed testing boundary is public Artisan commands in isolated fixture Laravel applications. Subprocess migration tests use that same boundary. See the overview for isolation and test-design requirements.
