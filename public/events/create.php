<?php
/**
 * public/events/create.php
 *
 * Organizer event creation form entry point.
 * Business logic (validation, DB insert) lives in src/event_create.php.
 *
 * SRS reference: FR-01 — Event Creation by Organizer
 *
 * This file:
 *  1. Includes the logic layer (which enforces authentication + CSRF).
 *  2. Renders the HTML form with accessible, mobile-friendly markup.
 *  3. Displays per-field validation errors inline.
 *  4. Shows a success flash message after a redirect-after-POST.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/event_create.php';

$user = current_user(); // guaranteed non-null by require_organizer() in logic layer
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event — Daló</title>
    <meta name="description" content="Create a new event for student registration and attendance tracking.">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="app-page">

<header class="topbar" role="banner">
    <span class="topbar__brand">Daló</span>
    <nav class="topbar__nav" aria-label="Account navigation">
        <span class="topbar__user"><?= e($user['full_name']) ?></span>
        <form method="post" action="/logout.php" class="topbar__logout-form">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="btn btn--ghost btn--sm">Sign out</button>
        </form>
    </nav>
</header>

<main class="page-content" role="main">
    <div class="page-header">
        <h1 class="page-title">Create Event</h1>
        <p class="page-subtitle">Fill in the details below to create a new event.</p>
    </div>

    <?php if ($flashSuccess !== null): ?>
    <div class="alert alert--success" role="status" aria-live="polite">
        <?= e($flashSuccess) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors['_general'])): ?>
    <div class="alert alert--error" role="alert" aria-live="polite">
        <?= e($errors['_general']) ?>
    </div>
    <?php endif; ?>

    <form
        method="post"
        action="/events/create.php"
        novalidate
        class="event-form card"
        aria-label="Create event form"
    >
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

        <!-- Title -->
        <div class="form-group <?= isset($errors['title']) ? 'form-group--error' : '' ?>">
            <label for="title" class="form-label">
                Event title <span class="required" aria-hidden="true">*</span>
            </label>
            <input
                type="text"
                id="title"
                name="title"
                class="form-input"
                value="<?= e($form['title']) ?>"
                maxlength="255"
                required
                aria-required="true"
                <?= isset($errors['title']) ? 'aria-describedby="err-title"' : '' ?>
            >
            <?php if (isset($errors['title'])): ?>
            <p id="err-title" class="form-error" role="alert"><?= e($errors['title']) ?></p>
            <?php endif; ?>
        </div>

        <!-- Date and Time (side by side on wider screens) -->
        <div class="form-row">
            <div class="form-group form-group--half <?= isset($errors['event_date']) ? 'form-group--error' : '' ?>">
                <label for="event_date" class="form-label">
                    Date <span class="required" aria-hidden="true">*</span>
                </label>
                <input
                    type="date"
                    id="event_date"
                    name="event_date"
                    class="form-input"
                    value="<?= e($form['event_date']) ?>"
                    required
                    aria-required="true"
                    <?= isset($errors['event_date']) ? 'aria-describedby="err-event_date"' : '' ?>
                >
                <?php if (isset($errors['event_date'])): ?>
                <p id="err-event_date" class="form-error" role="alert"><?= e($errors['event_date']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-group form-group--half <?= isset($errors['event_time']) ? 'form-group--error' : '' ?>">
                <label for="event_time" class="form-label">
                    Time <span class="required" aria-hidden="true">*</span>
                </label>
                <input
                    type="time"
                    id="event_time"
                    name="event_time"
                    class="form-input"
                    value="<?= e($form['event_time']) ?>"
                    required
                    aria-required="true"
                    <?= isset($errors['event_time']) ? 'aria-describedby="err-event_time"' : '' ?>
                >
                <?php if (isset($errors['event_time'])): ?>
                <p id="err-event_time" class="form-error" role="alert"><?= e($errors['event_time']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Location -->
        <div class="form-group <?= isset($errors['location']) ? 'form-group--error' : '' ?>">
            <label for="location" class="form-label">
                Location <span class="required" aria-hidden="true">*</span>
            </label>
            <input
                type="text"
                id="location"
                name="location"
                class="form-input"
                value="<?= e($form['location']) ?>"
                maxlength="500"
                required
                aria-required="true"
                placeholder="Building, room, or venue name"
                <?= isset($errors['location']) ? 'aria-describedby="err-location"' : '' ?>
            >
            <?php if (isset($errors['location'])): ?>
            <p id="err-location" class="form-error" role="alert"><?= e($errors['location']) ?></p>
            <?php endif; ?>
        </div>

        <!-- Capacity -->
        <div class="form-group <?= isset($errors['capacity']) ? 'form-group--error' : '' ?>">
            <label for="capacity" class="form-label">
                Registration capacity <span class="required" aria-hidden="true">*</span>
            </label>
            <input
                type="number"
                id="capacity"
                name="capacity"
                class="form-input form-input--narrow"
                value="<?= e($form['capacity']) ?>"
                min="1"
                step="1"
                required
                aria-required="true"
                placeholder="e.g. 100"
                <?= isset($errors['capacity']) ? 'aria-describedby="err-capacity"' : '' ?>
            >
            <p class="form-hint">Maximum number of students who may register.</p>
            <?php if (isset($errors['capacity'])): ?>
            <p id="err-capacity" class="form-error" role="alert"><?= e($errors['capacity']) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" id="btn-create-event" class="btn btn--primary">
                Create event
            </button>
        </div>

        <p class="form-note">
            <span class="required" aria-hidden="true">*</span> Required fields.
            Registration capacity closure will be enforced when the student
            registration module is available.
        </p>
    </form>
</main>

</body>
</html>
