<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "Script started...\n";

/**
 * AI PR Review POC - single file, no dependencies.
 *
 * Env vars:
 * - GITHUB_TOKEN
 * - OPENAI_API_KEY
 * - OPENAI_MODEL (optional)
 * - GITHUB_REPOSITORY (owner/repo)
 * - PR_NUMBER
 * - CURL_CA_BUNDLE (optional path to CA bundle)
 * - ALLOW_INSECURE_TLS (optional, local testing only)
 */

const BASE_BRANCH_REF = 'origin/main';
const DEFAULT_OPENAI_MODEL = 'gpt-4.1-mini';
const OPENAI_API_BASE = 'https://api.openai.com/v1';
const GITHUB_API_BASE = 'https://api.github.com';
const MAX_DIFF_BYTES = 400_000;
const MAX_AGENTS_BYTES = 50_000;
const MAX_HTTP_RESPONSE_BYTES = 1_000_000;
const MAX_LOG_BYTES = 20_000;
const CURL_TIMEOUT_SECONDS = 120;
const CURL_CONNECT_TIMEOUT_SECONDS = 15;
const USER_AGENT = 'ai-review-php/1.0';

/**
 * MUST embed system instruction inside this script.
 */
const OPENAI_SYSTEM_INSTRUCTION = <<<'PROMPT'
You are a strict code reviewer for CI/CD.

Review ONLY provided git diff.
Follow AGENTS.md rules if provided.

PROMPT;

main();

function main(): void
{
    $dryRun = false;

    // $openAiApiKey = requireEnv('OPENAI_API_KEY');
    // $githubToken = $dryRun ? (string) (getenv('GITHUB_TOKEN') ?: '') : requireEnv('GITHUB_TOKEN');
    // $model = getenv('OPENAI_MODEL');
    // $repo = $dryRun ? (string) (getenv('GITHUB_REPOSITORY') ?: '') : requireEnv('GITHUB_REPOSITORY');
    // $prNumber = $dryRun ? (int) (getenv('PR_NUMBER') ?: 0) : requirePositiveIntEnv('PR_NUMBER');


        $openAiApiKey = getenv('OPENAI_API_KEY');
        $githubToken = (string) getenv('GITHUB_TOKEN');
        $model = getenv('OPENAI_MODEL');
        $repo = getenv('GITHUB_REPOSITORY');
        $prNumber = (int)getenv('PR_NUMBER');


    $openAiModel = $model !== false && trim($model) !== '' ? trim($model) : DEFAULT_OPENAI_MODEL;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $openAiModel)) {
        failPipeline(
            $dryRun,
            $githubToken,
            $repo,
            $prNumber,
            'Invalid OPENAI_MODEL value. Allowed: letters, numbers, ".", "_", "-".',
            $openAiModel
        );
    }

    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
        if (!$dryRun) {
            failPipeline($dryRun, $githubToken, $repo, $prNumber, 'Invalid GITHUB_REPOSITORY format. Expected owner/repo.', null);
        }
    }

    ensureBaseBranchFetched();

    $diff = getGitDiff();
    // echo "See difference" ."\n";
    // echo $diff."\n";
    [$diffForPrompt, $diffTruncated] = truncateBytes($diff, MAX_DIFF_BYTES);
    $diffIndex = buildDiffIndex($diff);

    $agentsRules = readAgentsRules();

    // echo $agentsRules."\n";

    $userPrompt = buildUserPrompt($agentsRules, $diffForPrompt, $diffTruncated);

    $openAiRawResponse = callOpenAi($openAiApiKey, $openAiModel, $userPrompt);
    $reviewJsonText = extractOpenAiText($openAiRawResponse);

    try {
        $review = decodeReviewJsonFromModelText($reviewJsonText);
    } catch (Throwable $e) {
        failPipeline(
            $dryRun,
            $githubToken,
            $repo,
            $prNumber,
            "OpenAI returned non-JSON or invalid JSON. Error: {$e->getMessage()}",
            $openAiModel
        );
        return;
    }

    $validationErrors = validateReviewPayload($review);
    $review = enrichIssuesWithScope($review, $diffIndex);
    $validationErrors = array_merge($validationErrors, validateIssuesAgainstDiff($review, $diffIndex));
    if ($validationErrors !== []) {
        $msg = "OpenAI JSON failed validation:\n- " . implode("\n- ", $validationErrors);
        failPipeline($dryRun, $githubToken, $repo, $prNumber, $msg, $openAiModel);
    }

    $summary = $review['summary'];
    $humanReadable = (string) $review['human_readable'];

    $critical = (int) $summary['critical'];
    $high = (int) $summary['high'];
    $medium = (int) $summary['medium'];
    $low = (int) $summary['low'];

    $commentBody = buildCommentBody($openAiModel, $humanReadable, $critical, $high, $medium, $low);
    if ($dryRun) {
        fwrite(STDOUT, $commentBody . "\n");
    } else {
        postPrComment($githubToken, $repo, $prNumber, $commentBody);
    }

    // if ($critical > 0) {
    //     exit(1);
    // }

    exit(0);
}

function isTruthyEnv(string $name): bool
{
    $value = getenv($name);
    if ($value === false) {
        return false;
    }

    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function failPipeline(bool $dryRun, string $githubToken, string $repo, int $prNumber, string $message, ?string $model): void
{
    if ($dryRun) {
        $modelText = $model !== null ? $model : 'unknown';
        fwrite(STDERR, "AI Review pipeline failure (OpenAI: {$modelText})\n\n");
        fwrite(STDERR, sanitizeForLogsWithSecrets($message, [$githubToken]) . "\n");
        exit(1);
    }

    failWithComment($githubToken, $repo, $prNumber, $message, $model);
}

function requireEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        $message = "Missing required env var: {$name}\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(2);
    }

    return trim($value);
}

function requirePositiveIntEnv(string $name): int
{
    $value = requireEnv($name);
    if (!preg_match('/^[0-9]+$/', $value)) {
        $message = "Invalid {$name}: must be an integer.\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(2);
    }

    $int = (int) $value;
    if ($int <= 0) {
        $message = "Invalid {$name}: must be > 0.\n";
        echo $message;
        fwrite(STDERR, $message);
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
    $cmd = 'git diff --unified=5 ' . escapeshellarg(BASE_BRANCH_REF . '...HEAD');
    $result = runCommand($cmd);

    if ($result['exit_code'] !== 0) {
        $message = "Failed to generate git diff. Output:\n" . sanitizeForLogsWithSecrets($result['output'], []) . "\n";
        echo $message;
        fwrite(STDERR, $message);
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

function callOpenAi(string $apiKey, string $model, string $userPrompt): string
{
    $url = OPENAI_API_BASE . '/responses';

    // $payload = [
    //     'model' => $model,
    //     'input' => [
    //         [
    //             'role' => 'system',
    //             'content' => [
    //                 [
    //                     'type' => 'input_text',
    //                     'text' => OPENAI_SYSTEM_INSTRUCTION,
    //                 ],
    //             ],
    //         ],
    //         [
    //             'role' => 'user',
    //             'content' => [
    //                 [
    //                     'type' => 'input_text',
    //                     'text' => $userPrompt,
    //                 ],
    //             ],
    //         ],
    //     ],
    //     'max_output_tokens' => 2048,
    // ];

    $payload = [
        "model" => $model,
        "input" => [
            ["role" => "system", "content" => OPENAI_SYSTEM_INSTRUCTION],
            ["role" => "user", "content" => $userPrompt]
        ]
    ];


    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $message = "Failed to encode OpenAI payload.\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(1);
    }

    $resp = httpRequest('POST', $url, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $json);

    // echo '<pre/>';print_r($resp);exit;

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $status = $resp['status'];
        $body = sanitizeForLogsWithSecrets($resp['body'], [$apiKey]);
        $message = "OpenAI API error ({$status}) at {$url}. Body:\n{$body}\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(1);
    }

    return $resp['body'];
}

function extractOpenAiText(string $openAiResponseJson): string
{
    try {
        $data = json_decode($openAiResponseJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        $message = "Failed to decode OpenAI response envelope JSON: {$e->getMessage()}\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(1);
    }

    $text = $data['output'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        $text = extractOpenAiTextFromOutputItems($data);
    }
    if (!is_string($text) || trim($text) === '') {
        $text = extractOpenAiTextFromChatChoices($data);
    }
    if (!is_string($text) || trim($text) === '') {
        $text = extractOpenAiTextFromKnownFallbackPaths($data);
    }

    if (!is_string($text) || trim($text) === '') {
        $debug = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $safeDebug = is_string($debug) ? sanitizeForLogsWithSecrets($debug, []) : '[unavailable]';
        $message = "OpenAI response missing text content. Envelope snippet:\n"
            . substr($safeDebug, 0, 4000)
            . "\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(1);
    }

    return trim($text);
}

function extractOpenAiTextFromOutputItems(array $data): ?string
{
    $outputItems = $data['output'] ?? null;
    if (!is_array($outputItems)) {
        return null;
    }

    $chunks = [];
    foreach ($outputItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $contentItems = $item['content'] ?? null;
        if (!is_array($contentItems)) {
            continue;
        }

        foreach ($contentItems as $contentItem) {
            if (!is_array($contentItem)) {
                continue;
            }

            $candidateText = $contentItem['text'] ?? null;
            if (is_string($candidateText) && trim($candidateText) !== '') {
                $chunks[] = trim($candidateText);
            }
        }
    }

    if ($chunks === []) {
        return null;
    }

    return implode("\n", $chunks);
}

function extractOpenAiTextFromChatChoices(array $data): ?string
{
    $choices = $data['choices'] ?? null;
    if (!is_array($choices)) {
        return null;
    }

    $chunks = [];
    foreach ($choices as $choice) {
        if (!is_array($choice)) {
            continue;
        }

        $message = $choice['message'] ?? null;
        if (!is_array($message)) {
            continue;
        }

        $content = $message['content'] ?? null;
        if (is_string($content) && trim($content) !== '') {
            $chunks[] = trim($content);
            continue;
        }

        if (!is_array($content)) {
            continue;
        }

        foreach ($content as $part) {
            if (is_array($part)) {
                $partText = $part['text'] ?? null;
                if (is_string($partText) && trim($partText) !== '') {
                    $chunks[] = trim($partText);
                }
            }
        }
    }

    if ($chunks === []) {
        return null;
    }

    return implode("\n", $chunks);
}

function extractOpenAiTextFromKnownFallbackPaths(array $data): ?string
{
    $chunks = [];

    $responsesContent = $data['response']['content'] ?? null;
    if (is_string($responsesContent) && trim($responsesContent) !== '') {
        $chunks[] = trim($responsesContent);
    }

    $messageContent = $data['message']['content'] ?? null;
    if (is_string($messageContent) && trim($messageContent) !== '') {
        $chunks[] = trim($messageContent);
    }

    $refusal = $data['refusal'] ?? null;
    if (is_string($refusal) && trim($refusal) !== '') {
        $chunks[] = trim($refusal);
    }

    if ($chunks === []) {
        return null;
    }

    return implode("\n", $chunks);
}

/**
 * @return array<string, mixed>
 */
function decodeReviewJsonFromModelText(string $modelText): array
{
    $candidates = [];
    $candidates[] = trim($modelText);

    $withoutFences = stripMarkdownCodeFences($modelText);
    if ($withoutFences !== '') {
        $candidates[] = $withoutFences;
    }

    $objectSlice = extractFirstJsonObject($withoutFences !== '' ? $withoutFences : $modelText);
    if ($objectSlice !== null) {
        $candidates[] = $objectSlice;
    }

    foreach ($candidates as $candidate) {
        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    throw new RuntimeException('Syntax error');
}

function stripMarkdownCodeFences(string $text): string
{
    $trimmed = trim($text);
    if (!str_starts_with($trimmed, '```')) {
        return $trimmed;
    }

    $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
    $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;

    return trim($trimmed);
}

function extractFirstJsonObject(string $text): ?string
{
    $start = strpos($text, '{');
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($text);

    for ($i = $start; $i < $length; $i++) {
        $ch = $text[$i];

        if ($inString) {
            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $escaped = true;
                continue;
            }

            if ($ch === '"') {
                $inString = false;
            }

            continue;
        }

        if ($ch === '"') {
            $inString = true;
            continue;
        }

        if ($ch === '{') {
            $depth++;
            continue;
        }

        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }

    return null;
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
            } elseif ((int) $line <= 0) {
                $errors[] = "issues[{$i}].line must be > 0.";
            }

            $message = $issue['message'] ?? null;
            if (!is_string($message) || trim($message) === '') {
                $errors[] = "issues[{$i}].message must be a non-empty string.";
            } elseif (strlen(trim($message)) < 24) {
                $errors[] = "issues[{$i}].message must be detailed (minimum 24 characters).";
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

/**
 * @return array<string, array{lines: array<int, bool>, scopes: array<int, string>}>
 */
function buildDiffIndex(string $diff): array
{
    $index = [];
    $currentFile = null;
    $newLine = 0;
    $scope = '';

    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, '+++ b/')) {
            $currentFile = substr($line, 6);
            if (!isset($index[$currentFile])) {
                $index[$currentFile] = ['lines' => [], 'scopes' => []];
            }
            continue;
        }

        if (!is_string($currentFile) || $currentFile === '') {
            continue;
        }

        if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@\s*(.*)$/', $line, $m) === 1) {
            $newLine = (int) $m[1];
            $scope = trim((string) ($m[2] ?? ''));
            continue;
        }

        if ($line === '' || $line[0] === '\\') {
            continue;
        }

        $prefix = $line[0];
        if ($prefix === '+') {
            $index[$currentFile]['lines'][$newLine] = true;
            if ($scope !== '') {
                $index[$currentFile]['scopes'][$newLine] = $scope;
            }
            $newLine++;
            continue;
        }

        if ($prefix === ' ') {
            if ($scope !== '') {
                $index[$currentFile]['scopes'][$newLine] = $scope;
            }
            $newLine++;
        }
    }

    return $index;
}

/**
 * @param array<string, mixed> $review
 * @param array<string, array{lines: array<int, bool>, scopes: array<int, string>}> $diffIndex
 * @return array<string, mixed>
 */
function enrichIssuesWithScope(array $review, array $diffIndex): array
{
    if (!isset($review['issues']) || !is_array($review['issues'])) {
        return $review;
    }

    foreach ($review['issues'] as $i => $issue) {
        if (!is_array($issue)) {
            continue;
        }

        $file = (string) ($issue['file'] ?? '');
        $line = (int) ($issue['line'] ?? 0);
        $message = trim((string) ($issue['message'] ?? ''));

        $scope = findNearestScope($diffIndex, $file, $line);
        if ($scope !== '' && $message !== '' && stripos($message, 'scope:') !== 0) {
            $review['issues'][$i]['message'] = "Scope: {$scope}. {$message}";
        }
    }

    return $review;
}

/**
 * @param array<string, array{lines: array<int, bool>, scopes: array<int, string>}> $diffIndex
 */
function findNearestScope(array $diffIndex, string $file, int $line): string
{
    if (!isset($diffIndex[$file]['scopes']) || $line <= 0) {
        return '';
    }

    $scopes = $diffIndex[$file]['scopes'];
    if (isset($scopes[$line])) {
        return $scopes[$line];
    }

    for ($i = $line - 1; $i > max(0, $line - 80); $i--) {
        if (isset($scopes[$i])) {
            return $scopes[$i];
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $review
 * @param array<string, array{lines: array<int, bool>, scopes: array<int, string>}> $diffIndex
 * @return string[]
 */
function validateIssuesAgainstDiff(array $review, array $diffIndex): array
{
    $errors = [];
    if (!isset($review['issues']) || !is_array($review['issues'])) {
        return $errors;
    }

    foreach ($review['issues'] as $i => $issue) {
        if (!is_array($issue)) {
            continue;
        }

        $file = (string) ($issue['file'] ?? '');
        $line = (int) ($issue['line'] ?? 0);

        if (!isset($diffIndex[$file])) {
            $errors[] = "issues[{$i}] references file not found in diff: {$file}";
            continue;
        }

        if (!isset($diffIndex[$file]['lines'][$line])) {
            $errors[] = "issues[{$i}] line {$line} is not an added/changed line in diff for file {$file}";
        }
    }

    return $errors;
}

function buildCommentBody(string $model, string $humanReadable, int $critical, int $high, int $medium, int $low): string
{
    $header = "**AI Review (OpenAI: {$model})**\n\n"
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
        $message = "Failed to encode GitHub comment payload.\n";
        echo $message;
        fwrite(STDERR, $message);
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
        $message = "Failed to post PR comment (HTTP {$status}). Body:\n{$safeBody}\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(1);
    }
}

function failWithComment(string $githubToken, string $repo, int $prNumber, string $message, ?string $model): void
{
    $modelText = $model !== null ? $model : 'unknown';
    $safeMessage = sanitizeForLogsWithSecrets($message, [$githubToken]);
    $body = "**AI Review pipeline failure (OpenAI: {$modelText})**\n\n"
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
        $message = "PHP extension 'curl' is required.\n";
        echo $message;
        fwrite(STDERR, $message);
        exit(1);
    }

    $ch = curl_init();
    if ($ch === false) {
        $message = "Failed to initialize curl.\n";
        echo $message;
        fwrite(STDERR, $message);
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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    // $opts = applyTlsOptions($opts);

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
        $message = "HTTP request failed: {$msg}\n";
        // echo $message;
        fwrite(STDERR, $message);
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
 * @param array<int, mixed> $opts
 * @return array<int, mixed>
 */
function applyTlsOptions(array $opts): array
{
    $caBundle = getenv('CURL_CA_BUNDLE');
    if (is_string($caBundle) && trim($caBundle) !== '') {
        $path = trim($caBundle);
        if (!is_file($path) || !is_readable($path)) {
            $message = "Invalid CURL_CA_BUNDLE path: {$path}\n";
            echo $message;
            fwrite(STDERR, $message);
            exit(2);
        }

        $opts[CURLOPT_CAINFO] = $path;
    }

    if (isTruthyEnv('ALLOW_INSECURE_TLS')) {
        $message = "Warning: ALLOW_INSECURE_TLS=1 disables TLS verification (local testing only).\n";
        echo $message;
        fwrite(STDERR, $message);
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    return $opts;
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

