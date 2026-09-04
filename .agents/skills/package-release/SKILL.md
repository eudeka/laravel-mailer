---
name: package-release
description: "Use this skill when preparing Laravel package releases: CHANGELOG.md updates, generated release notes, GitHub release workflows, version checks, tags, release validation, or release automation changes. Never publish autonomously."
license: MIT
metadata:
    author: laravel
---

# Package Release

## Primary Goal

Prepare a safe package release checklist and implementation without tagging, pushing, or publishing unless the user explicitly approves that action.

## Workflow

1. Review `CHANGELOG.md`, GitHub release workflows, open diff, and pending package changes.
2. Validate the release state with `composer test` before recommending a release.
3. Ensure the `"version"` field in `composer.json` is bumped to match the target release version, and included in the release commit.
4. Review tag naming, release branch, and GitHub release workflow behavior before any release command.
5. Do not tag, push, or publish without explicit user approval.

## References

- `CHANGELOG.md`
- `.github/workflows/release.yml`
- `.github/workflows/tests.yml`
- `composer.json`

## Examples

- Prepare a release by updating `CHANGELOG.md`, bumping `"version"` in `composer.json`, running `composer test`, and pushing a commit with message `chore(release): vX.Y.Z` or `release: vX.Y.Z` to trigger the automated release workflow.

## Anti-Patterns

- Creating tags, pushing branches, or publishing releases without explicit approval.
- Skipping `composer test` before a release recommendation.
- Treating generated release notes as a replacement for meaningful `CHANGELOG.md` entries.
- Changing release workflows without checking the supported matrix in `.github/workflows/tests.yml`.
