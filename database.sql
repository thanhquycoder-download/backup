-- ==========================================================
-- HỆ THỐNG CƠ SỞ DỮ LIỆU THANHQUYTECH
-- File: database.sql
-- Chuẩn hóa: UTF-8 Unicode, InnoDB, Liên kết UUIDv7 (Foreign Keys)
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `thanhquytech_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `thanhquytech_db`;

-- Vô hiệu hóa kiểm tra khóa ngoại tạm thời để xóa sạch các bảng cũ bị lệch cấu trúc (nếu có)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `referral_claims`;
DROP TABLE IF EXISTS `referrals`;
DROP TABLE IF EXISTS `key_orders`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `rankings`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `platforms`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------
-- 1. Bảng: users (Quản lý tài khoản người dùng)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `uid` INT UNSIGNED NOT NULL UNIQUE COMMENT 'Mã định danh riêng biệt (7 chữ số: 1000000 - 9999999)',
    `uuid` CHAR(36) NOT NULL UNIQUE COMMENT 'Định danh duy nhất chuẩn UUIDv7 liên kết giữa các bảng',
    `name` VARCHAR(100) NOT NULL COMMENT 'Tên hiển thị người dùng',
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Tên đăng nhập (@tên)',
    `password` VARCHAR(255) NOT NULL COMMENT 'Mật khẩu mã hóa Hash + Salt + Stretching (BCRYPT / Argon2ID)',
    `email` VARCHAR(191) NOT NULL UNIQUE COMMENT 'Địa chỉ email duy nhất',
    `balance` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Số dư tài khoản nạp vào (VND)',
    `avatar` VARCHAR(255) NOT NULL DEFAULT 'assets/images/default-avatar.svg' COMMENT 'Đường dẫn ảnh đại diện',
    `role` ENUM('Admin', 'Member') NOT NULL DEFAULT 'Member' COMMENT 'Vai trò người dùng trong hệ thống',
    `status` ENUM('Active', 'Inactive', 'Locked') NOT NULL DEFAULT 'Active' COMMENT 'Trạng thái hoạt động của tài khoản',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo tài khoản',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất',
    
    INDEX `idx_users_uid` (`uid`),
    INDEX `idx_users_uuid` (`uuid`),
    INDEX `idx_users_username` (`username`),
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. Bảng: password_resets (Quản lý yêu cầu đặt lại mật khẩu)
-- Liên kết khóa ngoại với users.uuid và users.email
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Liên kết khóa ngoại tới users.uuid',
    `email` VARCHAR(191) NOT NULL COMMENT 'Liên kết khóa ngoại tới users.email',
    `token` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Mã Token khôi phục mật khẩu ngẫu nhiên',
    `expires_at` DATETIME NOT NULL COMMENT 'Thời hạn hết hạn của Token',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo yêu cầu',
    
    INDEX `idx_reset_user_uuid` (`user_uuid`),
    INDEX `idx_reset_email` (`email`),
    INDEX `idx_reset_token` (`token`),
    CONSTRAINT `fk_password_resets_user_uuid` 
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_password_resets_email` 
        FOREIGN KEY (`email`) REFERENCES `users` (`email`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. Bảng: platforms (Quản lý các nền tảng: Golike, TTC, TDS...)
-- Có chức năng bật/tắt (Active/Inactive) bởi Admin
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `platforms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã định danh nền tảng (golike, tuongtaccheo...)',
    `name` VARCHAR(100) NOT NULL COMMENT 'Tên hiển thị nền tảng',
    `icon` VARCHAR(50) NOT NULL DEFAULT 'fa-bolt' COMMENT 'Icon FontAwesome',
    `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' COMMENT 'Admin bật/tắt trạng thái',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. Bảng: rankings (Lưu trữ sản lượng / doanh số đua top người dùng)
-- Liên kết khóa ngoại với users.uuid và platforms.id
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rankings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Liên kết bảng users.uuid (chuẩn UUIDv7)',
    `platform_id` INT NOT NULL COMMENT 'Liên kết bảng platforms.id',
    `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Doanh thu / số tiền kiếm được',
    `points` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số tác vụ / điểm số tích lũy',
    `date` DATE NOT NULL COMMENT 'Ngày ghi nhận sản lượng',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_ranking_date` (`date`),
    INDEX `idx_ranking_user_uuid` (`user_uuid`),
    INDEX `idx_ranking_platform` (`platform_id`),
    UNIQUE KEY `uq_user_platform_date` (`user_uuid`, `platform_id`, `date`),
    CONSTRAINT `fk_rankings_user_uuid` 
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rankings_platform_id` 
        FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. Bảng: transactions (Lịch sử nạp tiền & biến động số dư)
-- Liên kết khóa ngoại với users.uuid
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Liên kết bảng users.uuid',
    `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã giao dịch duy nhất (VD: NAP260901, PAY...)',
    `type` ENUM('Deposit', 'Withdraw', 'Payment', 'Refund') NOT NULL DEFAULT 'Deposit' COMMENT 'Loại giao dịch',
    `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Số tiền biến động (VND)',
    `balance_before` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Số dư tài khoản trước khi giao dịch',
    `balance_after` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Số dư tài khoản sau khi giao dịch',
    `status` ENUM('Pending', 'Success', 'Failed', 'Cancelled') NOT NULL DEFAULT 'Success' COMMENT 'Trạng thái giao dịch',
    `note` VARCHAR(255) DEFAULT NULL COMMENT 'Nội dung chi tiết giao dịch',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_trans_user_uuid` (`user_uuid`),
    INDEX `idx_trans_code` (`code`),
    INDEX `idx_trans_status` (`status`),
    INDEX `idx_trans_created_at` (`created_at`),
    CONSTRAINT `fk_transactions_user_uuid` 
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 6. Bảng: key_orders (Lịch sử đơn hàng mua bản quyền Key & Combo Cloud)
-- Liên kết khóa ngoại với users.uuid
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `key_orders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Liên kết bảng users.uuid',
    `order_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã đơn hàng (#ORD-XXXXXX)',
    `package_type` ENUM('key_only', 'combo', 'cloud_only') NOT NULL DEFAULT 'key_only' COMMENT 'Loại gói (key_only, combo hoặc cloud_only)',
    `package_name` VARCHAR(100) NOT NULL COMMENT 'Tên gói dịch vụ',
    `duration_days` INT UNSIGNED NOT NULL COMMENT 'Số ngày sử dụng (1, 3, 7, 30, 90)',
    `license_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Chuỗi mã Key bản quyền kích hoạt',
    `cloud_server` VARCHAR(100) DEFAULT NULL COMMENT 'Máy chủ Cloud treo ngầm (cho gói Combo)',
    `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Số tiền thanh toán (VND)',
    `status` ENUM('Active', 'Expired') NOT NULL DEFAULT 'Active' COMMENT 'Trạng thái bản quyền',
    `expires_at` DATETIME NOT NULL COMMENT 'Thời hạn hết hạn của Key',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm mua đơn hàng',
    
    INDEX `idx_ko_user_uuid` (`user_uuid`),
    INDEX `idx_ko_order_code` (`order_code`),
    INDEX `idx_ko_license_key` (`license_key`),
    INDEX `idx_ko_status` (`status`),
    INDEX `idx_ko_expires_at` (`expires_at`),
    CONSTRAINT `fk_key_orders_user_uuid`
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 7. Bảng: referrals (Quản lý liên kết người giới thiệu & trạng thái nhận Key VIP)
-- Quy tắc: 1 người tham gia = thưởng 1 ngày Key VIP
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referrals` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `referrer_uuid` CHAR(36) NOT NULL COMMENT 'Người giới thiệu (users.uuid)',
    `referee_uuid` CHAR(36) NOT NULL UNIQUE COMMENT 'Người được giới thiệu (users.uuid - mỗi người chỉ có 1 người giới thiệu)',
    `reward_days` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Số ngày thưởng Key VIP cho mỗi người (mặc định 1 ngày)',
    `is_claimed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0: Chưa quy đổi Key, 1: Đã quy đổi nhận Key',
    `claimed_at` DATETIME DEFAULT NULL COMMENT 'Thời điểm quy đổi Key',
    `claim_order_code` VARCHAR(50) DEFAULT NULL COMMENT 'Mã đơn Key quy đổi liên kết',
    `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' COMMENT 'Trạng thái hoạt động',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm đăng ký qua mã giới thiệu',
    
    INDEX `idx_ref_referrer` (`referrer_uuid`),
    INDEX `idx_ref_referee` (`referee_uuid`),
    INDEX `idx_ref_is_claimed` (`is_claimed`),
    INDEX `idx_ref_status` (`status`),
    CONSTRAINT `fk_referrals_referrer`
        FOREIGN KEY (`referrer_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_referrals_referee`
        FOREIGN KEY (`referee_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 8. Bảng: referral_claims (Lịch sử các đợt quy đổi Key VIP từ bạn bè giới thiệu)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referral_claims` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Người nhận Key VIP (users.uuid)',
    `claim_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã đơn quy đổi (#REF-KEY-XXXXXX)',
    `referred_count` INT UNSIGNED NOT NULL COMMENT 'Số lượng bạn bè quy đổi đợt này',
    `reward_days` INT UNSIGNED NOT NULL COMMENT 'Tổng số ngày Key VIP nhận được (= referred_count * 1)',
    `license_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Mã Key VIP bản quyền được tạo',
    `expires_at` DATETIME NOT NULL COMMENT 'Thời hạn hết hạn của Key',
    `status` ENUM('Active', 'Expired') NOT NULL DEFAULT 'Active' COMMENT 'Trạng thái Key',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm quy đổi',
    
    INDEX `idx_rc_user_uuid` (`user_uuid`),
    INDEX `idx_rc_claim_code` (`claim_code`),
    INDEX `idx_rc_license_key` (`license_key`),
    INDEX `idx_rc_status` (`status`),
    CONSTRAINT `fk_rc_user_uuid`
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- DỮ LIỆU KHỞI TẠO MẪU (SEED DATA)
-- ==========================================================

-- 1. Nền tảng mẫu
INSERT INTO `platforms` (`id`, `code`, `name`, `icon`, `status`) VALUES
(1, 'golike', 'Golike', 'fa-bolt', 'Active'),
(2, 'tuongtaccheo', 'Tương Tác Chéo', 'fa-share-nodes', 'Active'),
(3, 'traodoisub', 'Trao Đổi Sub', 'fa-arrows-rotate', 'Active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `icon` = VALUES(`icon`);

-- 2. Người dùng mẫu (Admin & Members)
-- Mật khẩu mặc định:
-- + Admin (@admin): Admin@123456
-- + Members (@thanhquy, @hoangnam, @minhanh): User@123456
-- Hệ thống tự động băm bảo mật Stretched Hash + Pepper khi đăng nhập lần đầu
INSERT INTO `users` (`id`, `uid`, `uuid`, `name`, `username`, `password`, `email`, `balance`, `avatar`, `role`, `status`) VALUES
(1, 1000001, '0191eb50-0001-7000-8000-000000000001', 'Quản Trị Viên', '@admin', 'Admin@123456', 'admin@thanhquytech.vn', 5000000.00, 'assets/images/default-avatar.svg', 'Admin', 'Active'),
(2, 1000002, '0191eb50-0002-7000-8000-000000000002', 'Trần Thanh Quý', '@thanhquy', 'User@123456', 'thanhquy@gmail.com', 1500000.00, 'assets/images/default-avatar.svg', 'Member', 'Active'),
(3, 1000003, '0191eb50-0003-7000-8000-000000000003', 'Nguyễn Hoàng Nam', '@hoangnam', 'User@123456', 'hoangnam@gmail.com', 850000.00, 'assets/images/default-avatar.svg', 'Member', 'Active'),
(4, 1000004, '0191eb50-0004-7000-8000-000000000004', 'Lê Minh Anh', '@minhanh', 'User@123456', 'minhanh@gmail.com', 320000.00, 'assets/images/default-avatar.svg', 'Member', 'Active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `role` = VALUES(`role`), `status` = VALUES(`status`);

-- 3. Dữ liệu bảng xếp hạng mẫu (Rankings) qua UUIDv7 và Platform ID
INSERT INTO `rankings` (`user_uuid`, `platform_id`, `amount`, `points`, `date`) VALUES
-- Hôm nay (CURDATE())
('0191eb50-0002-7000-8000-000000000002', 1, 350000.00, 1420, CURDATE()),
('0191eb50-0003-7000-8000-000000000003', 1, 280000.00, 1150, CURDATE()),
('0191eb50-0004-7000-8000-000000000004', 1, 195000.00, 890, CURDATE()),
('0191eb50-0001-7000-8000-000000000001', 2, 420000.00, 1850, CURDATE()),
('0191eb50-0002-7000-8000-000000000002', 2, 310000.00, 1300, CURDATE()),
('0191eb50-0003-7000-8000-000000000003', 3, 240000.00, 960, CURDATE()),
-- Hôm qua (CURDATE() - INTERVAL 1 DAY)
('0191eb50-0002-7000-8000-000000000002', 1, 410000.00, 1680, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('0191eb50-0003-7000-8000-000000000003', 1, 320000.00, 1290, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('0191eb50-0001-7000-8000-000000000001', 2, 390000.00, 1540, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('0191eb50-0004-7000-8000-000000000004', 3, 210000.00, 880, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
-- 2 ngày trước
('0191eb50-0002-7000-8000-000000000002', 1, 290000.00, 1100, DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
('0191eb50-0001-7000-8000-000000000001', 3, 330000.00, 1320, DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
-- 3 ngày trước
('0191eb50-0003-7000-8000-000000000003', 2, 380000.00, 1490, DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
('0191eb50-0004-7000-8000-000000000004', 1, 260000.00, 1050, DATE_SUB(CURDATE(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE `amount` = VALUES(`amount`), `points` = VALUES(`points`);

-- 4. Dữ liệu giao dịch nạp tiền mẫu (Transactions) qua user_uuid
INSERT INTO `transactions` (`user_uuid`, `code`, `type`, `amount`, `balance_before`, `balance_after`, `status`, `note`) VALUES
('0191eb50-0001-7000-8000-000000000001', 'NAP1000001-01', 'Deposit', 5000000.00, 0.00, 5000000.00, 'Success', 'Nạp tiền tài khoản Quản trị qua Ngân hàng'),
('0191eb50-0002-7000-8000-000000000002', 'NAP1000002-01', 'Deposit', 2000000.00, 0.00, 2000000.00, 'Success', 'Nạp tiền qua chuyển khoản QR Code'),
('0191eb50-0002-7000-8000-000000000002', 'PAY1000002-01', 'Payment', 500000.00, 2000000.00, 1500000.00, 'Success', 'Thanh toán gói dịch vụ hệ thống'),
('0191eb50-0003-7000-8000-000000000003', 'NAP1000003-01', 'Deposit', 850000.00, 0.00, 850000.00, 'Success', 'Nạp số dư tài khoản Member'),
('0191eb50-0004-7000-8000-000000000004', 'NAP1000004-01', 'Deposit', 320000.00, 0.00, 320000.00, 'Success', 'Nạp tiền kích hoạt tài khoản')
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `note` = VALUES(`note`);

-- 5. Dữ liệu quan hệ giới thiệu mẫu (Referrals)
-- User @thanhquy (1000002) giới thiệu User @hoangnam (1000003) và @minhanh (1000004)
INSERT INTO `referrals` (`referrer_uuid`, `referee_uuid`, `commission_rate`, `total_commission`, `status`, `created_at`) VALUES
('0191eb50-0002-7000-8000-000000000002', '0191eb50-0003-7000-8000-000000000003', 10.00, 10020.00, 'Active', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('0191eb50-0002-7000-8000-000000000002', '0191eb50-0004-7000-8000-000000000004', 10.00, 10200.00, 'Active', DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE `total_commission` = VALUES(`total_commission`), `commission_rate` = VALUES(`commission_rate`);

-- 6. Dữ liệu lịch sử hoa hồng mẫu (Referral Commissions)
INSERT INTO `referral_commissions` (`referrer_uuid`, `referee_uuid`, `order_code`, `service_type`, `order_amount`, `commission_rate`, `commission_amount`, `status`, `note`, `created_at`) VALUES
('0191eb50-0002-7000-8000-000000000002', '0191eb50-0003-7000-8000-000000000003', 'ORD-K7D-8921', 'buy_key', 25200.00, 10.00, 2520.00, 'Completed', 'Hoa hồng 10% đơn mua Key 1 Tuần', DATE_SUB(NOW(), INTERVAL 4 DAY)),
('0191eb50-0002-7000-8000-000000000002', '0191eb50-0004-7000-8000-000000000004', 'ORD-COMBO30-4102', 'cloud', 102000.00, 10.00, 10200.00, 'Completed', 'Hoa hồng 10% đơn Combo 1 Tháng', DATE_SUB(NOW(), INTERVAL 2 DAY)),
('0191eb50-0002-7000-8000-000000000002', '0191eb50-0003-7000-8000-000000000003', 'ORD-VPS30-1092', 'cloud', 75000.00, 10.00, 7500.00, 'Completed', 'Hoa hồng 10% đơn thuê Cloud VPS VIP 1 Tháng', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `commission_amount` = VALUES(`commission_amount`), `status` = VALUES(`status`);
