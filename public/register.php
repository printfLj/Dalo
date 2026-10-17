<?php
/**
 * Student event registration and one-time QR delivery page.
 * SRS references: FR-02, FR-03, FR-04, FR-09, and FR-11.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/registration.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title><?= $registrationResult !== null ? 'Registration complete' : 'Event registration' ?> — Daló</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="app-page">
<header class="topbar" role="banner">
    <a class="topbar__brand" href="/">Daló</a>
    <span class="topbar__user">Student event registration</span>
</header>

<main class="page-content" role="main">
    <?php if ($registrationUnavailable): ?>
        <div class="page-header">
            <h1 class="page-title">Registration unavailable</h1>
            <p class="page-subtitle">The university privacy notice or email delivery settings are not ready. No registration details were collected. Contact the project administrator.</p>
        </div>
    <?php elseif ($registrationResult !== null): ?>
        <div class="page-header">
            <h1 class="page-title">Registration complete</h1>
            <p class="page-subtitle">Your registration for <?= e($registrationResult['event']['title']) ?> has been recorded.</p>
        </div>

        <section class="card qr-result" aria-labelledby="qr-heading">
            <h2 id="qr-heading" class="section-title">Your event QR code</h2>
            <p>Download this QR code and keep it private. Present it to the facilitator at the event entrance.</p>
            <img
                class="registration-qr"
                src="<?= e($registrationResult['qr_svg_data_uri']) ?>"
                alt="Your unique event registration QR code"
                width="280"
                height="280"
            >
            <a class="btn btn--primary" href="<?= e($registrationResult['qr_svg_data_uri']) ?>" download="dalo-registration-qr.svg">Download QR code</a>
            <?php if ($registrationResult['email_sent']): ?>
                <p class="alert alert--success" role="status">The email system accepted a confirmation with your QR code for delivery to the address you provided.</p>
            <?php else: ?>
                <p class="alert alert--warning" role="status">Your registration is saved, but email delivery could not be confirmed. Download your QR code now; if you lose it, contact the event organizer.</p>
            <?php endif; ?>
            <dl class="event-facts">
                <div><dt>Event</dt><dd><?= e($registrationResult['event']['title']) ?></dd></div>
                <div><dt>Date</dt><dd><?= e($registrationResult['event']['event_date']) ?></dd></div>
                <div><dt>Time</dt><dd><?= e($registrationResult['event']['event_time']) ?></dd></div>
                <div><dt>Location</dt><dd><?= e($registrationResult['event']['location']) ?></dd></div>
            </dl>
        </section>
    <?php else: ?>
        <div class="page-header">
            <h1 class="page-title">Event registration</h1>
            <p class="page-subtitle">Register online. No photo, screenshot, or file upload is needed.</p>
        </div>

        <?php if (!empty($errors['_general'])): ?>
            <div class="alert alert--error" role="alert" aria-live="assertive"><?= e($errors['_general']) ?></div>
        <?php endif; ?>

        <?php if ($event === null): ?>
            <div class="alert alert--error" role="alert">This event registration link is invalid or unavailable.</div>
        <?php else: ?>
            <section class="card event-summary" aria-labelledby="event-title">
                <h2 id="event-title" class="section-title"><?= e($event['title']) ?></h2>
                <dl class="event-facts">
                    <div><dt>Date</dt><dd><?= e($event['event_date']) ?></dd></div>
                    <div><dt>Time</dt><dd><?= e($event['event_time']) ?></dd></div>
                    <div><dt>Location</dt><dd><?= e($event['location']) ?></dd></div>
                    <div><dt>Availability</dt><dd><?= $event['is_full'] ? 'Full' : 'Open' ?></dd></div>
                </dl>
            </section>

            <?php if ($event['is_full']): ?>
                <div class="alert alert--warning" role="status">Registration is closed because this event has reached its capacity.</div>
            <?php else: ?>
                <section class="privacy-notice" aria-labelledby="privacy-heading">
                    <h2 id="privacy-heading" class="section-title">Privacy notice</h2>
                    <p>Read this notice before entering your details.</p>
                    <dl class="privacy-facts">
                        <div><dt>Responsible organization</dt><dd><?= e($config['registration_privacy_controller']) ?></dd></div>
                        <div><dt>Privacy contact</dt><dd><a href="mailto:<?= e($config['registration_privacy_contact']) ?>"><?= e($config['registration_privacy_contact']) ?></a></dd></div>
                        <div><dt>Purpose</dt><dd>Register you for this event, prevent duplicate registrations, verify attendance, and send your QR confirmation.</dd></div>
                        <div><dt>Data collected</dt><dd>Your full name, student ID, email address, event registration, and attendance status.</dd></div>
                        <div><dt>Access and sharing</dt><dd><?= e($config['registration_privacy_sharing']) ?></dd></div>
                        <div><dt>Lawful basis</dt><dd><?= e($config['registration_privacy_lawful_basis']) ?></dd></div>
                        <div><dt>Retention and deletion</dt><dd><?= e($config['registration_privacy_retention']) ?></dd></div>
                        <div><dt>Your rights and requests</dt><dd><?= e($config['registration_privacy_rights']) ?></dd></div>
                    </dl>
                </section>

                <form method="post" action="/register.php?event_id=<?= (int) $event['id'] ?>" class="event-form card" aria-label="Student event registration form" autocomplete="on">
                    <input type="hidden" name="_csrf_token" value="<?= e($csrf) ?>">

                    <div class="form-group <?= isset($errors['full_name']) ? 'form-group--error' : '' ?>">
                        <label for="full_name" class="form-label">Full name <span class="required" aria-hidden="true">*</span></label>
                        <input type="text" id="full_name" name="full_name" class="form-input" value="<?= e($form['full_name']) ?>" maxlength="255" autocomplete="name" required aria-required="true" <?= isset($errors['full_name']) ? 'aria-describedby="err-full-name"' : '' ?>>
                        <?php if (isset($errors['full_name'])): ?><p id="err-full-name" class="form-error" role="alert"><?= e($errors['full_name']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-group <?= isset($errors['student_id']) ? 'form-group--error' : '' ?>">
                        <label for="student_id" class="form-label">Student ID <span class="required" aria-hidden="true">*</span></label>
                        <input type="text" id="student_id" name="student_id" class="form-input" value="<?= e($form['student_id']) ?>" maxlength="50" autocomplete="off" required aria-required="true" <?= isset($errors['student_id']) ? 'aria-describedby="err-student-id"' : '' ?>>
                        <?php if (isset($errors['student_id'])): ?><p id="err-student-id" class="form-error" role="alert"><?= e($errors['student_id']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-group <?= isset($errors['email']) ? 'form-group--error' : '' ?>">
                        <label for="email" class="form-label">Email address <span class="required" aria-hidden="true">*</span></label>
                        <input type="email" id="email" name="email" class="form-input" value="<?= e($form['email']) ?>" maxlength="191" autocomplete="email" required aria-required="true" <?= isset($errors['email']) ? 'aria-describedby="err-email"' : '' ?>>
                        <?php if (isset($errors['email'])): ?><p id="err-email" class="form-error" role="alert"><?= e($errors['email']) ?></p><?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn--primary">Register and get QR code</button>
                    <p class="form-note">Submitting sends a confirmation and QR code to the email address above. This privacy notice describes the university-approved lawful basis; the form does not presume consent is the only lawful basis.</p>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
