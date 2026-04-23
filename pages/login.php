<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';

    if ($username === '' || $password === '' || $role === '') {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, username, password, full_name, role
             FROM users
             WHERE username = ? AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])
            && strtolower($user['role']) === strtolower($role)
        ) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username, password, or role.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Uncle Brew's – Sign In</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../css/login.css" />
</head>
<body>
<div class="wrap">

    <div class="brand">
    <div class="brand-icon">
        <img src="../images/brew.jpg" alt="Uncle Brew's Logo" />
    </div>
    <div>
        <div class="brand-name">Uncle Brew's</div>
        <div class="brand-sub">Management System</div>
    </div>
</div>

    <div class="card">
        <div class="card-title">Welcome back</div>
        <div class="card-sub">Sign in to continue</div>

        <?php if ($error): ?>
            <div class="error">
                <span>⚠</span>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <!-- Role selection (use case: Admin or Cashier) -->
            <div class="role-tabs">
                <div class="role-tab">
                    <input type="radio" id="role-admin" name="role" value="Admin"
                        <?= (($_POST['role'] ?? 'Admin') === 'Admin' ? 'checked' : '') ?>>
                    <label for="role-admin">
                        <span class="icon">🛠</span> Admin
                    </label>
                </div>
                <div class="role-tab">
                    <input type="radio" id="role-cashier" name="role" value="Cashier"
                        <?= (($_POST['role'] ?? '') === 'Cashier' ? 'checked' : '') ?>>
                    <label for="role-cashier">
                        <span class="icon">🧾</span> Cashier
                    </label>
                </div>
            </div>

            <div class="divider">
                <hr/><span>credentials</span><hr/>
            </div>

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       placeholder="Enter your username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" required />
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="••••••••"
                       autocomplete="current-password" required />
            </div>

            <button type="submit" class="btn">Sign In →</button>
        </form>
    </div>

    

    <p class="hint">Demo: admin / admin123 &nbsp;·&nbsp; cashier / cashier123</p>
</div>
</body>
</html>