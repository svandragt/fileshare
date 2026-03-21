<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fileshare — Login</title>
    <link rel="stylesheet" href="/simple.min.css">
    <style>
        form { display: flex; flex-direction: column; gap: .75rem; }
    </style>
</head>
<body>
    <header><h1>Fileshare</h1></header>
    <main>
        <?php if ($error): ?><p><strong><?= h($error) ?></strong></p><?php endif; ?>
        <?php if ($flash): ?><p><?= h($flash) ?></p><?php endif; ?>
        <form method="post" action="/login">
            <?= csrfField() ?>
            <label>Username
                <input type="text" name="username" required autofocus autocomplete="username">
            </label>
            <label>Password
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit">Log in</button>
        </form>
    </main>
</body>
</html>
