<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt Lại Mật Khẩu - App Quản Lý Chi Tiêu</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #10b981; /* Emerald green / Mint */
            --primary-hover: #059669;
            --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); /* Deep dark blue */
            --surface: rgba(255, 255, 255, 0.06);
            --surface-border: rgba(255, 255, 255, 0.08);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text-primary);
            overflow-x: hidden;
            position: relative;
        }

        /* Decorative glowing circles */
        .glowing-circle {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 1;
            opacity: 0.15;
        }

        .circle-1 {
            background: var(--primary);
            top: 15%;
            left: 20%;
        }

        .circle-2 {
            background: #6366f1; /* Indigo */
            bottom: 15%;
            right: 20%;
        }

        .container {
            width: 100%;
            max-width: 460px;
            z-index: 10;
        }

        .card {
            background: var(--surface);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--surface-border);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .logo {
            width: 64px;
            height: 64px;
            background: rgba(16, 185, 129, 0.15);
            border: 1.5px solid rgba(16, 185, 129, 0.3);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }

        .logo svg {
            width: 32px;
            height: 32px;
            fill: var(--primary);
        }

        h1 {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .subtitle {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 32px;
        }

        .form-group {
            text-align: left;
            margin-bottom: 20px;
            position: relative;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
            padding-left: 4px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper svg.field-icon {
            position: absolute;
            left: 16px;
            width: 20px;
            height: 20px;
            fill: var(--text-secondary);
            pointer-events: none;
            transition: fill 0.3s ease;
        }

        input {
            width: 100%;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 14px 44px 14px 48px;
            font-size: 15px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
        }

        input:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.06);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
        }

        input:focus + svg.field-icon {
            fill: var(--primary);
        }

        input:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background: rgba(255, 255, 255, 0.01);
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password svg {
            width: 20px;
            height: 20px;
            fill: var(--text-secondary);
            transition: fill 0.3s ease;
        }

        .toggle-password:hover svg {
            fill: var(--text-primary);
        }

        .error-message {
            color: var(--danger);
            font-size: 12px;
            margin-top: 6px;
            padding-left: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 24px;
            text-align: left;
            font-size: 13px;
            color: #fca5a5;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-error svg {
            width: 18px;
            height: 18px;
            fill: var(--danger);
            flex-shrink: 0;
            margin-top: 1px;
        }

        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 14px;
            padding: 15px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 12px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .card {
                padding: 30px 20px;
            }
            h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="glowing-circle circle-1"></div>
    <div class="glowing-circle circle-2"></div>

    <div class="container">
        <div class="card">
            <div class="logo">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.00 16h-2v-2h2v2zm0-4h-2V7h2v7z"/>
                </svg>
            </div>
            
            <h1>Thiết Lập Mật Khẩu</h1>
            <p class="subtitle">Vui lòng nhập mật khẩu mới cực bảo mật cho tài khoản của sếp</p>

            @if ($errors->any())
                <div class="alert-error">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <div>
                        <strong>Lỗi thiết lập:</strong>
                        <ul style="margin-left: 14px; margin-top: 4px;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <form action="{{ route('password.update') }}" method="POST">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="email" value="{{ $email }}">

                <!-- Email hiển thị (không cho sửa để an toàn) -->
                <div class="form-group">
                    <label>Địa chỉ Email của sếp</label>
                    <div class="input-wrapper">
                        <svg class="field-icon" viewBox="0 0 24 24">
                            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                        <input type="email" value="{{ $email }}" disabled>
                    </div>
                </div>

                <!-- Mật khẩu mới -->
                <div class="form-group">
                    <label for="password">Mật khẩu mới</label>
                    <div class="input-wrapper">
                        <svg class="field-icon" viewBox="0 0 24 24">
                            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                        </svg>
                        <input type="password" id="password" name="password" placeholder="Tối thiểu 8 ký tự" required autofocus>
                        <button type="button" class="toggle-password" onclick="toggleField('password', this)">
                            <svg class="eye-icon" viewBox="0 0 24 24">
                                <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Xác nhận mật khẩu mới -->
                <div class="form-group">
                    <label for="password_confirmation">Xác nhận mật khẩu</label>
                    <div class="input-wrapper">
                        <svg class="field-icon" viewBox="0 0 24 24">
                            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                        </svg>
                        <input type="password" id="password_confirmation" name="password_confirmation" placeholder="Nhập lại mật khẩu mới" required>
                        <button type="button" class="toggle-password" onclick="toggleField('password_confirmation', this)">
                            <svg class="eye-icon" viewBox="0 0 24 24">
                                <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Cập Nhật Mật Khẩu</button>
            </form>
        </div>
    </div>

    <script>
        function toggleField(fieldId, btn) {
            const input = document.getElementById(fieldId);
            const svg = btn.querySelector('svg');
            
            if (input.type === 'password') {
                input.type = 'text';
                // Change to eye closed icon
                svg.innerHTML = '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 2.2 0 4.27-.61 6.04-1.66l.46.46L20.73 22l1.27-1.27L3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>';
            } else {
                input.type = 'password';
                // Restore eye open icon
                svg.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
            }
        }
    </script>
</body>
</html>
