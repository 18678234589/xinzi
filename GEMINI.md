# Antigravity & Agent Guidelines for Salary System

## 1. Project Overview & Working Directory
- Project Root: `C:/mydev/xinzi.206.yjkj2026.cn`
- Always use standard absolute Windows paths (e.g. `C:/mydev/...`) or relative paths.
- Never prefix paths with double backslashes (like `\\C:\`) to prevent UNC path resolution errors.

## 2. Tool & Function Calling Safety Rules
- **Strict Parameter Validation**: When invoking any tool (such as file operations, commands, or subagents), always provide complete, well-formed arguments matching the tool's JSON schema.
- **No Empty Tool Calls**: Never emit empty function calls, empty arguments (`{}` for parameterized tools), or malformed tool payloads. If required parameters are missing, prompt the user for clarification before attempting to call a tool.
- **Batching & Execution**: Keep file reads, diffs, and command outputs scoped and concise to avoid upstream token limits or context overflow.
