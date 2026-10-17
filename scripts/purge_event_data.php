<?php
/**
 * Scheduled retention task for Daló registration and attendance data.
 * Run only from the hosting control panel's trusted PHP CLI scheduler.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = dirname(__DIR__) . '/config/db.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Daló retention task is not configured.\n");
    exit(1);
}

try {
    $config = require $configPath;
    if (!is_array($config)
        || !isset($config['registration_retention_days'])
        || !is_int($config['registration_retention_days'])
        || $config['registration_retention_days'] < 1
    ) {
        throw new RuntimeException('Approved retention days are not configured.');
    }

    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        $config['options']
    );
    $cutoff = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))
        ->modify('-' . $config['registration_retention_days'] . ' days')
        ->format('Y-m-d');

    $pdo->beginTransaction();
    $deleteRegistrations = $pdo->prepare(
        'DELETE registration
           FROM registrations AS registration
           INNER JOIN events AS event ON event.id = registration.event_id
          WHERE event.event_date < ?'
    );
    $deleteRegistrations->execute([$cutoff]);
    $registrationCount = $deleteRegistrations->rowCount();

    // Student rows with no remaining registrations contain no longer-needed
    // event identity data. Keep any student referenced as an event organizer.
    $deleteOrphanStudents = $pdo->prepare(
        'DELETE student
           FROM users AS student
           LEFT JOIN registrations AS registration ON registration.user_id = student.id
           LEFT JOIN events AS event ON event.organizer_id = student.id
          WHERE student.role = \'student\'
            AND student.created_at < ?
            AND registration.id IS NULL
            AND event.id IS NULL'
    );
    $deleteOrphanStudents->execute([$cutoff . ' 00:00:00']);
    $studentCount = $deleteOrphanStudents->rowCount();
    $pdo->commit();

    fwrite(STDOUT, "Daló retention completed. Registrations removed: {$registrationCount}; orphan student records removed: {$studentCount}.\n");
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Daló retention task failed [' . get_class($exception) . '].');
    fwrite(STDERR, "Daló retention task failed. Check the server error log.\n");
    exit(1);
}
