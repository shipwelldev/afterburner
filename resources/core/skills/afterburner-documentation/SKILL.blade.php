---
name: afterburner-documentation
description: "Use before changing code that depends on Laravel or a Laravel ecosystem package's APIs, configuration, behavior, or version-specific syntax, or when instructions say to search the docs (Boost's search-docs). Identifies the installed package versions and finds the matching official documentation with your own research tools."
---
# Finding the right documentation

Use documentation that matches the versions this application has installed. Package APIs change between major versions, and the newest docs are often wrong for an older application.

## 1. Identify the installed versions

- Composer dependencies: `{{ $assist->composerCommand('show --direct') }}` prints every direct dependency and its installed version; add a package name (for example `{{ $assist->composerCommand('show livewire/livewire') }}`) to see one.
- JavaScript dependencies: the committed lockfile records exact versions, and `{{ $assist->nodePackageManagerCommand('list --depth=0') }}` prints the top level.
- Framework and runtime: `{{ $assist->artisanCommand('about') }}` reports the Laravel and PHP versions.

Note the major version of every package involved in the change.

## 2. Find the primary source

Prefer the official documentation for the installed major version:

- Laravel: `https://laravel.com/docs/<major>.x/<topic>`
- Laravel first-party packages (Livewire, Inertia, Pest, Pint, Sanctum, Horizon, and others): the package's official documentation site, using its version selector when it has one.
- Other packages: the project's documentation or the README and changelog in its repository for the installed tag. The installed source in `vendor/<vendor>/<package>` or `node_modules/<package>` is authoritative for exact signatures.

## 3. Search with your own tools

Use your web search, fetch, or research tools to read those pages. Search for the feature or concept rather than a whole sentence, add the major version when results mix releases, and open the relevant page instead of trusting a search snippet. Documentation you already read earlier in this task can be used again without another search.

If you have no web or research tool, say so. Then use the installed source and package READMEs under `vendor/` or `node_modules/`, follow the application's guidelines, and ask the user before relying on an API you could not verify. Do not present unverified API details as documented.

A documentation lookup is unnecessary when a change does not touch package behavior, such as rewording text or adjusting styles.
