<?php
/**
 * public/login.php
 *
 * Login page entry point.
 * Processes the form via src/auth.php, then renders the login UI.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

// If already authenticated, redirect to event creation.
if (current_user() !== null) {
    header('Location: /events/create.php', true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Daló</title>
    <meta name="description" content="Sign in to the Daló event management system.">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="auth-page">

<main class="auth-card" role="main">
    <header class="auth-header">
        <h1 class="brand">Daló</h1>
        <p class="auth-subtitle">Sign in to your account</p>
    </header>

    <?php if (!empty($errors)): ?>
    <div class="alert alert--error" role="alert" aria-live="polite">
        <ul class="alert__list">
            <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="/login.php" novalidate class="auth-form">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-group <?= isset($errors[0]) ? 'form-group--error' : '' ?>">
            <label for="email" class="form-label">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                class="form-input"
                value="<?= e($oldEmail) ?>"
                autocomplete="email"
                required
                maxlength="191"
                aria-describedby="<?= !empty($errors) ? 'login-errors' : '' ?>"
            >
        </div>

        <div class="form-group">
            <label for="password" class="form-label">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-input"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit" class="btn btn--primary btn--full">Sign in</button>
    </form>
</main>

</body>
</html>
