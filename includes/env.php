<?php
declare(strict_types=1);

/**
 * Minimal .env loader — no Composer dependency.
 * Loads C:\xampp\htdocs\Marikina Concern\Marikina Concern\.env (and parent) if present.
 * - Ignores blank lines and # / ; comments
 * - Supports quoted values (" or ')
 * - Does NOT overwrite already-set env (putenv/$_ENV/$_SERVER take precedence)
 */

function env_load_dotenv(?string $baseDir = null): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $dirs = [];
    if (is_string($baseDir) && $baseDir !== '' && is_dir($baseDir)) {
        $dirs[] = rtrim($baseDir, '/\\');
    }
    $dirs[] = dirname(__DIR__); // project root: Marikina Concern/Marikina Concern
    $dirs[] = dirname(dirname(__DIR__)); // outer container

    $envFile = null;
    foreach ($dirs as $dir) {
        $candidate = $dir . DIRECTORY_SEPARATOR . '.env';
        if (is_file($candidate) && is_readable($candidate)) {
            $envFile = $candidate;
            break;
        }
    }
    if ($envFile === null) return;

    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }
        // Strip inline comments not inside quotes (best-effort)
        // Only split on first =
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        if ($key === '') continue;
        // Remove quotes if wrapped
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value)-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                // Unescape only for double quotes
                if ($first === '"') {
                    $value = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $value);
                }
            } else {
                // Strip trailing inline comment outside quotes
                // e.g. FOO=bar # comment  -> bar
                if (preg_match('/\s+#.*$/', $value)) {
                    // Only if # not inside quotes (simple)
                    $hashPos = strpos($value, ' #');
                    if ($hashPos !== false) {
                        $value = trim(substr($value, 0, $hashPos));
                    }
                }
            }
        }

        // Do not overwrite if already set via real env / server
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') continue;
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') continue;
        if (getenv($key) !== false && getenv($key) !== '') continue;

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        @putenv($key . '=' . $value);
    }
}

function env(string $key, mixed $default = null): mixed
{
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    return $default;
}

function env_bool(string $key, bool $default = false): bool
{
    $val = env($key, null);
    if ($val === null) return $default;
    $lower = strtolower(trim((string)$val));
    if (in_array($lower, ['1','true','yes','on','enabled'], true)) return true;
    if (in_array($lower, ['0','false','no','off','disabled',''], true)) return false;
    return $default;
}

// Auto-load on include
env_load_dotenv();
