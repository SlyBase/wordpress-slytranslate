---
applyTo: '**'
description: 'Completion workflow for Autopilot work: local tests must pass, then create a beta commit based on the plugin version, run Build and Deploy Plugin ZIP, and perform an MCP test translation for post 1109 with Ministral-8B-Instruct-2410-Q4_K_M.'
---

# Autopilot Completion Workflow

This instruction applies whenever a cohesive Autopilot task in the repository is considered complete.

## 1) Local validation is mandatory

- Before any completion claim, the narrowest local tests or validation steps for the changed work must pass without errors.
- If focused PHPUnit tests exist for the change, run those first. Otherwise run the smallest meaningful local check for the affected area.
- If any test or check fails, the work is not complete.

## 2) Create a beta commit after successful validation

- After successful local validation, exactly one commit must be created for each completed task.
- The commit title must follow the pattern `vX.Y.Z-beta.N`.
- `X.Y.Z` is the current plugin version from `slytranslate/ai-translate.php` and `slytranslate/readme.txt`.
- `N` must be computed as `highest existing beta number for the same X.Y.Z` + 1 (check existing git commit subjects/tags first).
- Example: if `v1.6.0-beta.20` already exists, the next commit title must be `v1.6.0-beta.21`.
- Never reset `N` based on local examples or task count; examples in this file are illustrative only.
- Before creating the beta commit, run `SlyTranslate: Build Plugin ZIP` (or `.github/scripts/build-plugin-zip.sh`) once so generated language files are refreshed.
- If that refresh updates tracked `.mo` files, stage them and include them in the same beta commit.
- Do not create the beta commit while any tracked generated language file changes are still unstaged or uncommitted.

## 3) Build and deploy after the commit

- Immediately after the commit, the VS Code task `SlyTranslate: Build and Deploy Plugin ZIP` must complete successfully.
- A task is not finished unless the build succeeds and the plugin ZIP is uploaded successfully to WordPress.

## 4) WordPress MCP smoke test is mandatory

- After a successful deploy, run a test translation for post `1109` through the WordPress MCP tools.
- If needed, first use `mcp_wordpress-sly_mcp-adapter-discover-abilities` to confirm the available translation ability.
- For the actual run, execute the appropriate translation ability through `mcp_wordpress-sly_mcp-adapter-execute-ability`.
- Always use the model `Ministral-8B-Instruct-2410-Q4_K_M`.
- The additional instruction must contain this exact text: `Anreden mit "du" statt "Sie". junger aber professioneller ton.`
- Until this smoke test succeeds, the work must not be reported as complete.

## 5) Failure handling

- If any step above fails, do not claim completion.
- Instead, report the failed step, the relevant error signal, and the next sensible repair step concisely.