========================
PHP 8 REVIEW RULES
========================

GENERAL
- Code MUST be compatible with PHP 8+
- Use strict typing wherever possible
- Prefer readability over cleverness
- Avoid dead code, unused variables, and commented-out blocks

------------------------
STRICT TYPES
------------------------
- All PHP files MUST declare:
  declare(strict_types=1);

- Functions MUST use explicit type hints for:
  - parameters
  - return types

❌ Bad:
function getUser($id) { return []; }

✅ Good:
function getUser(int $id): array { return []; }

------------------------
NULL SAFETY
------------------------
- Use nullsafe operator (?->) instead of manual null checks

❌ Bad:
if ($user !== null) {
  $name = $user->getName();
}

✅ Good:
$name = $user?->getName();

------------------------
TYPED PROPERTIES
------------------------
- Class properties MUST have types

❌ Bad:
class User {
  public $name;
}

✅ Good:
class User {
  public string $name;
}

------------------------
CONSTRUCTOR PROPERTY PROMOTION
------------------------
- Use constructor property promotion where applicable

❌ Bad:
class User {
  private string $name;

  public function __construct(string $name) {
    $this->name = $name;
  }
}

✅ Good:
class User {
  public function __construct(private string $name) {}
}

------------------------
MATCH EXPRESSION
------------------------
- Prefer match over switch for strict comparisons

❌ Bad:
switch ($status) {
  case 'active': return 1;
  default: return 0;
}

✅ Good:
return match($status) {
  'active' => 1,
  default => 0,
};

------------------------
ERROR HANDLING
------------------------
- Do NOT suppress errors using @
- Use exceptions instead of returning false/null for errors
- Catch only specific exceptions

❌ Bad:
$result = @file_get_contents($file);

✅ Good:
if (!file_exists($file)) {
  throw new RuntimeException("File not found");
}

------------------------
SECURITY
------------------------
- NEVER trust user input
- Always validate and sanitize input
- Use prepared statements for DB queries
- NEVER concatenate SQL strings directly

❌ Bad:
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];

✅ Good:
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $id]);

- Escape output in HTML context (XSS protection)

------------------------
ARRAYS & ITERATION
------------------------
- Prefer array functions over manual loops when clearer
- Avoid deeply nested loops

------------------------
IMMUTABILITY
------------------------
- Prefer readonly properties where possible

✅ Good:
class User {
  public function __construct(
    public readonly int $id
  ) {}
}

------------------------
DEPENDENCY INJECTION
------------------------
- Avoid creating dependencies inside methods
- Use dependency injection via constructor

❌ Bad:
$mailer = new Mailer();

✅ Good:
public function __construct(private Mailer $mailer) {}

------------------------
LOGGING
------------------------
- Do NOT log sensitive data:
  - passwords
  - tokens
  - secrets

------------------------
PERFORMANCE
------------------------
- Avoid unnecessary database queries inside loops (N+1 problem)
- Cache repeated computations when possible

------------------------
NAMING
------------------------
- Use meaningful variable and function names
- Avoid abbreviations unless widely understood

❌ Bad:
$u = getUsr($i);

✅ Good:
$user = getUser($userId);

------------------------
CONSISTENCY
------------------------
- Follow existing project conventions over introducing new patterns