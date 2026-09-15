<?php
/**
 * ==========================================================
 * TRANG ĐĂNG NHẬP (LOGIN) - GIAO DIỆN NỀN TRẮNG ĐỘC LẬP
 * File: login.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/jwt.php';

require_guest();

$errors = [];
$loginInput = '';
$generatedJwtToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng thử lại.';
    }

    $loginInput = trim($_POST['login_identity'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($loginInput)) {
        $errors[] = 'Vui lòng nhập Email hoặc Username.';
    }

    if (empty($password)) {
        $errors[] = 'Vui lòng nhập mật khẩu của bạn.';
    }

    if (empty($errors)) {
        $cleanUsername = ltrim($loginInput, '@');
        $formattedUsername = '@' . $cleanUsername;

        $stmt = $pdo->prepare("
            SELECT id, uid, uuid, name, username, email, password, balance, avatar, role, status, created_at
            FROM users 
            WHERE email = ? OR username = ? OR username = ?
            LIMIT 1
        ");
        $stmt->execute([$loginInput, $formattedUsername, $loginInput]);
        $user = $stmt->fetch();

        if (!$user || !verify_password_stretched($password, $user['password'])) {
            $errors[] = 'Tên đăng nhập / Email hoặc mật khẩu không chính xác.';
        } else {
            if ($user['status'] === 'Locked') {
                $errors[] = 'Tài khoản của bạn đã bị khóa! Vui lòng liên hệ hỗ trợ kỹ thuật.';
            } elseif ($user['status'] === 'Inactive') {
                $errors[] = 'Tài khoản của bạn hiện chưa được kích hoạt.';
            } else {
                // Tự động nâng cấp mật khẩu sang Stretched Hash + Pepper an toàn nếu chưa chuẩn
                $peppered = hash_hmac('sha256', $password, PASSWORD_PEPPER);
                if (!password_verify($peppered, $user['password'])) {
                    $upgradedHash = hash_password_stretched($password);
                    $stmtRehash = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtRehash->execute([$upgradedHash, $user['id']]);
                    $user['password'] = $upgradedHash;
                }

                session_regenerate_id(true);

                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_uid']  = $user['uid'];
                $_SESSION['user_uuid'] = $user['uuid'];
                $_SESSION['user']      = [
                    'id'         => $user['id'],
                    'uid'        => $user['uid'],
                    'uuid'       => $user['uuid'],
                    'name'       => $user['name'],
                    'username'   => $user['username'],
                    'email'      => $user['email'],
                    'balance'    => $user['balance'],
                    'avatar'     => $user['avatar'],
                    'role'       => $user['role'],
                    'status'     => $user['status'],
                    'created_at' => $user['created_at']
                ];

                $generatedJwtToken = jwt_encode([
                    'user_id'  => $user['id'],
                    'uid'      => $user['uid'],
                    'uuid'     => $user['uuid'],
                    'username' => $user['username'],
                    'role'     => $user['role']
                ]);

                set_flash('success', 'Chào mừng <strong>' . htmlspecialchars($user['name']) . '</strong> quay trở lại!', 'Đăng Nhập Thành Công');
            }
        }
    }
}

$csrfToken = get_csrf_token();
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - <?= htmlspecialchars(APP_NAME) ?></title>

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Phong Cách Nền Trắng Sang Trọng (Toàn bộ CSS độc lập trong 1 file) -->
    <style>
        :root {
            --bg-body: #f8fafc;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --accent: #06b6d4;
            --gradient-btn: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #06b6d4 100%);
            --radius-md: 12px;
            --radius-lg: 20px;
            --shadow-card: 0 20px 45px -15px rgba(0, 0, 0, 0.08), 0 2px 10px rgba(0, 0, 0, 0.03);
            --transition: all 0.25s ease-in-out;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            background-color: var(--bg-body) !important;
            color: var(--text-body) !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient Glow Nhẹ Nhàng Nền Sáng */
        .ambient-blob-1 {
            position: fixed;
            top: -15%;
            left: 10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1) 0%, rgba(99, 102, 241, 0) 70%);
            border-radius: 50%;
            filter: blur(60px);
            z-index: 0;
            pointer-events: none;
        }

        .ambient-blob-2 {
            position: fixed;
            bottom: -15%;
            right: 10%;
            width: 550px;
            height: 550px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.09) 0%, rgba(6, 182, 212, 0) 70%);
            border-radius: 50%;
            filter: blur(70px);
            z-index: 0;
            pointer-events: none;
        }

        /* Thẻ Form Chính */
        .auth-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            padding: 42px 36px;
            transition: var(--transition);
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #4f46e5;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .auth-title {
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--text-heading);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .auth-subtitle {
            color: var(--text-muted);
            font-size: 0.92rem;
            margin-bottom: 28px;
        }

        .form-label {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .form-label a {
            color: var(--primary);
            font-size: 0.83rem;
            text-decoration: none;
            font-weight: 600;
        }

        .form-label a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: #94a3b8;
            font-size: 1rem;
            z-index: 2;
            transition: var(--transition);
        }

        .form-control-custom {
            width: 100%;
            height: 48px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            padding: 0 42px 0 44px;
            font-size: 0.94rem;
            color: var(--text-heading);
            outline: none;
            transition: var(--transition);
        }

        .form-control-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
        }

        .form-control-custom:focus + .input-icon,
        .input-group-custom:focus-within .input-icon {
            color: var(--primary);
        }

        .toggle-pwd-btn {
            position: absolute;
            right: 14px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1rem;
            padding: 6px;
            z-index: 2;
            transition: var(--transition);
        }

        .toggle-pwd-btn:hover {
            color: var(--text-heading);
        }

        .btn-gradient {
            width: 100%;
            height: 48px;
            background: var(--gradient-btn);
            color: #ffffff;
            border: none;
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 10px 25px -6px rgba(79, 70, 229, 0.4);
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px -6px rgba(79, 70, 229, 0.55);
            filter: brightness(1.05);
        }

        .btn-gradient:active {
            transform: translateY(0);
        }

        .auth-footer {
            margin-top: 26px;
            text-align: center;
            font-size: 0.88rem;
            color: var(--text-muted);
            border-top: 1px solid #f1f5f9;
            padding-top: 20px;
        }

        .auth-footer a {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
            margin-left: 4px;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        /* ==========================================================
           HỆ THỐNG SVG STROKE DRAW / LINE DRAWING ANIMATION
           ========================================================== */
        .svg-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 20px;
        }

        .svg-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .svg-dialog-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-lg);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            padding: 36px 30px;
            text-align: center;
            transform: scale(0.85) translateY(20px);
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .svg-dialog-overlay.active .svg-dialog-card {
            transform: scale(1) translateY(0);
        }

        .svg-dialog-icon-wrapper {
            margin: 0 auto 20px;
            width: 86px;
            height: 86px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .svg-dialog-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text-heading);
            margin-bottom: 8px;
        }

        .svg-dialog-message {
            font-size: 0.94rem;
            color: var(--text-muted);
            line-height: 1.55;
            margin-bottom: 24px;
        }

        /* SVG Line Drawing Rules */
        .svg-draw-icon {
            width: 84px;
            height: 84px;
            fill: none;
            stroke-width: 4.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* Success Animation */
        .svg-success .svg-circle {
            stroke: #10b981;
            stroke-dasharray: 220;
            stroke-dashoffset: 220;
            animation: strokeCircle 0.6s ease-out forwards;
        }
        .svg-success .svg-check {
            stroke: #10b981;
            stroke-dasharray: 60;
            stroke-dashoffset: 60;
            animation: strokeCheck 0.4s 0.45s ease-out forwards;
        }

        /* Error Animation */
        .svg-error .svg-circle {
            stroke: #ef4444;
            stroke-dasharray: 220;
            stroke-dashoffset: 220;
            animation: strokeCircle 0.6s ease-out forwards;
        }
        .svg-error .line-1 {
            stroke: #ef4444;
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: strokeCross 0.3s 0.45s ease-out forwards;
        }
        .svg-error .line-2 {
            stroke: #ef4444;
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: strokeCross 0.3s 0.6s ease-out forwards;
        }

        /* Warning Animation */
        .svg-warning .svg-triangle {
            stroke: #f59e0b;
            stroke-dasharray: 200;
            stroke-dashoffset: 200;
            animation: strokeTriangle 0.6s ease-out forwards;
        }
        .svg-warning .svg-exclamation-line {
            stroke: #f59e0b;
            stroke-dasharray: 30;
            stroke-dashoffset: 30;
            animation: strokeLine 0.3s 0.45s ease-out forwards;
        }
        .svg-warning .svg-exclamation-dot {
            fill: #f59e0b;
            stroke: none;
            transform: scale(0);
            transform-origin: 40px 56px;
            animation: scaleDot 0.25s 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes strokeCircle { from { stroke-dashoffset: 220; } to { stroke-dashoffset: 0; } }
        @keyframes strokeCheck { from { stroke-dashoffset: 60; } to { stroke-dashoffset: 0; } }
        @keyframes strokeCross { from { stroke-dashoffset: 50; } to { stroke-dashoffset: 0; } }
        @keyframes strokeTriangle { from { stroke-dashoffset: 200; } to { stroke-dashoffset: 0; } }
        @keyframes strokeLine { from { stroke-dashoffset: 30; } to { stroke-dashoffset: 0; } }
        @keyframes scaleDot { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>
    <!-- Background Ambient Glow -->
    <div class="ambient-blob-1"></div>
    <div class="ambient-blob-2"></div>

    <!-- Hộp Thông Báo Tự Động Đăng Nhập JWT -->
    <div id="jwtAutoLoginNotice" style="display: none; position: fixed; top: 24px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 440px;">
        <div class="p-3 bg-white border border-info rounded-3 shadow-lg d-flex align-items-center gap-3">
            <i class="fa-solid fa-spinner fa-spin text-info fa-2x"></i>
            <div>
                <strong class="d-block text-dark">Phát hiện phiên JWT hợp lệ!</strong>
                <small class="text-muted">Đang tự động khôi phục đăng nhập từ LocalStorage...</small>
            </div>
        </div>
    </div>

    <!-- Hộp Thoại Dialog Độc Lập Hiệu Ứng SVG Stroke Draw -->
    <div id="svgDialogOverlay" class="svg-dialog-overlay">
        <div class="svg-dialog-card">
            <div class="svg-dialog-icon-wrapper" id="svgDialogIcon"></div>
            <h3 class="svg-dialog-title" id="svgDialogTitle">Thông báo</h3>
            <div class="svg-dialog-message" id="svgDialogMessage"></div>
            <div id="svgDialogActions">
                <button type="button" class="btn-gradient" style="height: 44px;" id="svgAlertCloseBtn">
                    <i class="fa-solid fa-check me-2"></i> Xác nhận
                </button>
            </div>
        </div>
    </div>

    <div class="auth-card">
        <div class="text-center">
            <div class="brand-badge">
                <i class="fa-solid fa-shield-halved me-1"></i> <?= htmlspecialchars(APP_NAME) ?> Security
            </div>
            <h1 class="auth-title">Đăng nhập</h1>
            <p class="auth-subtitle">Chào mừng bạn quay lại với hệ thống quản trị</p>
        </div>

        <form action="login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- Tên đăng nhập hoặc Email -->
            <div>
                <label for="login_identity" class="form-label">Username hoặc Email</label>
                <div class="input-group-custom">
                    <i class="fa-solid fa-user input-icon"></i>
                    <input type="text" id="login_identity" name="login_identity" class="form-control-custom" 
                           placeholder="user@example.com hoặc nguyenvana" 
                           value="<?= htmlspecialchars($loginInput) ?>" required autofocus>
                </div>
            </div>

            <!-- Mật khẩu -->
            <div>
                <div class="form-label">
                    <label for="password">Mật khẩu</label>
                    <a href="forgot-password.php">Quên mật khẩu?</a>
                </div>
                <div class="input-group-custom">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" class="form-control-custom" 
                           placeholder="Nhập mật khẩu" required>
                    <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('password', this)" title="Ẩn/Hiện">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Ghi nhớ đăng nhập -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <label class="d-flex align-items-center gap-2 text-muted small" style="cursor: pointer;">
                    <input type="checkbox" name="remember" value="1" checked style="accent-color: var(--primary); width: 16px; height: 16px;">
                    <span>Ghi nhớ tôi</span>
                </label>
            </div>

            <button type="submit" class="btn-gradient">
                <span>Đăng nhập</span>
                <i class="fa-solid fa-arrow-right-to-bracket ms-1"></i>
            </button>
        </form>

        <div class="auth-footer">
            Chưa có tài khoản? <a href="register.php"><i class="fa-solid fa-user-plus me-1"></i>Đăng ký ngay</a>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ==========================================================
        // THUẬT TOÁN HIỆU ỨNG "SVG STROKE DRAW ANIMATION" ĐỘC LẬP
        // ==========================================================
        const SVG_TEMPLATES = {
            success: `
                <svg class="svg-draw-icon svg-success" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <polyline class="svg-check" points="24,42 35,53 56,28" />
                </svg>
            `,
            error: `
                <svg class="svg-draw-icon svg-error" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <line class="line-1" x1="26" y1="26" x2="54" y2="54" />
                    <line class="line-2" x1="54" y1="26" x2="26" y2="54" />
                </svg>
            `,
            warning: `
                <svg class="svg-draw-icon svg-warning" viewBox="0 0 80 80">
                    <path class="svg-triangle" d="M40,12 L70,66 L10,66 Z" />
                    <line class="svg-exclamation-line" x1="40" y1="30" x2="40" y2="48" />
                    <circle class="svg-exclamation-dot" cx="40" cy="56" r="3" />
                </svg>
            `
        };

        function showSvgAlert(message, title = 'Thông Báo', type = 'success') {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const closeBtn = document.getElementById('svgAlertCloseBtn');

            iconEl.innerHTML = SVG_TEMPLATES[type] || SVG_TEMPLATES.success;
            titleEl.textContent = title;
            messageEl.innerHTML = message;

            overlay.classList.add('active');

            closeBtn.onclick = () => {
                overlay.classList.remove('active');
            };
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-regular fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa-regular fa-eye';
            }
        }

        // 1. Tự động bật thông báo hiệu ứng SVG Stroke Draw nếu có Flash Message từ trang trước
        <?php if (!empty($flash)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                showSvgAlert(
                    <?= json_encode($flash['message']) ?>,
                    <?= json_encode(!empty($flash['title']) ? $flash['title'] : 'Thông Báo') ?>,
                    <?= json_encode($flash['type']) ?>
                );
            });
        <?php endif; ?>

        // 2. Tự động bật thông báo hiệu ứng SVG Stroke Draw nếu có lỗi Form
        <?php if (!empty($errors)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const errorMsg = `<?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>`;
                showSvgAlert(errorMsg, 'Đăng Nhập Thất Bại', 'error');
            });
        <?php endif; ?>

        // 3. Nếu đăng nhập thành công: Lưu JWT vào LocalStorage nếu tích chọn "Ghi nhớ tôi"
        <?php if (!empty($generatedJwtToken)): ?>
            const jwtToken = <?= json_encode($generatedJwtToken) ?>;
            const rememberMe = <?= !empty($remember) ? 'true' : 'false' ?>;
            if (rememberMe) {
                localStorage.setItem('auth_token', jwtToken);
            } else {
                localStorage.removeItem('auth_token');
            }
            window.location.href = 'index.php';
        <?php else: ?>
            // 4. Nếu mở lại trình duyệt và có sẵn JWT trong LocalStorage: tự động đăng nhập
            document.addEventListener('DOMContentLoaded', () => {
                const storedToken = localStorage.getItem('auth_token');
                if (storedToken) {
                    const noticeEl = document.getElementById('jwtAutoLoginNotice');
                    if (noticeEl) noticeEl.style.display = 'block';

                    fetch('api/verify-token.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + storedToken
                        },
                        body: JSON.stringify({ token: storedToken })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.redirect) {
                            window.location.href = data.redirect;
                        } else {
                            localStorage.removeItem('auth_token');
                            if (noticeEl) noticeEl.style.display = 'none';
                        }
                    })
                    .catch(() => {
                        if (noticeEl) noticeEl.style.display = 'none';
                    });
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>
