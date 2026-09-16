-- ==========================================================
-- HỆ THỐNG CƠ SỞ DỮ LIỆU THANHQUYTECH
-- File: database.sql
-- Chuẩn hóa: UTF-8 Unicode, InnoDB, Liên kết UUIDv7 (Foreign Keys)
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `thanhquytech_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `thanhquytech_db`;

-- Vô hiệu hóa kiểm tra khóa ngoại tạm thời để xóa sạch các bảng cũ bị lệch cấu trúc (nếu có)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `deposits`;
DROP TABLE IF EXISTS `bank_accounts`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `token`;
DROP TABLE IF EXISTS `support_messages`;
DROP TABLE IF EXISTS `support_tickets`;
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

-- ----------------------------------------------------------
-- 9. Bảng: support_tickets (Quản lý các phiếu yêu cầu hỗ trợ)
-- Liên kết khóa ngoại với users.uuid
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Người gửi yêu cầu (users.uuid)',
    `ticket_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã phiếu hỗ trợ (#TK-XXXXXX)',
    `subject` VARCHAR(255) NOT NULL COMMENT 'Tiêu đề vấn đề cần trợ giúp',
    `category` ENUM('Billing', 'LicenseKey', 'CloudServer', 'GolikeTool', 'Account', 'Other') NOT NULL DEFAULT 'Other' COMMENT 'Danh mục hỗ trợ',
    `priority` ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium' COMMENT 'Mức độ ưu tiên',
    `status` ENUM('Pending', 'In Progress', 'Answered', 'Closed') NOT NULL DEFAULT 'Pending' COMMENT 'Trạng thái xử lý',
    `order_code` VARCHAR(50) DEFAULT NULL COMMENT 'Mã đơn hàng/giao dịch liên quan',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_st_user_uuid` (`user_uuid`),
    INDEX `idx_st_ticket_code` (`ticket_code`),
    INDEX `idx_st_status` (`status`),
    INDEX `idx_st_category` (`category`),
    INDEX `idx_st_priority` (`priority`),
    INDEX `idx_st_created_at` (`created_at`),
    CONSTRAINT `fk_support_tickets_user_uuid`
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 10. Bảng: support_messages (Nội dung trao đổi & phản hồi ticket)
-- Liên kết khóa ngoại với support_tickets.id và users.uuid
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL COMMENT 'Liên kết support_tickets.id',
    `sender_uuid` CHAR(36) NOT NULL COMMENT 'Người gửi tin nhắn (users.uuid)',
    `sender_role` ENUM('Member', 'Admin', 'Support') NOT NULL DEFAULT 'Member' COMMENT 'Vai trò người gửi',
    `message` TEXT NOT NULL COMMENT 'Nội dung trao đổi',
    `attachment` VARCHAR(255) DEFAULT NULL COMMENT 'Đường dẫn ảnh/tệp đính kèm',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_sm_ticket_id` (`ticket_id`),
    INDEX `idx_sm_sender_uuid` (`sender_uuid`),
    INDEX `idx_sm_created_at` (`created_at`),
    CONSTRAINT `fk_support_messages_ticket_id`
        FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_support_messages_sender_uuid`
        FOREIGN KEY (`sender_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 11. Bảng: token (Quản lý Access Token / API Key kết nối hệ thống)
-- Liên kết khóa ngoại với users.uuid
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `token` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Liên kết bảng users.uuid',
    `name` VARCHAR(100) NOT NULL COMMENT 'Tên gợi nhớ của Token (VD: Tool Golike VPS 1, Python Automation...)',
    `token` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Chuỗi mã Access Token bảo mật (chuẩn tiền tố tqt_...)',
    `abilities` TEXT NOT NULL COMMENT 'Danh sách quyền hạn JSON (VD: ["all"], ["read"], ["jobs"])',
    `last_used_at` DATETIME DEFAULT NULL COMMENT 'Thời điểm token được sử dụng gần nhất',
    `expires_at` DATETIME DEFAULT NULL COMMENT 'Thời hạn hết hạn (NULL = Không giới hạn / Vĩnh viễn)',
    `status` ENUM('Active', 'Revoked') NOT NULL DEFAULT 'Active' COMMENT 'Trạng thái token',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm khởi tạo token',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất',
    
    INDEX `idx_token_user_uuid` (`user_uuid`),
    INDEX `idx_token_key` (`token`),
    INDEX `idx_token_status` (`status`),
    INDEX `idx_token_expires_at` (`expires_at`),
    CONSTRAINT `fk_token_user_uuid`
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 12. Bảng: settings (Quản lý cấu hình tài khoản cá nhân & hệ thống)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `user_uuid` CHAR(36) DEFAULT NULL COMMENT 'Liên kết bảng users.uuid (NULL nếu là cấu hình chung toàn hệ thống)',
    `setting_key` VARCHAR(100) NOT NULL COMMENT 'Mã định danh cấu hình (key)',
    `setting_value` LONGTEXT DEFAULT NULL COMMENT 'Giá trị cấu hình (chuỗi, số hoặc JSON)',
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general' COMMENT 'Nhóm cài đặt: general, security, notifications, api, system',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Mô tả cài đặt',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_settings_user_uuid` (`user_uuid`),
    INDEX `idx_settings_key` (`setting_key`),
    INDEX `idx_settings_group` (`setting_group`),
    UNIQUE KEY `uq_user_setting_key` (`user_uuid`, `setting_key`),
    CONSTRAINT `fk_settings_user_uuid`
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 13. Bảng: bank_accounts (Quản lý tài khoản ngân hàng nhận tiền của Admin)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bank_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `bank_code` VARCHAR(20) NOT NULL COMMENT 'Mã định danh ngân hàng chuẩn VietQR (MB, VCB, TCB, ACB, ICB, MOMO...)',
    `bank_name` VARCHAR(100) NOT NULL COMMENT 'Tên đầy đủ ngân hàng (MBBank Quân Đội, Vietcombank...)',
    `account_number` VARCHAR(50) NOT NULL COMMENT 'Số tài khoản / Số điện thoại ví nhận tiền',
    `account_name` VARCHAR(100) NOT NULL COMMENT 'Tên chủ tài khoản (Admin)',
    `branch` VARCHAR(100) DEFAULT NULL COMMENT 'Chi nhánh ngân hàng',
    `qr_template` VARCHAR(20) NOT NULL DEFAULT 'compact2' COMMENT 'Mẫu VietQR hiển thị (compact2, compact, qr_only)',
    `min_deposit` DECIMAL(15, 2) NOT NULL DEFAULT 10000.00 COMMENT 'Hạn mức nạp tối thiểu (VND)',
    `max_deposit` DECIMAL(15, 2) NOT NULL DEFAULT 50000000.00 COMMENT 'Hạn mức nạp tối đa (VND)',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: Ưu tiên chọn mặc định',
    `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' COMMENT 'Trạng thái hoạt động',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_ba_bank_code` (`bank_code`),
    INDEX `idx_ba_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 14. Bảng: deposits (Quản lý các lệnh nạp tiền của người dùng)
-- Liên kết khóa ngoại với users.uuid
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deposits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Liên kết bảng users.uuid',
    `deposit_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã nạp tiền độc nhất (VD: NAP8492015, TQ260901...)',
    `bank_id` INT DEFAULT NULL COMMENT 'Liên kết bank_accounts.id (nếu có)',
    `bank_name` VARCHAR(100) NOT NULL COMMENT 'Tên ngân hàng chuyển đến',
    `account_number` VARCHAR(50) NOT NULL COMMENT 'Số tài khoản Admin nhận',
    `account_name` VARCHAR(100) NOT NULL COMMENT 'Tên chủ tài khoản Admin',
    `amount` DECIMAL(15, 2) NOT NULL COMMENT 'Số tiền nạp (VND)',
    `transfer_content` VARCHAR(100) NOT NULL COMMENT 'Nội dung chuyển khoản chính xác để duyệt tự động',
    `status` ENUM('Pending', 'Success', 'Failed', 'Cancelled') NOT NULL DEFAULT 'Pending' COMMENT 'Trạng thái giao dịch nạp',
    `proof_image` VARCHAR(255) DEFAULT NULL COMMENT 'Đường dẫn ảnh chụp bill chuyển khoản',
    `admin_note` VARCHAR(255) DEFAULT NULL COMMENT 'Ghi chú đối soát của Quản trị viên',
    `approved_at` DATETIME DEFAULT NULL COMMENT 'Thời điểm duyệt lệnh nạp và cộng tiền',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_dep_user_uuid` (`user_uuid`),
    INDEX `idx_dep_code` (`deposit_code`),
    INDEX `idx_dep_status` (`status`),
    INDEX `idx_dep_created_at` (`created_at`),
    CONSTRAINT `fk_deposits_user_uuid`
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
(1, 8492015, '0191eb50-0001-7000-8000-000000000001', 'Quản Trị Viên', '@admin', 'Admin@123456', 'admin@thanhquytech.vn', 5000000.00, 'assets/images/default-avatar.svg', 'Admin', 'Active'),
(2, 6839204, '0191eb50-0002-7000-8000-000000000002', 'Trần Thanh Quý', '@thanhquy', 'User@123456', 'thanhquy@gmail.com', 1500000.00, 'assets/images/default-avatar.svg', 'Member', 'Active'),
(3, 3185927, '0191eb50-0003-7000-8000-000000000003', 'Nguyễn Hoàng Nam', '@hoangnam', 'User@123456', 'hoangnam@gmail.com', 850000.00, 'assets/images/default-avatar.svg', 'Member', 'Active'),
(4, 7524918, '0191eb50-0004-7000-8000-000000000004', 'Lê Minh Anh', '@minhanh', 'User@123456', 'minhanh@gmail.com', 320000.00, 'assets/images/default-avatar.svg', 'Member', 'Active')
ON DUPLICATE KEY UPDATE `uid` = VALUES(`uid`), `name` = VALUES(`name`), `role` = VALUES(`role`), `status` = VALUES(`status`);

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
('0191eb50-0001-7000-8000-000000000001', 'NAP8492015-01', 'Deposit', 5000000.00, 0.00, 5000000.00, 'Success', 'Nạp tiền tài khoản Quản trị qua Ngân hàng'),
('0191eb50-0002-7000-8000-000000000002', 'NAP6839204-01', 'Deposit', 2000000.00, 0.00, 2000000.00, 'Success', 'Nạp tiền qua chuyển khoản QR Code'),
('0191eb50-0002-7000-8000-000000000002', 'PAY6839204-01', 'Payment', 500000.00, 2000000.00, 1500000.00, 'Success', 'Thanh toán gói dịch vụ hệ thống'),
('0191eb50-0003-7000-8000-000000000003', 'NAP3185927-01', 'Deposit', 850000.00, 0.00, 850000.00, 'Success', 'Nạp số dư tài khoản Member'),
('0191eb50-0004-7000-8000-000000000004', 'NAP7524918-01', 'Deposit', 320000.00, 0.00, 320000.00, 'Success', 'Nạp tiền kích hoạt tài khoản')
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `note` = VALUES(`note`);

-- 5. Dữ liệu quan hệ giới thiệu mẫu (Referrals) - 1 người tham gia = 1 ngày Key VIP
-- User @thanhquy (6839204) giới thiệu:
-- + User @hoangnam (3185927): Đã quy đổi nhận Key 1 ngày (is_claimed = 1)
-- + User @minhanh (7524918): Vừa tham gia, CHƯA quy đổi (is_claimed = 0) -> Sẵn sàng đổi 1 ngày Key VIP
INSERT INTO `referrals` (`referrer_uuid`, `referee_uuid`, `reward_days`, `is_claimed`, `claimed_at`, `claim_order_code`, `status`, `created_at`) VALUES
('0191eb50-0002-7000-8000-000000000002', '0191eb50-0003-7000-8000-000000000003', 1, 1, DATE_SUB(NOW(), INTERVAL 2 DAY), 'REF-KEY-892144', 'Active', DATE_SUB(NOW(), INTERVAL 4 DAY)),
('0191eb50-0002-7000-8000-000000000002', '0191eb50-0004-7000-8000-000000000004', 1, 0, NULL, NULL, 'Active', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `reward_days` = VALUES(`reward_days`), `is_claimed` = VALUES(`is_claimed`);

-- 6. Dữ liệu lịch sử quy đổi Key VIP mẫu (Referral Claims)
INSERT INTO `referral_claims` (`user_uuid`, `claim_code`, `referred_count`, `reward_days`, `license_key`, `expires_at`, `status`, `created_at`) VALUES
('0191eb50-0002-7000-8000-000000000002', 'REF-KEY-892144', 1, 1, 'TQ-REF-VIP-8921-HN03', DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 1 DAY), 'Expired', DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `reward_days` = VALUES(`reward_days`), `license_key` = VALUES(`license_key`);

-- 7. Dữ liệu yêu cầu hỗ trợ mẫu (Support Tickets)
INSERT INTO `support_tickets` (`id`, `user_uuid`, `ticket_code`, `subject`, `category`, `priority`, `status`, `order_code`, `created_at`) VALUES
(1, '0191eb50-0002-7000-8000-000000000002', 'TK-2609-7812', 'Hỗ trợ nạp tiền chưa cộng số dư tự động', 'Billing', 'High', 'Answered', 'NAP6839204-01', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2, '0191eb50-0003-7000-8000-000000000003', 'TK-2609-4159', 'Key Golike báo lỗi kích hoạt trên máy chủ phụ', 'LicenseKey', 'Urgent', 'In Progress', 'TQ-REF-VIP-8921-HN03', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, '0191eb50-0004-7000-8000-000000000004', 'TK-2609-9023', 'Tư vấn cấu hình máy chủ Cloud chạy đa luồng', 'CloudServer', 'Medium', 'Closed', NULL, DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `subject` = VALUES(`subject`), `status` = VALUES(`status`);

-- 8. Dữ liệu tin nhắn trao đổi hỗ trợ mẫu (Support Messages)
INSERT INTO `support_messages` (`id`, `ticket_id`, `sender_uuid`, `sender_role`, `message`, `created_at`) VALUES
(1, 1, '0191eb50-0002-7000-8000-000000000002', 'Member', 'Chào ban quản trị, mình vừa quét mã QR nạp 2.000.000đ từ app ngân hàng nhưng sau 5 phút hệ thống chưa cộng số dư. Nhờ ad kiểm tra giúp với mã giao dịch NAP6839204-01.', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2, 1, '0191eb50-0001-7000-8000-000000000001', 'Admin', 'Chào bạn Thanh Quý, hệ thống đã kiểm tra và đối soát giao dịch ngân hàng thành công. Số dư 2.000.000đ đã được cộng vào tài khoản của bạn. Chúc bạn làm việc hiệu quả!', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3, 2, '0191eb50-0003-7000-8000-000000000003', 'Member', 'Admin kiểm tra giúp mình mã key vừa nhận từ quà giới thiệu bạn bè, khi nhập vào tool Golike thì báo mã không tìm thấy trên server.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 2, '0191eb50-0001-7000-8000-000000000001', 'Admin', 'Kỹ thuật viên đang đồng bộ lại cache máy chủ bản quyền, bạn vui lòng đợi trong 5 phút rồi thử lại nhé.', DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(5, 3, '0191eb50-0004-7000-8000-000000000004', 'Member', 'Mình muốn thuê gói cloud treo 100 nick Golike cùng lúc thì nên chọn cấu hình nào tối ưu nhất ạ?', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(6, 3, '0191eb50-0001-7000-8000-000000000001', 'Admin', 'Chào bạn, với 100 luồng Golike bạn nên chọn gói Cloud Pro (4 vCPU, 8GB RAM) tại mục Thuê Cloud để chạy ổn định 24/7 mượt mà không bị nghẽn CPU nhé.', DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `message` = VALUES(`message`);

-- 9. Dữ liệu Access Token mẫu (token)
INSERT INTO `token` (`id`, `user_uuid`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `status`, `created_at`) VALUES
(1, '0191eb50-0001-7000-8000-000000000001', 'Admin Master Key API', 'tqt_live_9a7d8e2f1c5b4e3a0d9e8f7a6c5b4d3e', '["all"]', DATE_SUB(NOW(), INTERVAL 10 MINUTE), NULL, 'Active', DATE_SUB(NOW(), INTERVAL 15 DAY)),
(2, '0191eb50-0002-7000-8000-000000000002', 'Golike Automation Server 1', 'tqt_live_4b8f2c1e7a9d3e5f0b6a8c4e2d7f1a9b', '["jobs","read"]', DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_ADD(NOW(), INTERVAL 60 DAY), 'Active', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(3, '0191eb50-0002-7000-8000-000000000002', 'Auto Bot TraoDoiSub', 'tqt_live_1f3e5a7b9c2d4e6f8a0b2c4d6e8f0a2c', '["jobs"]', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 'Active', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, '0191eb50-0003-7000-8000-000000000003', 'VPS Cloud Worker 01', 'tqt_live_7e9a1b3c5d7f9a1b3c5d7f9a1b3c5d7f', '["jobs","read"]', DATE_SUB(NOW(), INTERVAL 6 HOUR), DATE_ADD(NOW(), INTERVAL 90 DAY), 'Active', DATE_SUB(NOW(), INTERVAL 10 DAY))
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `abilities` = VALUES(`abilities`), `status` = VALUES(`status`);

-- 10. Dữ liệu Cấu hình hệ thống & người dùng mẫu (settings)
INSERT INTO `settings` (`user_uuid`, `setting_key`, `setting_value`, `setting_group`, `description`) VALUES
-- Cấu hình toàn hệ thống (user_uuid = NULL)
(NULL, 'site_name', 'ThanhQuyTech - Nền Tảng Tool & Cloud Bản Quyền', 'system', 'Tên thương hiệu website hiển thị'),
(NULL, 'site_contact_telegram', 'https://t.me/thanhquytech_support', 'system', 'Kênh hỗ trợ Telegram'),
(NULL, 'site_contact_zalo', '0987654321', 'system', 'Số điện thoại Zalo hỗ trợ'),
(NULL, 'maintenance_mode', '0', 'system', 'Chế độ bảo trì hệ thống (0: Tắt, 1: Bật)'),
(NULL, 'announcement_marquee', '🎉 Chào mừng đến với ThanhQuyTech! Hệ thống tự động kích hoạt Key và Cloud 24/7 siêu tốc.', 'system', 'Thông báo chạy chữ đầu trang'),
(NULL, 'referral_bonus_days', '1', 'system', 'Số ngày thưởng Key VIP cho mỗi lượt giới thiệu thành công'),
-- Cấu hình cá nhân của Quản trị viên (@admin)
('0191eb50-0001-7000-8000-000000000001', 'notification_email', '1', 'notifications', 'Nhận email thông báo hệ thống'),
('0191eb50-0001-7000-8000-000000000001', 'notification_telegram', '1', 'notifications', 'Nhận thông báo đơn hàng qua Telegram'),
('0191eb50-0001-7000-8000-000000000001', 'telegram_chat_id', '589214782', 'notifications', 'ID Chat Telegram nhận tin'),
('0191eb50-0001-7000-8000-000000000001', 'default_platform', 'golike', 'general', 'Nền tảng mặc định khi xem bảng điều khiển'),
-- Cấu hình cá nhân của User @thanhquy
('0191eb50-0002-7000-8000-000000000002', 'notification_email', '1', 'notifications', 'Nhận email thông báo hệ thống'),
('0191eb50-0002-7000-8000-000000000002', 'notification_telegram', '1', 'notifications', 'Nhận thông báo qua Telegram'),
('0191eb50-0002-7000-8000-000000000002', 'telegram_chat_id', '629831441', 'notifications', 'ID Chat Telegram'),
('0191eb50-0002-7000-8000-000000000002', 'auto_renew_key', '0', 'general', 'Tự động gia hạn key khi hết hạn'),
('0191eb50-0002-7000-8000-000000000002', 'hide_balance_header', '0', 'general', 'Ẩn hiển thị số dư trên thanh tiêu đề')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 11. Dữ liệu Tài khoản ngân hàng nhận tiền mẫu của Admin (bank_accounts)
-- Chỉ sử dụng duy nhất ngân hàng TPBank theo cấu hình hệ thống
INSERT INTO `bank_accounts` (`id`, `bank_code`, `bank_name`, `account_number`, `account_name`, `branch`, `qr_template`, `min_deposit`, `max_deposit`, `is_default`, `status`) VALUES
(1, 'TPB', 'TPBank (Ngân Hàng Tiên Phong)', '0987654321', 'TRAN THANH QUY', 'Hội Sở Chính Hà Nội', 'compact2', 10000.00, 50000000.00, 1, 'Active')
ON DUPLICATE KEY UPDATE `bank_code` = VALUES(`bank_code`), `bank_name` = VALUES(`bank_name`), `account_number` = VALUES(`account_number`), `account_name` = VALUES(`account_name`);

-- 12. Dữ liệu lệnh nạp tiền mẫu của người dùng (deposits)
-- Định dạng mã đơn và cú pháp: ThanhQuyTech(mã 7 số ngẫu nhiên)
INSERT INTO `deposits` (`user_uuid`, `deposit_code`, `bank_id`, `bank_name`, `account_number`, `account_name`, `amount`, `transfer_content`, `status`, `approved_at`, `created_at`) VALUES
('0191eb50-0002-7000-8000-000000000002', '6839204', 1, 'TPBank (Ngân Hàng Tiên Phong)', '0987654321', 'TRAN THANH QUY', 2000000.00, 'ThanhQuyTech6839204', 'Success', DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 3 HOUR)),
('0191eb50-0003-7000-8000-000000000003', '3185927', 1, 'TPBank (Ngân Hàng Tiên Phong)', '0987654321', 'TRAN THANH QUY', 850000.00, 'ThanhQuyTech3185927', 'Success', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
('0191eb50-0004-7000-8000-000000000004', '7524918', 1, 'TPBank (Ngân Hàng Tiên Phong)', '0987654321', 'TRAN THANH QUY', 320000.00, 'ThanhQuyTech7524918', 'Success', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
('0191eb50-0002-7000-8000-000000000002', '9281045', 1, 'TPBank (Ngân Hàng Tiên Phong)', '0987654321', 'TRAN THANH QUY', 500000.00, 'ThanhQuyTech9281045', 'Pending', NULL, DATE_SUB(NOW(), INTERVAL 15 MINUTE))
ON DUPLICATE KEY UPDATE `amount` = VALUES(`amount`), `status` = VALUES(`status`);


