You are a senior DevOps + backend engineer.

Build a complete Proof of Concept (POC) for AI-powered GitHub Pull Request code review.

The system must use:
- GitHub Actions (CI trigger on PR)
- PHP (single file implementation ONLY)
- Gemini API (Google Generative AI) for code review
- GitHub API for posting PR comments

========================
🎯 GOAL
========================

When a Pull Request is opened or updated:

1. GitHub Action triggers
2. It checks out the repo
3. It generates git diff (ONLY changes in PR vs base branch)
4. It reads AGENTS.md rules (if exists)
5. It sends BOTH diff + AGENTS.md to Gemini API
6. Gemini returns STRICT JSON output + human-readable markdown inside JSON
7. PHP script parses response
8. Script posts a comment on the GitHub PR
9. If ANY critical issues exist → exit(1) to fail CI check

========================
📦 HARD REQUIREMENTS
========================

1. MUST be a SINGLE PHP FILE (no frameworks, no dependencies except curl/file_get_contents)
2. MUST use Gemini API (Google Generative Language API)
3. MUST call GitHub REST API to post PR comments
4. MUST parse AI response as STRICT JSON
5. MUST FAIL pipeline if:
   summary.critical > 0
6. MUST post human-readable review as PR comment
7. MUST ONLY analyze git diff (never full repo)

========================
🧠 GEMINI PROMPT RULES
========================

You MUST embed this system instruction inside the PHP script:

- You are a strict code reviewer for CI/CD
- Review ONLY provided git diff
- Follow AGENTS.md rules if provided
- Detect:
  - security issues
  - logic bugs
  - performance issues
  - bad practices
- Be strict and precise

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
  "human_readable": "markdown string"
}

RULES:
- No extra text outside JSON
- No markdown outside JSON
- No hallucinated files/lines

========================
⚙️ GITHUB ACTION REQUIREMENTS
========================

Create a workflow file:

.github/workflows/ai-review.yml

It must:
- Trigger on pull_request (opened, synchronize, reopened)
- Run on ubuntu-latest
- Install PHP 8.2
- Run: php review.php

It must pass:
- GITHUB_TOKEN
- GEMINI_API_KEY (from secrets)
- PR_NUMBER from GitHub event

========================
🐘 PHP SCRIPT REQUIREMENTS (review.php)
========================

The PHP script must:

STEP 1:
- Get PR diff using git diff against base branch (origin/main or configurable)

STEP 2:
- Load AGENTS.md if exists

STEP 3:
- Call Gemini API:
  https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent

STEP 4:
- Send structured prompt containing:
  - AGENTS.md rules
  - git diff

STEP 5:
- Parse Gemini response safely:
  - extract text
  - json_decode it
  - validate structure

STEP 6:
- Post PR comment using GitHub API:
  POST /repos/{owner}/{repo}/issues/{pr_number}/comments

STEP 7:
- If summary.critical > 0:
  exit(1)
  else exit(0)

========================
🔐 ENV VARIABLES
========================

Use only:
- GITHUB_TOKEN
- GEMINI_API_KEY
- GITHUB_REPOSITORY
- PR_NUMBER

========================
🧾 OUTPUT FORMAT
========================

Return ONLY the complete implementation:

1. review.php (single file)
2. .github/workflows/ai-review.yml

No explanations.
No extra commentary.
Just production-ready code.

========================
🚨 IMPORTANT
========================

- Keep it minimal but production-structured
- No external dependencies
- Must be fully runnable as a POC immediately after copy-paste