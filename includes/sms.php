<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function _sms_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts' => date('c'),
        'event' => 'sms_' . $event,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'php_cli',
    ] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        $line = date('c') . ' sms_' . $event;
    }
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

function normalize_ph_mobile(string $raw): string|false
{
    $digits = preg_replace('/\D+/', '', $raw);
    if (!is_string($digits)) {
        return false;
    }

    if (str_starts_with($digits, '63') && strlen($digits) === 12) {
        $e164 = '+' . $digits;
    } elseif (str_starts_with($digits, '09') && strlen($digits) === 11) {
        $e164 = '+63' . substr($digits, 1);
    } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
        $e164 = '+63' . $digits;
    } else {
        return false;
    }

    if (!preg_match('/^\+639\d{9}$/', $e164)) {
        return false;
    }

    return $e164;
}

function mask_mobile(string $e164): string
{
    if (strlen($e164) < 7) {
        return '***';
    }
    $prefix = substr($e164, 0, 5);
    $suffix = substr($e164, -4);
    $stars = str_repeat('*', max(2, strlen($e164) - 9));
    return $prefix . $stars . $suffix;
}

function _sms_curl_exec(array $payload, int $timeoutSec): array
{
    if (!function_exists('curl_init')) {
        _sms_diag('curl_missing', ['hint' => 'extension=curl must be enabled in php.ini']);
        return ['ok' => false, 'error' => 'PHP cURL extension is not enabled'];
    }

    $ch = curl_init(SMS_TEXTBEE_ENDPOINT);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($jsonPayload)) {
        $jsonPayload = '{}';
    }

    $headers = [
        'x-api-key: ' . SMS_TEXTBEE_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false,
        CURLOPT_VERBOSE => false,
    ]);

    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_errno($ch);
    $curlErrMsg = $curlErr !== 0 ? curl_error($ch) : '';
    curl_close($ch);

    $decoded = null;
    if (is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
    }

    return [
        'ok' => $curlErr === 0 && $httpStatus >= 200 && $httpStatus < 300,
        'http_status' => $httpStatus,
        'curl_errno' => $curlErr,
        'curl_error' => $curlErrMsg,
        'raw_response' => is_string($response) ? $response : null,
        'response' => is_array($decoded) ? $decoded : null,
    ];
}

function send_sms(string $toE164, string $body): array
{
    if (!preg_match('/^\+639\d{9}$/', $toE164)) {
        $normalized = normalize_ph_mobile($toE164);
        if ($normalized === false) {
            return ['ok' => false, 'error' => 'Invalid PH mobile number format. Use 09xxxxxxxxx or +639xxxxxxxxx.'];
        }
        $toE164 = $normalized;
    }

    if (trim($body) === '') {
        return ['ok' => false, 'error' => 'SMS body cannot be empty'];
    }

    if (strlen($body) > 1600) {
        return ['ok' => false, 'error' => 'SMS body exceeds maximum length'];
    }

    $payload = [
        'recipients' => [$toE164],
        'message' => $body,
    ];
    if (defined('SMS_TEXTBEE_DEVICE_ID') && SMS_TEXTBEE_DEVICE_ID !== '') {
        $payload['deviceId'] = SMS_TEXTBEE_DEVICE_ID;
    }

    $maskedDest = mask_mobile($toE164);
    _sms_diag('send_attempt', [
        'to_masked' => $maskedDest,
        'len' => strlen($body),
        'endpoint' => SMS_TEXTBEE_ENDPOINT,
        'has_device' => isset($payload['deviceId']) ? 1 : 0,
    ]);

    $result = _sms_curl_exec($payload, 5);

    $retryCodes = [CURLE_OPERATION_TIMEDOUT, CURLE_SSL_CONNECT_ERROR, CURLE_COULDNT_CONNECT, CURLE_GOT_NOTHING, CURLE_RECV_ERROR];
    if (!$result['ok'] && isset($result['curl_errno']) && in_array((int) $result['curl_errno'], $retryCodes, true)) {
        _sms_diag('send_retry', [
            'to_masked' => $maskedDest,
            'curl_errno' => $result['curl_errno'],
            'curl_error' => $result['curl_error'] ?? '',
        ]);
        usleep(300000);
        $result = _sms_curl_exec($payload, 8);
    }

    $logPayload = [
        'to_masked' => $maskedDest,
        'http_status' => $result['http_status'] ?? null,
        'curl_errno' => $result['curl_errno'] ?? null,
        'curl_error' => $result['curl_error'] ?? null,
        'response_body_len' => isset($result['raw_response']) ? strlen($result['raw_response']) : 0,
    ];
    if (isset($result['response']) && is_array($result['response'])) {
        $providerRef = null;
        foreach (['id', 'message_id', 'messageId', 'ref', 'reference', 'send_id'] as $k) {
            if (isset($result['response'][$k]) && is_scalar($result['response'][$k])) {
                $providerRef = (string) $result['response'][$k];
                break;
            }
        }
        if ($providerRef !== null) {
            $logPayload['provider_ref'] = $providerRef;
        }
    }

    if ($result['ok']) {
        _sms_diag('send_ok', $logPayload);
        return [
            'ok' => true,
            'provider_ref' => $logPayload['provider_ref'] ?? null,
            'http_status' => $result['http_status'] ?? 200,
        ];
    }

    $errorMsg = $result['curl_error'] ?? '';
    if ($errorMsg === '' && isset($result['response']) && is_array($result['response'])) {
        foreach (['error', 'message', 'detail', 'errors'] as $k) {
            if (isset($result['response'][$k])) {
                if (is_string($result['response'][$k])) {
                    $errorMsg = $result['response'][$k];
                    break;
                }
                if (is_array($result['response'][$k])) {
                    $first = reset($result['response'][$k]);
                    if (is_string($first)) {
                        $errorMsg = $first;
                        break;
                    }
                }
            }
        }
    }
    if ($errorMsg === '') {
        $errorMsg = 'HTTP ' . ($result['http_status'] ?? 0);
    }

    $logPayload['error'] = $errorMsg;
    if (isset($result['raw_response']) && strlen((string) $result['raw_response']) > 0 && strlen((string) $result['raw_response']) <= 1000) {
        $logPayload['raw_response'] = (string) $result['raw_response'];
    }
    _sms_diag('send_failed', $logPayload);

    return [
        'ok' => false,
        'error' => $errorMsg,
        'http_status' => $result['http_status'] ?? 0,
    ];
}
