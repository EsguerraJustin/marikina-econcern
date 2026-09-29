<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): mysqli
{
    static $mysqli = null;
    static $lastPingAt = 0;

    $now = time();
    $pingInterval = 15;

    if ($mysqli instanceof mysqli) {
        try {
            if ($now - $lastPingAt >= $pingInterval) {
                $alive = @$mysqli->ping();
                $lastPingAt = $now;
                if (!$alive) {
                    throw new mysqli_sql_exception('MySQL server has gone away (ping failed)');
                }
            }

            return $mysqli;
        } catch (Throwable $e) {
            $matches = [];
            $msg = $e->getMessage();
            $goneAway = stripos($msg, 'server has gone away') !== false
                || stripos($msg, 'Lost connection') !== false
                || stripos($msg, 'Broken pipe') !== false
                || stripos($msg, 'Error while reading greeting packet') !== false;

            if (!$goneAway) {
                throw $e;
            }

            @$mysqli->close();
            $mysqli = null;
        }
    }

    $attempts = 0;
    $maxAttempts = 2;
    $lastErr = null;

    while ($attempts < $maxAttempts) {
        $attempts++;
        $mysqli = @mysqli_init();
        if (!$mysqli instanceof mysqli) {
            $lastErr = 'mysqli_init() failed';
            usleep(200000);
            continue;
        }

        // Cap the TCP connect so an unreachable server surfaces in seconds instead of
        // blocking until max_execution_time (120s), which looks like a page that never loads.
        @$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

        // mysqli throws on connect failure (PHP 8.1+ default), so the $connected check
        // below never sees the error. Catch it and fall into the retry path.
        try {
            $connected = @$mysqli->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        } catch (Throwable $e) {
            $connected = false;
            $lastErr = $e->getMessage();
        }
        if (!$connected) {
            $lastErr = $lastErr ?: ($mysqli->connect_error ?: 'real_connect failed');
            @$mysqli->close();
            $mysqli = null;
            usleep(200000);
            continue;
        }

        @$mysqli->set_charset('utf8mb4');
        $lastPingAt = time();
        return $mysqli;
    }

    throw new RuntimeException('Database connection failed after ' . $maxAttempts . ' attempts: ' . ($lastErr ?? 'unknown'));
}

function db_drain(mysqli $mysqli): void
{
    while ($mysqli->more_results() && $mysqli->next_result()) {
        $res = $mysqli->store_result();
        if ($res instanceof mysqli_result) {
            $res->free();
        }
    }
}

function db_prepared_execute(mysqli_stmt $stmt, string $types = '', array $params = []): bool
{
    if ($types !== '' || $params !== []) {
        $typeLen = strlen($types);
        $paramCount = count($params);
        if ($typeLen !== $paramCount) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = isset($backtrace[1]) ? ($backtrace[1]['function'] ?? '{closure}') . '()@' . ($backtrace[1]['line'] ?? '?') : 'unknown';
            @error_log('[db_prepared_execute] bind_param type/param count mismatch at ' . $caller . '; types=' . $typeLen . '(' . $types . ') params=' . $paramCount . PHP_EOL, 3, __DIR__ . '/../app_error.log');
            throw new InvalidArgumentException('db_prepared_execute: type string length (' . $typeLen . ') does not match parameter count (' . $paramCount . ') at ' . $caller . '.');
        }
        $stmt->bind_param($types, ...$params);
    }

    return $stmt->execute();
}

function db_bind_and_execute(mysqli $mysqli, string $sql, string $types = '', array $params = []): mysqli_stmt|false
{
    $stmt = db_prepare($mysqli, $sql);
    if (!($stmt instanceof mysqli_stmt)) return false;
    if ($types !== '' || $params !== []) {
        try {
            db_prepared_execute($stmt, $types, $params);
        } catch (Throwable $e) {
            try { $stmt->close(); } catch (Throwable $_) {}
            throw $e;
        }
    }
    return $stmt;
}

function db_bind_exec_bool(mysqli $mysqli, string $sql, string $types = '', array $params = []): bool
{
    $stmt = db_bind_and_execute($mysqli, $sql, $types, $params);
    if (!($stmt instanceof mysqli_stmt)) return false;
    return true;
}

function db_prepare(mysqli &$mysqli, string $sql, int $maxRetries = 2): mysqli_stmt|false
{
    $attempts = 0;
    $lastErr = null;
    while ($attempts < $maxRetries) {
        $attempts++;
        try {
            @$mysqli->ping();
        } catch (Throwable $e) {
        }
        if (!$mysqli instanceof mysqli || @$mysqli->connect_errno || !@$mysqli->ping()) {
            try {
                $GLOBALS['__db_force_reconnect'] = true;
                $mysqli = db();
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
                usleep(150000);
                continue;
            }
        }
        $stmt = @$mysqli->prepare($sql);
        if ($stmt instanceof mysqli_stmt) {
            return $stmt;
        }
        $err = $mysqli->error ?? 'prepare failed';
        $goneAway = stripos($err, 'server has gone away') !== false
            || stripos($err, 'Lost connection') !== false
            || stripos($err, 'Broken pipe') !== false
            || stripos($err, 'MySQL server has gone away') !== false;
        if (!$goneAway) {
            return false;
        }
        $lastErr = $err;
        @$mysqli->close();
        try {
            $mysqli = db();
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
        usleep(200000);
    }
    return false;
}

