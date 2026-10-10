<?php
/**
 * src/event_create.php
 *
 * Organizer event creation — business logic.
 * Included by public/events/create.php — never accessed directly.
 *
 * SRS reference: FR-01 (Event Creation by Organizer)
 *
 * Fields accepted (matching events table):
 *   title, event_date, event_time, location, capacity
 *
 * Security rules applied:
 *  • require_organizer() enforces role before any processing.
 *  • csrf_verify() on every POST before touching user input.
 *  • All values bound as parameters via PDO prepared statements.
 *  • Capacity validated > 0 in PHP (database trigger provides a second layer).
 *  • SQL errors are logged server-side; generic messages shown to users.
 *  • No file inputs, upload paths, or multipart handling.
 *
 * Not in scope for this task:
 *  • Registration capacity closure (FR-09) — enforced in the registration module.
 *  • QR generation, scanning, check-in, export, or student-facing pages.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_organizer('/login.php');

$user   = current_user();   // guaranteed non-null after require_organizer()
$errors = [];
$saved  = false;

// Repopulate values after a failed submission.
$form = [
    'title'      => '',
    'event_date' => '',
    'event_time' => '',
    'location'   => '',
    'capacity'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF validation — must happen before any other processing.
    csrf_verify();

    $title      = $_POST['title']      ?? '';
    $event_date = $_POST['event_date'] ?? '';
    $event_time = $_POST['event_time'] ?? '';
    $location   = $_POST['location']   ?? '';
    $capacity   = $_POST['capacity']   ?? '';

    $form['title']      = is_string($title)      ? trim($title)      : '';
    $form['event_date'] = is_string($event_date) ? trim($event_date) : '';
    $form['event_time'] = is_string($event_time) ? trim($event_time) : '';
    $form['location']   = is_string($location)   ? trim($location)   : '';
    $form['capacity']   = is_string($capacity)   ? trim($capacity)   : '';

    // 3. Server-side validation.

    // Title
    if ($form['title'] === '') {
        $errors['title'] = 'Event title is required.';
    } elseif (mb_strlen($form['title']) > 255) {
        $errors['title'] = 'Title must not exceed 255 characters.';
    }

    // Date — must be a valid calendar date.
    if ($form['event_date'] === '') {
        $errors['event_date'] = 'Event date is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['event_date'])
        || !checkdate(
            (int) substr($form['event_date'], 5, 2),
            (int) substr($form['event_date'], 8, 2),
            (int) substr($form['event_date'], 0, 4)
        )
    ) {
        $errors['event_date'] = 'Enter a valid date (YYYY-MM-DD).';
    }

    // Time — HH:MM or HH:MM:SS accepted from time inputs, strictly clock values.
    if ($form['event_time'] === '') {
        $errors['event_time'] = 'Event time is required.';
    } elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $form['event_time'])) {
        $errors['event_time'] = 'Enter a valid time (HH:MM).';
    }

    // Location
    if ($form['location'] === '') {
        $errors['location'] = 'Location is required.';
    } elseif (mb_strlen($form['location']) > 500) {
        $errors['location'] = 'Location must not exceed 500 characters.';
    }

    // Capacity — must be a positive integer.
    if ($form['capacity'] === '') {
        $errors['capacity'] = 'Registration capacity is required.';
    } elseif (!ctype_digit($form['capacity']) || (int) $form['capacity'] < 1) {
        $errors['capacity'] = 'Capacity must be a whole number greater than zero.';
    } elseif ((int) $form['capacity'] > 2147483647) {
        $errors['capacity'] = 'Capacity value is too large.';
    }

    // 4. Persist to the database when validation passes.
    if (empty($errors)) {
        $capacity = (int) $form['capacity'];

        try {
            $stmt = db()->prepare(
                'INSERT INTO events
                    (title, event_date, event_time, location, capacity, organizer_id)
                 VALUES
                    (:title, :event_date, :event_time, :location, :capacity, :organizer_id)'
            );
            $stmt->execute([
                ':title'        => $form['title'],
                ':event_date'   => $form['event_date'],
                ':event_time'   => $form['event_time'],
                ':location'     => $form['location'],
                ':capacity'     => $capacity,
                ':organizer_id' => $user['id'],
            ]);

            $newEventId = (int) db()->lastInsertId();
            $saved      = true;

            // Clear form on success.
            $form = array_fill_keys(array_keys($form), '');

            // Redirect-after-POST to prevent duplicate submissions on refresh.
            $_SESSION['_flash_success'] = 'Event created successfully (ID ' . $newEventId . ').';
            header('Location: /events/create.php', true, 303);
            exit;

        } catch (PDOException $e) {
            error_log('Event insert failed for organizer ' . $user['id'] . ': ' . $e->getMessage());

            // Detect the trigger signal for zero capacity (SQLSTATE 45000).
            if ($e->getCode() === '45000') {
                $errors['capacity'] = 'Capacity must be greater than zero.';
            } else {
                $errors['_general'] = 'The event could not be saved. Please try again.';
            }
        }
    }
}

// Consume any flash message set before a redirect.
$flashSuccess = null;
if (!empty($_SESSION['_flash_success'])) {
    $flashSuccess = $_SESSION['_flash_success'];
    unset($_SESSION['_flash_success']);
}
