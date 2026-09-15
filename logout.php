<?php
/**
 * ==========================================================
 * TRANG ĐĂNG XUẤT (LOGOUT) - NỀN TRẮNG ĐỘC LẬP
 * File: logout.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đang đăng xuất - <?= htmlspecialchars(APP_NAME) ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        body {
            background-color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 20px;
        }

        .logout-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.07);
            padding: 40px 30px;
            text-align: center;
            width: 100%;
            max-width: 400px;
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <div class="mb-3">
            <i class="fa-solid fa-circle-notch fa-spin text-primary fa-3x"></i>
        </div>
        <h4 class="fw-bold text-dark mb-2">Đang đăng xuất...</h4>
    </div>

    <script>
        // Xóa Token JWT khỏi LocalStorage
        localStorage.removeItem('auth_token');

        setTimeout(() => {
            window.location.href = 'login.php';
        }, 600);
    </script>
</body>
</html>
