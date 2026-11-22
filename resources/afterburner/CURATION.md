# Afterburner curated guidance

This directory is Afterburner's separately maintained copy of the Laravel Boost guidance. Its base is the vendored snapshot in `../boost`: laravel/boost v2.10.4 (commit `9cd5e7d81300b5dee4c8b1619ec4b36f4a6367fd`, MIT; see `LICENSE.md`) with Afterburner's adaptations from `../boost/ADAPTATIONS.patch` applied. Those adaptations make the guidance read Afterburner's configuration instead of Boost's, remove content that only applies to Boost's MCP server, and point Boost MCP tool instructions at the Afterburner skills that replace them.

Refreshing the snapshot never modifies this directory; maintainers port upstream changes here by hand.

## Changes from the adapted snapshot

| Path | Change |
| --- | --- |
| `afterburner/` | Replaces the snapshot's `boost/` folder, which holds the catalog's own core guideline and skills. The core guideline's identity is `afterburner`. |
| `afterburner/core.blade.php` | Heading renamed from "Laravel Boost" to "Afterburner". |
| `afterburner/skill/infer-conventions/` | Infers conventions only where the application's coding standard, if its guidelines or skills define one, is silent. The standard is read before the sweep, dimensions it decides get a "decided by the standard" verdict, and code that departs from it is reported as a departure rather than recorded; existing code never overrides the standard. |
| `deployments/` | Removed: the Laravel Cloud deployment guideline (including its promotion) and the `deploying-to-cloud` integration skill. |
| `php/` | Removed. Afterburner ships no code style guidance: `php/core` and the `php/8.4`/`php/8.5` preferences are style rules, and the empty `php/8.2` and `php/8.3` files were removed. |
| `foundation.blade.php` | Heading renamed from "Laravel Boost Guidelines" to "Afterburner Guidelines". The descriptive-names style rule removed. The "follow existing code conventions" rule now applies only where the application's coding standard, when its guidelines or skills define one, is silent; existing code never overrides the standard. |
| `laravel/core.blade.php` | Style rules removed (Afterburner ships no code style guidance): default to Eloquent API Resources and API versioning, prefer named routes and `route()`, use model factories and their states in tests, Faker style, and "most tests should be feature tests". The `make:test` instruction stays. |
| `laravel/11/core.blade.php`, `laravel/12/core.blade.php` | The model `casts()` rule removed (Afterburner ships no code style guidance). |
| `livewire/core.blade.php` | "Keep state server-side so the UI reflects it" removed (Afterburner ships no code style guidance); validating and authorizing in actions stays. |
| `volt/core.blade.php` | The functional versus class-based component style rule removed (Afterburner ships no code style guidance). |
| `inertia-laravel/core.blade.php` | The skeleton empty state for deferred props (v2 and v3 sections) removed (Afterburner ships no code style guidance). |

Everything else matches the adapted snapshot.
