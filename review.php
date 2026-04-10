<?php

declare(strict_types=1);

/**
 * AI PR Review POC - single file, no dependencies.
 *
 * Env vars:
 * - GITHUB_TOKEN
 * - GEMINI_API_KEY
 * - GEMINI_MODEL (optional)
 * - GITHUB_REPOSITORY (owner/repo)
 * - PR_NUMBER
 */

const BASE_BRANCH_REF = 'origin/main';
const DEFAULT_GEMINI_MODEL = 'gemini-pro';
const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
const GITHUB_API_BASE = 'https://api.github.com';
const MAX_DIFF_BYTES = 400_000;
const MAX_AGENTS_BYTES = 50_000;
const MAX_HTTP_RESPONSE_BYTES = 1_000_000;
const MAX_LOG_BYTES = 20_000;
const CURL_TIMEOUT_SECONDS = 60;
const CURL_CONNECT_TIMEOUT_SECONDS = 15;
const USER_AGENT = 'ai-review-php/1.0';

/**
 * MUST embed system instruction inside this script.
 */
const GEMINI_SYSTEM_INSTRUCTION = <<<'PROMPT'
You are a strict code reviewer for CI/CD
Review ONLY provided git diff
Follow AGENTS.md rules if provided
Detect:
 - security issues
 - logic bugs
 - performance issues
 - bad practices
Be strict and precise

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
PROMPT;

main();

function main(): void
{
    $githubToken = requireEnv('GITHUB_TOKEN');
    $geminiApiKey = requireEnv('GEMINI_API_KEY');
    $repo = requireEnv('GITHUB_REPOSITORY');
    $prNumber = requirePositiveIntEnv('PR_NUMBER');

    $model = getenv('GEMINI_MODEL');
    $geminiModel = $model !== false && trim($model) !== '' ? trim($model) : DEFAULT_GEMINI_MODEL;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $geminiModel)) {
        failWithComment(
            $githubToken,
            $repo,
            $prNumber,
            'Invalid GEMINI_MODEL value. Allowed: letters, numbers, ".", "_", "-".',
            $geminiModel
        );
    }

    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
        failWithComment($githubToken, $repo, $prNumber, 'Invalid GITHUB_REPOSITORY format. Expected owner/repo.', null);
    }

    ensureBaseBranchFetched();

    $diff = getGitDiff();
    [$diffForPrompt, $diffTruncated] = truncateBytes($diff, MAX_DIFF_BYTES);

    $agentsRules = readAgentsRules();

    $userPrompt = buildUserPrompt($agentsRules, $diffForPrompt, $diffTruncated);

    $geminiRawResponse = callGemini($geminiApiKey, $geminiModel, $userPrompt);
    $reviewJsonText = extractGeminiText($geminiRawResponse);

    try {
        $review = json_decode($reviewJsonText, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        failWithComment(
            $githubToken,
            $repo,
            $prNumber,
            "Gemini returned non-JSON or invalid JSON. Error: {$e->getMessage()}",
            $geminiModel
        );
        return;
    }

    $validationErrors = validateReviewPayload($review);
    if ($validationErrors !== []) {
        $msg = "Gemini JSON failed validation:\n- " . implode("\n- ", $validationErrors);
        failWithComment($githubToken, $repo, $prNumber, $msg, $geminiModel);
    }

    $summary = $review['summary'];
    $humanReadable = (string) $review['human_readable'];

    $critical = (int) $summary['critical'];
    $high = (int) $summary['high'];
    $medium = (int) $summary['medium'];
    $low = (int) $summary['low'];

    $commentBody = buildCommentBody($geminiModel, $humanReadable, $critical, $high, $medium, $low);
    postPrComment($githubToken, $repo, $prNumber, $commentBody);

    if ($critical > 0) {
        exit(1);
    }

    exit(0);
}

function requireEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        fwrite(STDERR, "Missing required env var: {$name}\n");
        exit(2);
    }

    return trim($value);
}

function requirePositiveIntEnv(string $name): int
{
    $value = requireEnv($name);
    if (!preg_match('/^[0-9]+$/', $value)) {
        fwrite(STDERR, "Invalid {$name}: must be an integer.\n");
        exit(2);
    }

    $int = (int) $value;
    if ($int <= 0) {
        fwrite(STDERR, "Invalid {$name}: must be > 0.\n");
        exit(2);
    }

    return $int;
}

function ensureBaseBranchFetched(): void
{
    $cmd = 'git fetch origin main --no-tags --prune';
    $result = runCommand($cmd);
    if ($result['exit_code'] !== 0) {
        fwrite(STDERR, "Failed to fetch base branch. Output:\n" . sanitizeForLogsWithSecrets($result['output'], []) . "\n");
        exit(1);
    }
}

function getGitDiff(): string
{
    $cmd = 'git diff --unified=0 ' . escapeshellarg(BASE_BRANCH_REF . '...HEAD');
    $result = runCommand($cmd);

    if ($result['exit_code'] !== 0) {
        fwrite(STDERR, "Failed to generate git diff. Output:\n" . sanitizeForLogsWithSecrets($result['output'], []) . "\n");
        exit(1);
    }

    return $result['output'];
}

/**
 * @return array{0: string, 1: bool} [truncatedText, wasTruncated]
 */
function truncateBytes(string $text, int $maxBytes): array
{
    $bytes = strlen($text);
    if ($bytes <= $maxBytes) {
        return [$text, false];
    }

    $slice = substr($text, 0, $maxBytes);

    $note = "\n\n[NOTE] Content truncated to {$maxBytes} bytes for safety. Review may be incomplete.\n";
    $remaining = $maxBytes - strlen($note);
    if ($remaining > 0) {
        $slice = substr($slice, 0, $remaining) . $note;
    }

    return [$slice, true];
}

function readAgentsRules(): string
{
    $path = getcwd() . DIRECTORY_SEPARATOR . 'AGENTS.md';
    if (!is_file($path)) {
        return '';
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return '';
    }

    $trimmed = trim($content);
    [$limited] = truncateBytes($trimmed, MAX_AGENTS_BYTES);

    return $limited;
}

function buildUserPrompt(string $agentsRules, string $diff, bool $diffTruncated): string
{
    $parts = [];
    $parts[] = "AGENTS.md rules (may be empty):\n" . ($agentsRules !== '' ? $agentsRules : '[none]');

    $parts[] = "Git diff against " . BASE_BRANCH_REF . " (PR-only changes):\n" . ($diff !== '' ? $diff : '[empty diff]');

    if ($diffTruncated) {
        $parts[] = 'Reminder: diff was truncated due to size limits.';
    }

    return implode("\n\n---\n\n", $parts);
}

function callGemini(string $apiKey, string $model, string $userPrompt): string
{
    $url = GEMINI_API_BASE . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'system_instruction' => [
            'parts' => [
                ['text' => GEMINI_SYSTEM_INSTRUCTION],
            ],
        ],
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $userPrompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'maxOutputTokens' => 2048,
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Failed to encode Gemini payload.\n");
        exit(1);
    }

    $resp = httpRequest('POST', $url, [
        'Content-Type: application/json',
    ], $json);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $status = $resp['status'];
        $body = sanitizeForLogsWithSecrets($resp['body'], [$apiKey]);
        $safeUrl = preg_replace('/key=[^&]+/i', 'key=[REDACTED]', $url) ?? '[redacted]';
        fwrite(STDERR, "Gemini API error ({$status}) at {$safeUrl}. Body:\n{$body}\n");
        exit(1);
    }

    return $resp['body'];
}

function extractGeminiText(string $geminiResponseJson): string
{
    try {
        $data = json_decode($geminiResponseJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fwrite(STDERR, "Failed to decode Gemini response envelope JSON: {$e->getMessage()}\n");
        exit(1);
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        fwrite(STDERR, "Gemini response missing candidates[0].content.parts[0].text\n");
        exit(1);
    }

    return trim($text);
}

/**
 * @param mixed $review
 * @return string[]
 */
function validateReviewPayload($review): array
{
    $errors = [];

    if (!is_array($review)) {
        return ['Top-level JSON must be an object.'];
    }

    foreach (['summary', 'issues', 'human_readable'] as $key) {
        if (!array_key_exists($key, $review)) {
            $errors[] = "Missing key: {$key}";
        }
    }

    if (!isset($review['summary']) || !is_array($review['summary'])) {
        $errors[] = 'summary must be an object.';
    } else {
        foreach (['critical', 'high', 'medium', 'low'] as $k) {
            if (!array_key_exists($k, $review['summary'])) {
                $errors[] = "summary.{$k} is required.";
                continue;
            }
            $v = $review['summary'][$k];
            if (!is_int($v) && !(is_float($v) && (int) $v === $v)) {
                $errors[] = "summary.{$k} must be a number.";
                continue;
            }
            if ((int) $v < 0) {
                $errors[] = "summary.{$k} must be >= 0.";
            }
        }
    }

    if (!isset($review['issues']) || !is_array($review['issues'])) {
        $errors[] = 'issues must be an array.';
    } else {
        foreach ($review['issues'] as $i => $issue) {
            if (!is_array($issue)) {
                $errors[] = "issues[{$i}] must be an object.";
                continue;
            }

            $severity = $issue['severity'] ?? null;
            if (!is_string($severity) || !in_array($severity, ['critical', 'high', 'medium', 'low'], true)) {
                $errors[] = "issues[{$i}].severity must be one of critical|high|medium|low.";
            }

            $file = $issue['file'] ?? null;
            if (!is_string($file) || trim($file) === '') {
                $errors[] = "issues[{$i}].file must be a non-empty string.";
            }

            $line = $issue['line'] ?? null;
            if (!is_int($line) && !(is_float($line) && (int) $line === $line)) {
                $errors[] = "issues[{$i}].line must be a number.";
            } elseif ((int) $line < 0) {
                $errors[] = "issues[{$i}].line must be >= 0.";
            }

            $message = $issue['message'] ?? null;
            if (!is_string($message) || trim($message) === '') {
                $errors[] = "issues[{$i}].message must be a non-empty string.";
            }

            $suggestion = $issue['suggestion'] ?? null;
            if (!is_string($suggestion) || trim($suggestion) === '') {
                $errors[] = "issues[{$i}].suggestion must be a non-empty string.";
            }
        }
    }

    if (!isset($review['human_readable']) || !is_string($review['human_readable'])) {
        $errors[] = 'human_readable must be a string.';
    }

    $extraKeys = array_diff(array_keys($review), ['summary', 'issues', 'human_readable']);
    if ($extraKeys !== []) {
        $errors[] = 'Unexpected top-level keys: ' . implode(', ', $extraKeys);
    }

    return $errors;
}

function buildCommentBody(string $model, string $humanReadable, int $critical, int $high, int $medium, int $low): string
{
    $header = "**AI Review (Gemini: {$model})**\n\n"
        . "**Summary**: critical={$critical}, high={$high}, medium={$medium}, low={$low}\n\n"
        . "---\n\n";

    $footer = "\n\n---\n\n"
        . "_Generated by ai-review workflow. This comment contains only PR diff analysis._";

    return $header . $humanReadable . $footer;
}

function postPrComment(string $githubToken, string $repo, int $prNumber, string $body): void
{
    $url = GITHUB_API_BASE . '/repos/' . $repo . '/issues/' . $prNumber . '/comments';
    $payload = json_encode(['body' => $body], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        fwrite(STDERR, "Failed to encode GitHub comment payload.\n");
        exit(1);
    }

    $resp = httpRequest('POST', $url, [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $githubToken,
        'User-Agent: ' . USER_AGENT,
        'X-GitHub-Api-Version: 2022-11-28',
        'Content-Type: application/json',
    ], $payload);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $status = $resp['status'];
        $safeBody = sanitizeForLogsWithSecrets($resp['body'], [$githubToken]);
        fwrite(STDERR, "Failed to post PR comment (HTTP {$status}). Body:\n{$safeBody}\n");
        exit(1);
    }
}

function failWithComment(string $githubToken, string $repo, int $prNumber, string $message, ?string $model): void
{
    $modelText = $model !== null ? $model : 'unknown';
    $safeMessage = sanitizeForLogsWithSecrets($message, [$githubToken]);
    $body = "**AI Review pipeline failure (Gemini: {$modelText})**\n\n"
        . "The review step failed and should be treated as a **high severity CI failure**.\n\n"
        . "**Details**:\n\n"
        . "```\n" . trim($safeMessage) . "\n```\n";

    postPrComment($githubToken, $repo, $prNumber, $body);
    exit(1);
}

/**
 * @return array{status:int, body:string, headers:array<string,string>}
 */
function httpRequest(string $method, string $url, array $headers, ?string $body): array
{
    if (!extension_loaded('curl')) {
        fwrite(STDERR, "PHP extension 'curl' is required.\n");
        exit(1);
    }

    $ch = curl_init();
    if ($ch === false) {
        fwrite(STDERR, "Failed to initialize curl.\n");
        exit(1);
    }

    $responseHeaders = [];
    $headerFn = static function ($curl, string $headerLine) use (&$responseHeaders): int {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($name !== '') {
                $responseHeaders[$name] = $value;
            }
        }
        return $len;
    };

    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => $headerFn,
        CURLOPT_TIMEOUT => CURL_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);
    $respBody = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($respBody === false || $errno !== 0) {
        $msg = $error !== '' ? $error : 'Unknown curl error';
        fwrite(STDERR, "HTTP request failed: {$msg}\n");
        exit(1);
    }

    $respBodyStr = (string) $respBody;
    if (strlen($respBodyStr) > MAX_HTTP_RESPONSE_BYTES) {
        $respBodyStr = substr($respBodyStr, 0, MAX_HTTP_RESPONSE_BYTES) . "\n...[truncated]...";
    }

    return [
        'status' => $status,
        'body' => $respBodyStr,
        'headers' => $responseHeaders,
    ];
}

/**
 * @return array{exit_code:int, output:string}
 */
function runCommand(string $command): array
{
    $outputLines = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $outputLines, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => implode("\n", $outputLines),
    ];
}

function sanitizeForLogs(string $text): string
{
    return sanitizeForLogsWithSecrets($text, []);
}

/**
 * @param string[] $secrets
 */
function sanitizeForLogsWithSecrets(string $text, array $secrets): string
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return '[empty]';
    }

    $redacted = redactSecrets($trimmed, $secrets);
    $redacted = preg_replace('/\bBearer\s+([A-Za-z0-9._-]+)\b/i', 'Bearer [REDACTED]', $redacted) ?? $redacted;
    $redacted = preg_replace('/key=([^&\s]+)/i', 'key=[REDACTED]', $redacted) ?? $redacted;

    if (strlen($redacted) > MAX_LOG_BYTES) {
        return substr($redacted, 0, MAX_LOG_BYTES) . "\n...[truncated]...";
    }

    return $redacted;
}

/**
 * @param string[] $secrets
 */
function redactSecrets(string $text, array $secrets): string
{
    $result = $text;
    foreach ($secrets as $secret) {
        $secret = (string) $secret;
        if ($secret === '' || strlen($secret) < 6) {
            continue;
        }

        $result = str_replace($secret, '[REDACTED]', $result);
    }

    return $result;
}

