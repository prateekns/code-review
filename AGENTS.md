Detect:
## Security Issues
  - Validate and sanitize all external input (GET, POST, COOKIE, FILES)
  - Enforce strict type validation for all inputs
  - Use prepared statements for all database queries
  - Escape all output rendered in HTML, JS, and URLs
  - Implement CSRF protection for all state-changing requests
  - Validate and securely handle file uploads (type, size, location)
  - Store passwords using secure hashing algorithms
  - Do not expose sensitive data in error messages
  - Disable error display in production environments
  - Protect against session fixation and hijacking
  - Regenerate session IDs after authentication
  - Restrict access to sensitive files and directories
  - Avoid using user-controlled data in file paths or system commands

## Logic Bugs
  - Enable strict types in all PHP files
  - Use explicit type declarations for parameters and return values
  - Avoid loose comparisons; always use strict comparisons
  - Handle null values safely and explicitly
  - Validate array keys before accessing them
  - Check all function return values before use
  - Handle edge cases (empty, zero, false conditions)
  - Avoid deeply nested conditional logic
  - Ensure consistent control flow with early returns
  - Avoid implicit type coercion
  - Ensure all code paths return expected values

## performance issues
- Avoid N+1 database query patterns
- Minimize database calls and batch queries when possible
- Avoid redundant computations inside loops
- Cache reusable results when appropriate
- Avoid loading large datasets into memory unnecessarily
- Use efficient data structures and built-in functions
- Avoid repeated file includes or requires
- Use autoloading for class loading
- Optimize string and array operations
- Profile and monitor slow code paths

## bad practices
- Avoid direct use of superglobals in business logic
- Do not hardcode configuration values or secrets
- Separate concerns (logic, data, presentation)
- Avoid magic numbers and hardcoded strings
- Use constants or enums for fixed values
- Do not suppress errors silently
- Avoid deprecated or removed PHP features
- Do not use dynamic properties
- Maintain consistent naming conventions
- Keep functions and classes small and focused
- Avoid code duplication
- Follow established coding standards (e.g., PSR-12)
- Avoid mixing procedural and object-oriented styles unnecessarily
- Ensure proper exception handling strategy
- Do not leave debug code or commented-out blocks in production

Be strict and precise.

OUTPUT MUST BE VALID JSON ONLY:

{
  "summary": {
    "critical": number,
    "high": number,
    "medium": number,
    "low": number
  },
  "issues": [
    {
      "severity": "critical|high|medium|low",
      "file": "string",
      "line": number,
      "message": "string",
      "suggestion": "string"
    }
  ],
  "human_readable": "GitHub-style PR review comments grouped by file"
}

HUMAN_READABLE FORMAT RULES:

- Group comments by file
- Each issue must appear like:

File: <file path>
Line: <line or line range>
Severity: <critical|high|medium|low>
Issue: <short explanation>
Impact: <what could go wrong>
Suggestion: <fix recommendation>

- Use line ranges (e.g. 25–30) when applicable
- Must be valid markdown
- Must NOT include JSON inside this field
- Must reflect ONLY provided diff (no hallucinations)

RULES:
- No extra text outside JSON
- No markdown outside JSON
- No hallucinated files/lines
- For each issue, use exact changed line from diff
- Include function or scope name when available in message
