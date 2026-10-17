<?php
/**
 * Student event registration service.
 * Included only by public/register.php.
 *
 * Registration uses the existing users and registrations tables. Student
 * accounts receive an unshared random password hash because the SRS does not
 * define student sign-in; the submitted email receives the QR attachment.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/lib/phpqrcode/QRCode.php';

// Never allow a registration form or its one-time QR credential into browser
// caches, history caches, or referrer headers.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

/** @return array<string, mixed>|null */
function registration_load_config(): ?array
{
    $path = dirname(__DIR__) . '/config/db.php';
    if (!is_file($path)) {
        return null;
    }

    try {
        $config = require $path;
        return is_array($config) ? $config : null;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, mixed>|null $config */
function registration_config_is_ready(?array $config): bool
{
    if ($config === null) {
        return false;
    }

    $required = [
        'registration_privacy_controller',
        'registration_privacy_contact',
        'registration_privacy_lawful_basis',
        'registration_privacy_retention',
        'registration_privacy_rights',
        'registration_privacy_sharing',
        'registration_mail_from_address',
        'registration_mail_from_name',
    ];

    foreach ($required as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
            return false;
        }
        if (str_starts_with($config[$key], 'REPLACE_WITH_')) {
            return false;
        }
        if (preg_match('/[\r\n]/', $config[$key]) === 1) {
            return false;
        }
    }

    return filter_var($config['registration_privacy_contact'], FILTER_VALIDATE_EMAIL) !== false
        && filter_var($config['registration_mail_from_address'], FILTER_VALIDATE_EMAIL) !== false
        && mb_strlen($config['registration_privacy_controller']) <= 255
        && mb_strlen($config['registration_privacy_lawful_basis']) <= 1000
        && mb_strlen($config['registration_privacy_retention']) <= 1000
        && mb_strlen($config['registration_privacy_rights']) <= 1000
        && mb_strlen($config['registration_privacy_sharing']) <= 1000
        && isset($config['registration_retention_days'])
        && is_int($config['registration_retention_days'])
        && $config['registration_retention_days'] > 0
        && $config['registration_retention_days'] <= 36500
        && mb_strlen($config['registration_mail_from_name']) <= 120;
}

/**
 * Build and send the confirmation with an inline/attached SVG QR code.
 * Returns false without logging addresses, message bodies, or token material.
 *
 * @param array{id:int,title:string,event_date:string,event_time:string,location:string,capacity:int} $event
 */
function registration_send_confirmation(
    string $recipient,
    string $studentName,
    array $event,
    string $qrSvg,
    array $config
): bool {
    if (!function_exists('mail')) {
        return false;
    }

    $fromAddress = $config['registration_mail_from_address'];
    $fromName = trim(str_replace(["\r", "\n"], '', $config['registration_mail_from_name']));
    if (!is_string($fromAddress) || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $subject = 'Your Daló event registration confirmation';
    try {
        $relatedBoundary = 'dalo_related_' . bin2hex(random_bytes(18));
        $alternativeBoundary = 'dalo_alternative_' . bin2hex(random_bytes(18));
    } catch (Throwable) {
        return false;
    }
    $safeStudentName = e($studentName);
    $safeEventTitle = e($event['title']);
    $safeDate = e($event['event_date']);
    $safeTime = e($event['event_time']);
    $safeLocation = e($event['location']);

    $plain = "Hello {$studentName},\n\n"
        . "Your registration for {$event['title']} is complete.\n"
        . "Date: {$event['event_date']}\nTime: {$event['event_time']}\n"
        . "Location: {$event['location']}\n\n"
        . "Your QR code is attached as an SVG image. Keep it private and present it to the event facilitator.\n";

    $html = '<!doctype html><html lang="en"><body>'
        . '<p>Hello ' . $safeStudentName . ',</p>'
        . '<p>Your registration for <strong>' . $safeEventTitle . '</strong> is complete.</p>'
        . '<p>Date: ' . $safeDate . '<br>Time: ' . $safeTime . '<br>Location: ' . $safeLocation . '</p>'
        . '<p>Present this QR code to the event facilitator. Keep it private.</p>'
        . '<p><img src="cid:dalo-registration-qr" alt="Your event registration QR code" width="280" height="280"></p>'
        . '<p>The QR code is also attached as an SVG file.</p>'
        . '</body></html>';

    $body = '--' . $relatedBoundary . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . "\"\r\n\r\n"
        . '--' . $alternativeBoundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($plain) . "\r\n"
        . '--' . $alternativeBoundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($html) . "\r\n"
        . '--' . $alternativeBoundary . "--\r\n"
        . '--' . $relatedBoundary . "\r\n"
        . "Content-Type: image/svg+xml; name=\"dalo-registration-qr.svg\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-ID: <dalo-registration-qr>\r\n"
        . "Content-Disposition: inline; filename=\"dalo-registration-qr.svg\"\r\n\r\n"
        . chunk_split(base64_encode($qrSvg))
        . '--' . $relatedBoundary . "--\r\n";

    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . $encodedFromName . ' <' . $fromAddress . '>',
        'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"',
    ];

    try {
        return @mail($recipient, $subject, $body, implode("\r\n", $headers));
    } catch (Throwable) {
        return false;
    }
}

final class RegistrationAlreadyExists extends RuntimeException
{
}

/** @return array{id:int,title:string,event_date:string,event_time:string,location:string,capacity:int,registered_count:int}|null */
function registration_find_event(PDO $pdo, int $eventId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, title, event_date, event_time, location, capacity
           FROM events
          WHERE id = ?'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = ?');
    $count->execute([$eventId]);
    $row['registered_count'] = (int) $count->fetchColumn();
    $row['id'] = (int) $row['id'];
    $row['capacity'] = (int) $row['capacity'];
    return $row;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$eventIdInput = $_GET['event_id'] ?? null;
$eventId = is_string($eventIdInput)
    ? filter_var($eventIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$eventId = $eventId === false ? null : (int) $eventId;

$config = registration_load_config();
$configurationReady = registration_config_is_ready($config);
$registrationUnavailable = !$configurationReady;
if ($registrationUnavailable) {
    http_response_code(503);
}
$event = null;
$errors = [];
$form = ['full_name' => '', 'student_id' => '', 'email' => ''];
$registrationResult = null;

if ($requestMethod === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!empty($_FILES) || stripos($contentType, 'multipart/form-data') === 0) {
        http_response_code(400);
        $errors['_general'] = 'File uploads are not accepted. Submit the registration form without files.';
    } elseif (array_diff(array_keys($_POST), ['_csrf_token', 'full_name', 'student_id', 'email']) !== []) {
        http_response_code(400);
        $errors['_general'] = 'The request contains unsupported fields.';
    } else {
        csrf_verify();
    }

    foreach (['full_name', 'student_id', 'email'] as $field) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }
    $form['email'] = mb_strtolower($form['email']);

    if (!$registrationUnavailable && $eventId !== null && empty($errors)) {
        if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 255
            || preg_match('/[\x00-\x1F\x7F]/u', $form['full_name']) === 1
        ) {
            $errors['full_name'] = 'Enter a valid name of no more than 255 characters.';
        }
        if ($form['student_id'] === '' || mb_strlen($form['student_id']) > 50
            || preg_match('/[\x00-\x1F\x7F]/u', $form['student_id']) === 1
        ) {
            $errors['student_id'] = 'Enter a valid student ID of no more than 50 characters.';
        }
        if ($form['email'] === '' || mb_strlen($form['email']) > 191
            || filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'Enter a valid email address of no more than 191 characters.';
        }
    }

    if (!$registrationUnavailable && $eventId === null && empty($errors)) {
        http_response_code(400);
        $errors['_general'] = 'Choose a valid event registration link.';
    }

    if (!$registrationUnavailable && $eventId !== null && empty($errors)) {
        $pdo = null;
        try {
            $pdo = db();
            $pdo->beginTransaction();

            // Serializing all registrations on the event row makes the
            // capacity count and subsequent INSERT one atomic decision.
            $eventStmt = $pdo->prepare(
                'SELECT id, title, event_date, event_time, location, capacity
                   FROM events
                  WHERE id = ?
                  FOR UPDATE'
            );
            $eventStmt->execute([$eventId]);
            $lockedEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);

            if ($lockedEvent === false) {
                $pdo->rollBack();
                http_response_code(404);
                $errors['_general'] = 'This event is unavailable for registration.';
            } else {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = ?');
                $countStmt->execute([$eventId]);
                $registeredCount = (int) $countStmt->fetchColumn();

                if ($registeredCount >= (int) $lockedEvent['capacity']) {
                    $pdo->rollBack();
                    http_response_code(409);
                    $errors['_general'] = 'Registration for this event is full.';
                } else {
                    $studentStmt = $pdo->prepare(
                        'SELECT id, role, full_name, email, student_id
                           FROM users
                          WHERE email = ? OR student_id = ?
                          FOR UPDATE'
                    );
                    $studentStmt->execute([$form['email'], $form['student_id']]);
                    $matches = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($matches) > 1) {
                        $pdo->rollBack();
                        $errors['_general'] = 'These details cannot be used for registration. Check them or contact the event organizer.';
                    } elseif (count($matches) === 1) {
                        $existing = $matches[0];
                        $sameName = mb_strtolower(trim((string) $existing['full_name']))
                            === mb_strtolower($form['full_name']);
                        $sameEmail = mb_strtolower((string) $existing['email']) === $form['email'];
                        $sameStudentId = mb_strtolower((string) $existing['student_id'])
                            === mb_strtolower($form['student_id']);

                        if ($existing['role'] !== 'student' || !$sameName || !$sameEmail || !$sameStudentId) {
                            $pdo->rollBack();
                            $errors['_general'] = 'These details cannot be used for registration. Check them or contact the event organizer.';
                        } else {
                            $studentUserId = (int) $existing['id'];
                            registration_create_record(
                                $pdo,
                                $eventId,
                                $studentUserId,
                                $form,
                                $lockedEvent,
                                $config,
                                $registrationResult
                            );
                        }
                    } else {
                        // No student authentication flow is specified in the
                        // SRS. Store only a password hash for a random secret
                        // that is never disclosed or retained in plaintext.
                        $unsharedPassword = bin2hex(random_bytes(32));
                        $passwordHash = password_hash($unsharedPassword, PASSWORD_DEFAULT);
                        unset($unsharedPassword);
                        if (!is_string($passwordHash)) {
                            throw new RuntimeException('Could not create a student credential hash.');
                        }

                        $insertUser = $pdo->prepare(
                            'INSERT INTO users (role, full_name, email, password_hash, student_id)
                             VALUES (\'student\', ?, ?, ?, ?)'
                        );
                        $insertUser->execute([
                            $form['full_name'],
                            $form['email'],
                            $passwordHash,
                            $form['student_id'],
                        ]);
                        $studentUserId = (int) $pdo->lastInsertId();

                        registration_create_record(
                            $pdo,
                            $eventId,
                            $studentUserId,
                            $form,
                            $lockedEvent,
                            $config,
                            $registrationResult
                        );
                    }
                }
            }
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RegistrationAlreadyExists) {
                http_response_code(409);
                $errors['_general'] = 'This student is already registered for this event. Contact the event organizer if you need help retrieving the QR code.';
            } else {
                error_log('Student registration failed [' . get_class($exception) . '].');
                http_response_code(500);
                $errors['_general'] = 'Registration could not be completed. Please try again or contact the event organizer.';
            }
            $registrationResult = null;
        }

        if ($registrationResult !== null && $pdo instanceof PDO && !$pdo->inTransaction()) {
            $emailSent = registration_send_confirmation(
                $form['email'],
                $form['full_name'],
                $registrationResult['event'],
                $registrationResult['qr_svg'],
                $config
            );
            $registrationResult['email_sent'] = $emailSent;
        }
    }
}

if ($eventId !== null && !$registrationUnavailable) {
    try {
        $event = registration_find_event(db(), $eventId);
        if ($event === null) {
            http_response_code(404);
        }
    } catch (Throwable $exception) {
        error_log('Registration event lookup failed [' . get_class($exception) . '].');
        http_response_code(500);
        $errors['_general'] = 'The event could not be loaded. Please try again later.';
    }
} elseif ($eventId === null && !$registrationUnavailable && empty($errors)) {
    http_response_code(400);
    $errors['_general'] = 'Choose a valid event registration link.';
}

if ($event !== null && $event['registered_count'] >= $event['capacity']) {
    $event['is_full'] = true;
} elseif ($event !== null) {
    $event['is_full'] = false;
}

/**
 * Insert the registration and generate its QR within the caller's transaction.
 * On a QR hash collision, a new independent random token is generated.
 *
 * @param array{full_name:string,student_id:string,email:string} $form
 * @param array<string, mixed> $event
 * @param array<string, mixed> $config
 * @param array<string, mixed>|null $result
 */
function registration_create_record(
    PDO $pdo,
    int $eventId,
    int $studentUserId,
    array $form,
    array $event,
    array $config,
    ?array &$result
): void {
    $duplicate = $pdo->prepare(
        'SELECT id FROM registrations WHERE event_id = ? AND user_id = ? FOR UPDATE'
    );
    $duplicate->execute([$eventId, $studentUserId]);
    if ($duplicate->fetchColumn() !== false) {
        throw new RegistrationAlreadyExists();
    }

    $insert = $pdo->prepare(
        'INSERT INTO registrations (event_id, user_id, qr_token_hash)
         VALUES (?, ?, ?)'
    );

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $rawToken = random_bytes(32);
        $token = rtrim(strtr(base64_encode($rawToken), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken, true);
        $qrSvg = \splitbrain\phpQRCode\QRCode::svg($token, ['s' => 'qrm']);

        try {
            $insert->execute([$eventId, $studentUserId, $tokenHash]);
            $pdo->commit();
            $result = [
                'event' => [
                    'id' => (int) $event['id'],
                    'title' => (string) $event['title'],
                    'event_date' => (string) $event['event_date'],
                    'event_time' => (string) $event['event_time'],
                    'location' => (string) $event['location'],
                    'capacity' => (int) $event['capacity'],
                ],
                'qr_svg' => $qrSvg,
                'qr_svg_data_uri' => 'data:image/svg+xml;base64,' . base64_encode($qrSvg),
                'email_sent' => false,
            ];
            unset($rawToken, $token, $tokenHash);
            return;
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $driverMessage = (string) ($exception->errorInfo[2] ?? '');
            $isQrHashCollision = $driverCode === 1062
                && str_contains($driverMessage, 'uq_registrations_qr_token_hash');
            unset($rawToken, $token, $tokenHash);
            if ($isQrHashCollision && $attempt < 2) {
                continue;
            }
            throw $exception;
        }
    }

    throw new RuntimeException('QR token collision retry limit reached.');
}

$csrf = csrf_token();

