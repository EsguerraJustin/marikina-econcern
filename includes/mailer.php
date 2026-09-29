<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function _mailer_diag(string $event, array $extra = []): void
{
    $line = json_encode([
        'ts' => date('c'),
        'event' => 'mailer_' . $event,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'php_cli',
    ] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        $line = date('c') . ' mailer_' . $event;
    }
    @error_log($line . PHP_EOL, 3, __DIR__ . '/../app_error.log');
}

function _mailer_brevo_api_exec(array $payload, int $timeoutSec): array
{
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    $json = json_encode($payload);
    if ($json === false) {
        return [
            'ok' => false,
            'curl_errno' => -1,
            'curl_error' => 'Failed to JSON-encode payload',
            'http_status' => 0,
            'raw_response' => '',
            'response' => null,
        ];
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . BREVO_API_KEY,
        'content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $raw = curl_exec($ch);
    $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = (int) curl_errno($ch);
    $cerrmsg = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
    }

    return [
        'ok' => ($cerr === 0 && $http >= 200 && $http < 300),
        'curl_errno' => $cerr,
        'curl_error' => $cerrmsg,
        'http_status' => $http,
        'raw_response' => is_string($raw) ? $raw : '',
        'response' => $decoded,
    ];
}

function _mailer_send_brevo_api(string $to, string $subject, string $htmlBody, string $textBody): array
{
    $toName = '';
    $local = strstr($to, '@', true);
    if (is_string($local) && $local !== '') {
        $toName = ucwords((string) preg_replace('/[^a-zA-Z0-9]/', ' ', $local));
    }
    if (trim($textBody) === '') {
        $textBody = trim(strip_tags((string) preg_replace('/<br\s*\/?\s*>/i', "\n", $htmlBody)));
    }
    $payload = [
        'sender' => ['name' => (string) MAIL_FROM_NAME, 'email' => (string) MAIL_FROM_ADDRESS],
        'to'     => [['email' => $to] + ($toName !== '' ? ['name' => $toName] : [])],
        'replyTo' => ['email' => (string) MAIL_FROM_ADDRESS, 'name' => (string) MAIL_FROM_NAME],
        'subject' => $subject,
        'htmlContent' => $htmlBody,
        'textContent' => $textBody,
        'headers' => [
            'X-Mailer' => 'Marikina-E-Concern',
            'X-Priority' => '3',
        ],
        'tags' => ['marikina-econcern', strtolower((string) preg_replace('/[^a-z0-9_-]/i', '-', $subject))],
    ];

    $result = _mailer_brevo_api_exec($payload, 8);

    $retryable = in_array($result['curl_errno'], [
        CURLE_OPERATION_TIMEDOUT,
        CURLE_SSL_CONNECT_ERROR,
        CURLE_COULDNT_CONNECT,
        CURLE_GOT_NOTHING,
        CURLE_RECV_ERROR,
    ], true);

    if (!$result['ok'] && $retryable) {
        usleep(300000);
        $result = _mailer_brevo_api_exec($payload, 12);
    }

    if ($result['ok']) {
        _mailer_diag('brevo_api_ok', [
            'to' => $to,
            'subject' => $subject,
            'http_status' => $result['http_status'],
            'message_id' => is_array($result['response']) ? ($result['response']['messageId'] ?? '') : '',
        ]);
        return ['ok' => true, 'provider_ref' => is_array($result['response']) ? ($result['response']['messageId'] ?? '') : ''];
    }

    _mailer_diag('brevo_api_failed', [
        'to' => $to,
        'subject' => $subject,
        'http_status' => $result['http_status'],
        'curl_errno' => $result['curl_errno'],
        'curl_error' => $result['curl_error'],
        'raw_response' => mb_substr($result['raw_response'], 0, 2000),
    ]);

    return [
        'ok' => false,
        'error' => 'Brevo API: HTTP ' . $result['http_status']
            . ($result['curl_errno'] !== 0 ? ' | curl_err=' . $result['curl_errno'] . ' ' . $result['curl_error'] : '')
            . ($result['raw_response'] !== '' ? ' | ' . mb_substr($result['raw_response'], 0, 200) : ''),
    ];
}

function _mailer_send_native(string $to, string $subject, string $htmlBody, string $textBody = ''): array
{
    $fromLine = (MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME . ' ' : '') . '<' . MAIL_FROM_ADDRESS . '>';

    $headers = [
        'MIME-Version: 1.0',
        'From: ' . $fromLine,
        'Reply-To: ' . MAIL_FROM_ADDRESS,
        'X-Mailer: PHP/' . phpversion(),
    ];

    $boundary = '----=_Boundary_' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $useHtml = trim($htmlBody) !== '';
    $useText = trim($textBody) !== '';
    if (!$useText) {
        $textBody = trim(strip_tags((string) preg_replace('/<br\s*\/?\s*>/i', "\n", $htmlBody)));
        $useText = true;
    }

    $body = '';
    if ($useHtml && $useText) {
        $body = "This is a multi-part message in MIME format.\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= '--' . $boundary . '--';
    } else {
        $headers[] = 'Content-Type: ' . ($useHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $body = $useHtml ? $htmlBody : $textBody;
    }

    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f ' . MAIL_FROM_ADDRESS);

    if ($ok) {
        _mailer_diag('native_ok', ['to' => $to, 'subject' => $subject]);
        return ['ok' => true];
    }

    $err = error_get_last();
    _mailer_diag('native_failed', ['to' => $to, 'php_err' => $err['message'] ?? 'unknown']);
    return ['ok' => false, 'error' => 'mail() function returned false'];
}

function send_email(string $to, string $subject, string $htmlBody, string $textBody = ''): array
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email address'];
    }

    $apiResult = _mailer_send_brevo_api($to, $subject, $htmlBody, $textBody);
    if ($apiResult['ok']) {
        return $apiResult;
    }

    $phpmailerPath = __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
    $smtpPath = __DIR__ . '/vendor/PHPMailer/SMTP.php';
    $exceptionPath = __DIR__ . '/vendor/PHPMailer/Exception.php';

    if (is_file($phpmailerPath) && is_file($smtpPath) && is_file($exceptionPath)) {
        require_once $exceptionPath;
        require_once $phpmailerPath;
        require_once $smtpPath;

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = MAIL_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_PASSWORD;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = MAIL_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 20;

            $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            if (trim($textBody) !== '') {
                $mail->AltBody = $textBody;
            } else {
                $mail->AltBody = trim(strip_tags((string) preg_replace('/<br\s*\/?\s*>/i', "\n", $htmlBody)));
            }

            $mail->send();
            _mailer_diag('smtp_ok_fallback', ['to' => $to, 'subject' => $subject, 'host' => MAIL_HOST]);
            return ['ok' => true];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $dbg = '';
            if (isset($mail) && method_exists($mail, 'getSMTPInstance')) {
                $smtp = $mail->getSMTPInstance();
                if ($smtp && isset($smtp->getError()['detail'])) {
                    $dbg = print_r($smtp->getError(), true);
                }
            }
            _mailer_diag('smtp_failed_fallback', [
                'to' => $to,
                'host' => MAIL_HOST,
                'port' => MAIL_PORT,
                'exception' => $msg,
                'smtp_detail' => $dbg !== '' ? $dbg : null,
                'hint_fallback' => 'retrying with native mail()',
            ]);
        }
    }

    $fallback = _mailer_send_native($to, $subject, $htmlBody, $textBody);
    if ($fallback['ok']) {
        return $fallback;
    }

    $errors = [];
    if (!empty($apiResult['error'])) {
        $errors[] = $apiResult['error'];
    }
    if (!empty($fallback['error'])) {
        $errors[] = 'Native fallback: ' . $fallback['error'];
    }
    return ['ok' => false, 'error' => implode(' || ', $errors)];
}
