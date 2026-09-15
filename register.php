<?php
/**
 * ==========================================================
 * TRANG ĐĂNG KÝ TÀI KHOẢN (REGISTER) - GIAO DIỆN NỀN TRẮNG ĐỘC LẬP
 * File: register.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_guest();

$errors = [];
$old = [
    'name'     => '',
    'username' => '',
    'email'    => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Yêu cầu không hợp lệ hoặc đã hết hạn (CSRF Token).';
    }

    $name            = trim($_POST['name'] ?? '');
    $usernameInput   = trim($_POST['username'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $agreeTerms      = isset($_POST['terms']);

    $old['name']     = $name;
    $old['username'] = $usernameInput;
    $old['email']    = $email;

    $cleanUsername = ltrim($usernameInput, '@');
    $formattedUsername = '@' . $cleanUsername;

    if (empty($name) || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors[] = 'Họ và tên phải từ 2 đến 100 ký tự.';
    }

    if (empty($cleanUsername) || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $cleanUsername)) {
        $errors[] = 'Tên đăng nhập chỉ chứa từ 3-30 ký tự chữ cái, số và dấu gạch dưới (_).';
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Địa chỉ email không đúng định dạng.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Mật khẩu phải có độ dài tối thiểu từ 6 ký tự trở lên.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Mật khẩu xác nhận không khớp.';
    }

    if (!$agreeTerms) {
        $errors[] = 'Bạn cần đồng ý với Điều khoản dịch vụ và Chính sách bảo mật.';
    }

    if (empty($errors)) {
        $stmtCheck = $pdo->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmtCheck->execute([$formattedUsername, $email]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            if (strcasecmp($existing['email'], $email) === 0) {
                $errors[] = 'Địa chỉ email này đã được đăng ký tài khoản.';
            }
            if (strcasecmp($existing['username'], $formattedUsername) === 0) {
                $errors[] = 'Tên người dùng này đã có người sử dụng. Vui lòng chọn tên khác.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $uid = generate_unique_uid($pdo);
            $uuid = generate_uuidv7();
            $hashedPassword = hash_password_stretched($password);

            $defaultAvatar = 'assets/images/default-avatar.svg';
            $defaultRole   = 'Member';
            $defaultStatus = 'Active';
            $initialBalance = 0.00;

            $stmtInsert = $pdo->prepare("
                INSERT INTO users (
                    uid, uuid, name, username, password, email, balance, avatar, role, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmtInsert->execute([
                $uid,
                $uuid,
                $name,
                $formattedUsername,
                $hashedPassword,
                $email,
                $initialBalance,
                $defaultAvatar,
                $defaultRole,
                $defaultStatus
            ]);

            set_flash(
                'success', 
                'Chúc mừng bạn đã tạo tài khoản thành công!<br>Mã định danh UID của bạn là: <strong style="color: #4f46e5; font-size: 1.15rem;">#' . $uid . '</strong>.<br>Hãy đăng nhập ngay bên dưới.',
                'Đăng ký thành công'
            );
            header("Location: login.php");
            exit;

        } catch (Exception $e) {
            $errors[] = 'Có lỗi xảy ra khi tạo tài khoản: ' . $e->getMessage();
        }
    }
}

$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản - <?= htmlspecialchars(APP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- CSS Nền Trắng Độc Lập -->
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
            padding: 35px 15px;
            position: relative;
            overflow-x: hidden;
        }

        .ambient-blob-1 {
            position: fixed;
            top: -10%;
            left: 15%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.09) 0%, rgba(99, 102, 241, 0) 70%);
            border-radius: 50%;
            filter: blur(60px);
            z-index: 0;
            pointer-events: none;
        }

        .ambient-blob-2 {
            position: fixed;
            bottom: -15%;
            right: 15%;
            width: 550px;
            height: 550px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.08) 0%, rgba(6, 182, 212, 0) 70%);
            border-radius: 50%;
            filter: blur(70px);
            z-index: 0;
            pointer-events: none;
        }

        .auth-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 540px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            padding: 42px 38px;
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
            display: block;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
            margin-bottom: 18px;
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

        /* Responsive cột form */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 600px) {
            .form-grid-2 { grid-template-columns: 1fr; gap: 0; }
            .auth-card { padding: 30px 22px; }
        }

        /* SVG Line Drawing Dialog */
        .svg-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 20px;
        }

        .svg-dialog-overlay.active { opacity: 1; visibility: visible; }

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

        .svg-dialog-overlay.active .svg-dialog-card { transform: scale(1) translateY(0); }

        .svg-dialog-icon-wrapper {
            margin: 0 auto 20px;
            width: 86px;
            height: 86px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .svg-draw-icon {
            width: 84px;
            height: 84px;
            fill: none;
            stroke-width: 4.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

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

        @keyframes strokeCircle { from { stroke-dashoffset: 220; } to { stroke-dashoffset: 0; } }
        @keyframes strokeCross { from { stroke-dashoffset: 50; } to { stroke-dashoffset: 0; } }
    </style>
</head>
<body>
    <div class="ambient-blob-1"></div>
    <div class="ambient-blob-2"></div>

    <!-- Hộp Thoại Dialog SVG Stroke Draw -->
    <div id="svgDialogOverlay" class="svg-dialog-overlay">
        <div class="svg-dialog-card">
            <div class="svg-dialog-icon-wrapper" id="svgDialogIcon"></div>
            <h3 class="fw-bold mb-2 text-dark" id="svgDialogTitle">Thông báo</h3>
            <div class="text-muted mb-4 small" id="svgDialogMessage"></div>
            <button type="button" class="btn-gradient" style="height: 44px;" id="svgAlertCloseBtn">
                <i class="fa-solid fa-check me-2"></i> Xác nhận
            </button>
        </div>
    </div>

    <div class="auth-card">
        <div class="text-center">
            <div class="brand-badge">
                <i class="fa-solid fa-user-plus me-1"></i> <?= htmlspecialchars(APP_NAME) ?> System
            </div>
            <h1 class="auth-title">Đăng ký tài khoản</h1>
            <p class="auth-subtitle">Trở thành thành viên để trải nghiệm hệ thống công nghệ hàng đầu</p>
        </div>

        <form action="register.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-grid-2">
                <!-- Họ và tên -->
                <div>
                    <label for="name" class="form-label">Họ và tên</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-id-card input-icon"></i>
                        <input type="text" id="name" name="name" class="form-control-custom" 
                               placeholder="Nguyễn Văn A" 
                               value="<?= htmlspecialchars($old['name']) ?>" required>
                    </div>
                </div>

                <!-- Tên đăng nhập (@username) -->
                <div>
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-at input-icon"></i>
                        <input type="text" id="username" name="username" class="form-control-custom" 
                               placeholder="nguyenvana" 
                               value="<?= htmlspecialchars($old['username']) ?>" required>
                    </div>
                </div>
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="form-label">Email</label>
                <div class="input-group-custom">
                    <i class="fa-solid fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email" class="form-control-custom" 
                           placeholder="name@example.com" 
                           value="<?= htmlspecialchars($old['email']) ?>" required>
                </div>
            </div>

            <div class="form-grid-2">
                <!-- Mật khẩu -->
                <div>
                    <label for="password" class="form-label">Mật khẩu</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" class="form-control-custom" 
                               placeholder="Tối thiểu 6 ký tự" required>
                        <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('password', this)">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Xác nhận mật khẩu -->
                <div>
                    <label for="confirm_password" class="form-label">Xác nhận mật khẩu</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-key input-icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control-custom" 
                               placeholder="Nhập lại mật khẩu" required>
                        <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('confirm_password', this)">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Điều khoản -->
            <div class="mb-4">
                <label class="d-flex align-items-center gap-2 text-muted small" style="cursor: pointer;">
                    <input type="checkbox" name="terms" value="1" required checked style="accent-color: var(--primary); width: 16px; height: 16px;">
                    <span>Tôi đồng ý với <a href="#" class="text-primary fw-semibold text-decoration-none">Điều khoản dịch vụ</a> và <a href="#" class="text-primary fw-semibold text-decoration-none">Chính sách bảo mật</a></span>
                </label>
            </div>

            <button type="submit" class="btn-gradient">
                <span>Tạo tài khoản ngay</span>
                <i class="fa-solid fa-user-check ms-1"></i>
            </button>
        </form>

        <div class="auth-footer">
            Đã có tài khoản? <a href="login.php"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i>Đăng nhập tại đây</a>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const SVG_TEMPLATES = {
            error: `
                <svg class="svg-draw-icon svg-error" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <line class="line-1" x1="26" y1="26" x2="54" y2="54" />
                    <line class="line-2" x1="54" y1="26" x2="26" y2="54" />
                </svg>
            `
        };

        function showSvgAlert(message, title = 'Thông Báo') {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const closeBtn = document.getElementById('svgAlertCloseBtn');

            iconEl.innerHTML = SVG_TEMPLATES.error;
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

        // Tự động bật hộp thoại vẽ viền SVG nếu có lỗi kiểm tra dữ liệu
        <?php if (!empty($errors)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const errorMsg = `<?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>`;
                showSvgAlert(errorMsg, 'Đăng Ký Chưa Hợp Lệ');
            });
        <?php endif; ?>
    </script>
</body>
</html>
