---
name: afterburner-debugging
description: "Use when investigating errors, exceptions, failed requests, failing jobs, or broken pages in this Laravel application, including reading the application log, finding the last error, and checking browser console errors (replaces Boost's read-log-entries, last-error, and browser-logs tools)."
---
# Debugging

Base every conclusion on output you actually read. Never invent log lines, stack traces, or console messages.

## Find the application log

1. Run `{{ $assist->artisanCommand('config:show logging.default') }}` and `{{ $assist->artisanCommand('config:show logging.channels') }}` to see which channel is active and where it writes. A `stack` channel writes to each channel in its `channels` list.
2. The `single` channel writes to `storage/logs/laravel.log`; the `daily` channel writes `storage/logs/laravel-YYYY-MM-DD.log`, so read the newest file. Channels such as `stderr`, `syslog`, `papertrail`, or `slack` do not write a file in the project; say so and ask the user for that output.

## Read recent entries and the last error

- Read the end of the log, for example `tail -n 200 storage/logs/laravel.log`, and follow entries from their timestamp line through the end of the stack trace.
- To find the most recent error, search for the last line containing `.ERROR:` (or `.CRITICAL:`, `.ALERT:`, `.EMERGENCY:`), for example `grep -n '\.ERROR:' storage/logs/laravel.log | tail -n 1`, then read from that line onward.
@if($assist->hasPackage('laravel/pail'))
- Laravel Pail is installed: `{{ $assist->artisanCommand('pail --timeout=30') }}` tails the log while you reproduce the problem. Filter with `--filter="QueryException"` or `--level=error`.
@endif
- Only recent entries are relevant. Compare timestamps with when the problem happened, and ignore older noise.

## Reproduce and confirm

Reproduce the failure with a test or with `{{ $assist->artisanCommand("tinker --execute '…'") }}` before changing code, and use the same check to confirm the fix. For failed queued jobs, run `{{ $assist->artisanCommand('queue:failed') }}`.

## Browser problems

For JavaScript errors, failed network requests, or rendering problems, use your own browser or computer-use tool to open the page, reproduce the issue, and read the browser console and network activity. Do not install a log collector or add scripts to the application to capture browser output.

If you do not have a browser or computer-use tool, say so, describe what to check, and ask the user to share the console output or a screenshot.
