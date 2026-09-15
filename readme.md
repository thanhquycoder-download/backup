# HỆ THỐNG WEBSITE THANHQUYTECH

Website đua top sản lượng, quản lý người dùng, giao dịch số dư và phân quyền với chuẩn bảo mật cao cấp, kiến trúc cơ sở dữ liệu đồng bộ liên kết qua **UUIDv7**.

---

## 1. Cấu Trúc Cơ Sở Dữ Liệu & Liên Kết Khóa Ngoại (Foreign Keys)

File định nghĩa CSDL: `database.sql` (Cấu hình kết nối: `config/config.php`).

Mọi bảng nghiệp vụ được liên kết chặt chẽ với bảng `users` thông qua trường **`uuid` (chuẩn UUIDv7)** và quy tắc ràng buộc `ON DELETE CASCADE ON UPDATE CASCADE`.

### Danh sách các bảng:

1. **`users` (Người dùng)**:
   - `id`: Khóa chính tự tăng (`BIGINT UNSIGNED`).
   - `uid`: Mã định danh riêng biệt 7 số ngẫu nhiên (`1000000 - 9999999`).
   - `uuid`: Chuẩn `UUIDv7` kết nối giữa các bảng trong hệ thống.
   - `name`: Tên hiển thị người dùng.
   - `username`: Tên đăng nhập bắt đầu bằng dấu `@` (VD: `@admin`, `@thanhquy`).
   - `password`: Mật khẩu băm `Hash + Salt + Stretching (BCrypt Cost 12) + Pepper`.
   - `email`: Email đăng ký duy nhất.
   - `balance`: Số dư tài khoản nạp vào (VND).
   - `avatar`: Đường dẫn ảnh đại diện.
   - `role`: Vai trò (`Admin`, `Member`).
   - `status`: Trạng thái (`Active`, `Inactive`, `Locked`).
   - `created_at`, `updated_at`: Thời gian tạo và cập nhật.

2. **`password_resets` (Khôi phục mật khẩu)**:
   - `id`: Khóa chính tự tăng.
   - `user_uuid`: Khóa ngoại liên kết tới `users.uuid`.
   - `email`: Khóa ngoại liên kết tới `users.email`.
   - `token`: Chuỗi token bảo mật ngẫu nhiên (hiệu lực 30 phút).
   - `expires_at`, `created_at`.

3. **`platforms` (Nền tảng đua top)**:
   - `id`: Khóa chính tự tăng.
   - `code`: Mã nền tảng (`golike`, `tuongtaccheo`, `traodoisub`).
   - `name`: Tên hiển thị.
   - `icon`: Biểu tượng FontAwesome.
   - `status`: Trạng thái Bật/Tắt bởi Admin (`Active`, `Inactive`).

4. **`rankings` (Sản lượng & Điểm đua top)**:
   - `id`: Khóa chính tự tăng.
   - `user_uuid`: Khóa ngoại liên kết tới `users.uuid`.
   - `platform_id`: Khóa ngoại liên kết tới `platforms.id`.
   - `amount`: Doanh thu / số tiền kiếm được.
   - `points`: Số tác vụ / điểm số tích lũy.
   - `date`: Ngày ghi nhận.
   - Chỉ mục duy nhất: `UNIQUE KEY (user_uuid, platform_id, date)`.

5. **`transactions` (Lịch sử biến động số dư)**:
   - `id`: Khóa chính tự tăng.
   - `user_uuid`: Khóa ngoại liên kết tới `users.uuid`.
   - `code`: Mã giao dịch duy nhất (`NAP...`, `PAY...`).
   - `type`: Loại giao dịch (`Deposit`, `Withdraw`, `Payment`, `Refund`).
   - `amount`: Số tiền biến động.
   - `balance_before`, `balance_after`: Số dư trước và sau giao dịch.
   - `status`: Trạng thái (`Success`, `Pending`, `Failed`, `Cancelled`).
   - `note`: Ghi chú nội dung giao dịch.

---

## 2. Tài Khoản Thử Nghiệm Mẫu (Seed Data)

Khi import `database.sql`, hệ thống có sẵn các tài khoản sau:

| Tên người dùng | Email | Mật khẩu mặc định | Vai trò | Số dư ban đầu |
| :--- | :--- | :--- | :--- | :--- |
| **`@admin`** | `admin@thanhquytech.vn` | `Admin@123456` | **Admin** | 5.000.000 ₫ |
| **`@thanhquy`** | `thanhquy@gmail.com` | `User@123456` | **Member** | 1.500.000 ₫ |
| **`@hoangnam`** | `hoangnam@gmail.com` | `User@123456` | **Member** | 850.000 ₫ |
| **`@minhanh`** | `minhanh@gmail.com` | `User@123456` | **Member** | 320.000 ₫ |

> *(Lưu ý: Mật khẩu sẽ tự động được hệ thống mã hóa sang chuẩn Stretched Hash + Pepper ngay lần đăng nhập đầu tiên).*

---

## 3. Các Tính Năng & Trang Hệ Thống

- **`index.php`**: Bảng điều khiển chính, xếp hạng Top Ngày / Tuần / Tháng, biểu đồ doanh thu Chart.js 7 ngày, biểu đồ tỷ trọng nền tảng, công tắc Bật/Tắt nền tảng dành cho Admin.
- **`profile.php`**: Hồ sơ cá nhân hiển thị mã UID 7 số, mã UUIDv7, số dư, cùng 2 khối dữ liệu liên kết từ `rankings` (sản lượng đua top) và `transactions` (lịch sử biến động số dư).
- **`login.php`**: Đăng nhập bằng `@username` hoặc Email, cấp phiên JWT lưu LocalStorage và Session.
- **`register.php`**: Đăng ký tài khoản tự động sinh UID 7 số ngẫu nhiên và UUIDv7 chuẩn RFC 9562.
- **`forgot-password.php`**: Yêu cầu đặt lại mật khẩu liên kết chặt chẽ qua `user_uuid`.
- **`reset-password.php`**: Xác thực token và cập nhật mật khẩu mới bảo mật.
- **`logout.php`**: Đăng xuất an toàn, xóa Session và JWT Token.
- **`api/verify-token.php`**: Tự động khôi phục phiên đăng nhập khi mở lại website qua JWT LocalStorage.




# HỆ THỐNG WEBSITE THANHQUYTECH

Website đua top sản lượng, quản lý người dùng, giao dịch số dư và phân quyền với chuẩn bảo mật cao cấp, kiến trúc cơ sở dữ liệu đồng bộ liên kết qua **UUIDv7**.

---

## 1. Cấu Trúc Cơ Sở Dữ Liệu & Liên Kết Khóa Ngoại (Foreign Keys)

File định nghĩa CSDL: `database.sql` (Cấu hình kết nối: `config/config.php`).

Mọi bảng nghiệp vụ được liên kết chặt chẽ với bảng `users` thông qua trường **`uuid` (chuẩn UUIDv7)** và quy tắc ràng buộc `ON DELETE CASCADE ON UPDATE CASCADE`.

### Danh sách các bảng:

1. **`users` (Người dùng)**:
   - `id`: Khóa chính tự tăng (`BIGINT UNSIGNED`).
   - `uid`: Mã định danh riêng biệt 7 số ngẫu nhiên (`1000000 - 9999999`).
   - `uuid`: Chuẩn `UUIDv7` kết nối giữa các bảng trong hệ thống.
   - `name`: Tên hiển thị người dùng.
   - `username`: Tên đăng nhập bắt đầu bằng dấu `@` (VD: `@admin`, `@thanhquy`).
   - `password`: Mật khẩu băm `Hash + Salt + Stretching (BCrypt Cost 12) + Pepper`.
   - `email`: Email đăng ký duy nhất.
   - `balance`: Số dư tài khoản nạp vào (VND).
   - `avatar`: Đường dẫn ảnh đại diện.
   - `role`: Vai trò (`Admin`, `Member`).
   - `status`: Trạng thái (`Active`, `Inactive`, `Locked`).
   - `created_at`, `updated_at`: Thời gian tạo và cập nhật.

2. **`password_resets` (Khôi phục mật khẩu)**:
   - `id`: Khóa chính tự tăng.
   - `user_uuid`: Khóa ngoại liên kết tới `users.uuid`.
   - `email`: Khóa ngoại liên kết tới `users.email`.
   - `token`: Chuỗi token bảo mật ngẫu nhiên (hiệu lực 30 phút).
   - `expires_at`, `created_at`.

3. **`platforms` (Nền tảng đua top)**:
   - `id`: Khóa chính tự tăng.
   - `code`: Mã nền tảng (`golike`, `tuongtaccheo`, `traodoisub`).
   - `name`: Tên hiển thị.
   - `icon`: Biểu tượng FontAwesome.
   - `status`: Trạng thái Bật/Tắt bởi Admin (`Active`, `Inactive`).

4. **`rankings` (Sản lượng & Điểm đua top)**:
   - `id`: Khóa chính tự tăng.
   - `user_uuid`: Khóa ngoại liên kết tới `users.uuid`.
   - `platform_id`: Khóa ngoại liên kết tới `platforms.id`.
   - `amount`: Doanh thu / số tiền kiếm được.
   - `points`: Số tác vụ / điểm số tích lũy.
   - `date`: Ngày ghi nhận.
   - Chỉ mục duy nhất: `UNIQUE KEY (user_uuid, platform_id, date)`.

5. **`transactions` (Lịch sử biến động số dư)**:
   - `id`: Khóa chính tự tăng.
   - `user_uuid`: Khóa ngoại liên kết tới `users.uuid`.
   - `code`: Mã giao dịch duy nhất (`NAP...`, `PAY...`).
   - `type`: Loại giao dịch (`Deposit`, `Withdraw`, `Payment`, `Refund`).
   - `amount`: Số tiền biến động.
   - `balance_before`, `balance_after`: Số dư trước và sau giao dịch.
   - `status`: Trạng thái (`Success`, `Pending`, `Failed`, `Cancelled`).
   - `note`: Ghi chú nội dung giao dịch.

---

## 2. Tài Khoản Thử Nghiệm Mẫu (Seed Data)

Khi import `database.sql`, hệ thống có sẵn các tài khoản sau:

| Tên người dùng | Email | Mật khẩu mặc định | Vai trò | Số dư ban đầu |
| :--- | :--- | :--- | :--- | :--- |
| **`@admin`** | `admin@thanhquytech.vn` | `Admin@123456` | **Admin** | 5.000.000 ₫ |
| **`@thanhquy`** | `thanhquy@gmail.com` | `User@123456` | **Member** | 1.500.000 ₫ |
| **`@hoangnam`** | `hoangnam@gmail.com` | `User@123456` | **Member** | 850.000 ₫ |
| **`@minhanh`** | `minhanh@gmail.com` | `User@123456` | **Member** | 320.000 ₫ |

> *(Lưu ý: Mật khẩu sẽ tự động được hệ thống mã hóa sang chuẩn Stretched Hash + Pepper ngay lần đăng nhập đầu tiên).*

---

## 3. Các Tính Năng & Trang Hệ Thống

- **`index.php`**: Bảng điều khiển chính, xếp hạng Top Ngày / Tuần / Tháng, biểu đồ doanh thu Chart.js 7 ngày, biểu đồ tỷ trọng nền tảng, công tắc Bật/Tắt nền tảng dành cho Admin.
- **`profile.php`**: Hồ sơ cá nhân hiển thị mã UID 7 số, mã UUIDv7, số dư, cùng 2 khối dữ liệu liên kết từ `rankings` (sản lượng đua top) và `transactions` (lịch sử biến động số dư).
- **`login.php`**: Đăng nhập bằng `@username` hoặc Email, cấp phiên JWT lưu LocalStorage và Session.
- **`register.php`**: Đăng ký tài khoản tự động sinh UID 7 số ngẫu nhiên và UUIDv7 chuẩn RFC 9562.
- **`forgot-password.php`**: Yêu cầu đặt lại mật khẩu liên kết chặt chẽ qua `user_uuid`.
- **`reset-password.php`**: Xác thực token và cập nhật mật khẩu mới bảo mật.
- **`logout.php`**: Đăng xuất an toàn, xóa Session và JWT Token.
- **`api/verify-token.php`**: Tự động khôi phục phiên đăng nhập khi mở lại website qua JWT LocalStorage.




bn sửa index.php lại có headers cố định lướt k bị trượt treo, và menu sidebar menu girl gồm:
* Bên trái
- Trang chủ
- Mua key
- Thuê cloud
- Tool Golike(có menu sổ xuống)(/jobs/golike):
 + Instagram (/jobs/golike/instagram)(có bảng lịch sử)
 + Threads (/jobs/golike/threads)(có bảng lịch sử)
 + Pinterest (/jobs/golike/pinterest)(có bảng lịch sử)
- Account(có menu sổ xuống)(/products/accounts):
 + Instagram (/products/accounts/instagram)(có bảng lịch sử)
 + Threads (/products/accounts/threads)(có bảng lịch sử)
 + Pinterest (/products/accounts/pinterest)(có bảng lịch sử)
- Payment(có menu sổ xuống)(/payments):
 + Nạp tiền (/payments/deposit)(có bảng lịch sử)
 + Rút tiền (/payments/withdraw)(có bảng lịch sử)
- Giới thiệu(/referral)(có bảng lịch sử)
- Hỗ trợ(/support)(có bảng lịch sử)
(mấy kia chưa có file thì mik sẽ updatasau bn cứ sửa duy nhất thằng index và hồ sơ lại giúp mik)

* Bên phải
- Số dư
- Ảnh avatar hồ sơ