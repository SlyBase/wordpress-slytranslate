---
applyTo: '**'
description: 'Completion workflow for Autopilot work: local tests must pass, then create a normal commit via the git-commit skill, run the plugin-specific build and deploy task, and perform the matching MCP smoke test.'
---

# Autopilot Completion Workflow

This instruction applies whenever a cohesive Autopilot task in the repository is considered complete.

## 1) Local validation is mandatory

- Before any completion claim, the narrowest local tests or validation steps for the changed work must pass without errors.
- If focused PHPUnit tests exist for the change, run those first. Otherwise run the smallest meaningful local check for the affected area.
- If any test or check fails, the work is not complete.

## 2) Create a normal commit after successful validation

- After successful local validation, exactly one commit must be created for each completed task.
- Use the `git-commit` skill to analyze the diff and create a conventional commit message that matches the actual change.
- Do not force a beta suffix or version-based commit title.
- Before creating the commit, run `WP Plugin: Build and Verify Plugin ZIP` once so generated language files are refreshed.
- If that refresh updates tracked `.mo` files, stage them and include them in the same commit.
- Do not create the commit while any tracked generated language file changes are still unstaged or uncommitted.

## 3) Build and deploy after the commit

- Immediately after the commit, the matching VS Code build-and-deploy task must complete successfully.
- Use exactly one of these tasks depending on the affected plugin environment:
	- `WP Plugin: Build and Deploy Plugin ZIP to SlyBase WordPress Pod` for Polylang / SlyBase work
	- `WP Plugin: Build and Deploy Plugin ZIP to TranslatePress WordPress Pod` for TranslatePress work
	- `WP Plugin: Build and Deploy Plugin ZIP to WP-Globus WordPress Pod` for WPGlobus work
- A task is not finished unless the build succeeds and the plugin ZIP is uploaded successfully to WordPress.

## 4) MCP Smoke Test
- After deployment, perform a quick MCP smoke test of the affected area to verify the change is live and working as expected.
- If the change only affects one specific language plugin, only the matching plugin smoke test is required.
- If the change affects shared code or multiple language plugins, run smoke tests for each affected plugin environment.

### Polylang smoke test

- MCP: `wordpress-slybase`
- Post ID: `1468`

### TranslatePress smoke test

- MCP: `wordpress-translatepress`
- Post ID: `8`

### WPGlobus smoke test

- MCP: `wordpress-wpglobus`
- Post ID: `8`

### Shared smoke test parameters

- Translation direction: `de` -> `en`
- `overwrite`: `true`
- Model: `Ministral-3-3B-Instruct-2512-Q4_K_M`


## 5) Failure handling

- If any step above fails, do not claim completion.
- Instead, report the failed step, the relevant error signal, and the next sensible repair step concisely.