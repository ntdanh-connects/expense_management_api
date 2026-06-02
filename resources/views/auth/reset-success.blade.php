<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thành Công - App Quản Lý Chi Tiêu</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #10b981;
            --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            --surface: rgba(255, 255, 255, 0.06);
            --surface-border: rgba(255, 255, 255, 0.08);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
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

        /* Glowing background spots */
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
            top: 20%;
            left: 20%;
        }

        .circle-2 {
            background: #6366f1;
            bottom: 20%;
            right: 20%;
        }

        .container {
            width: 100%;
            max-width: 440px;
            z-index: 10;
        }

        .card {
            background: var(--surface);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--surface-border);
            border-radius: 24px;
            padding: 48px 32px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        /* Animated Success Checkmark */
        .success-checkmark {
            width: 80px;
            height: 80px;
            margin: 0 auto 30px;
            background: rgba(16, 185, 129, 0.1);
            border: 1.5px solid rgba(16, 185, 129, 0.25);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse-border 2s infinite;
        }

        .success-checkmark svg {
            width: 40px;
            height: 40px;
            fill: none;
            stroke: var(--primary);
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: draw-check 0.8s ease-in-out forwards 0.2s;
        }

        @keyframes draw-check {
            to {
                stroke-dashoffset: 0;
            }
        }

        @keyframes pulse-border {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.2);
            }
            70% {
                box-shadow: 0 0 0 16px rgba(16, 185, 129, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        h1 {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        p {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 36px;
        }

        .btn-action {
            display: inline-block;
            width: 100%;
            background: var(--primary);
            color: #ffffff;
            text-decoration: none;
            border-radius: 14px;
            padding: 16px;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .btn-action:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-action:active {
            transform: translateY(0);
        }

        .footer-note {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 24px;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="glowing-circle circle-1"></div>
    <div class="glowing-circle circle-2"></div>

    <div class="container">
        <div class="card">
            <div class="success-checkmark">
                <svg viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>

            <h1>Đặt Lại Thành Công!</h1>
            <p>Chúc mừng sếp! Mật khẩu mới của tài khoản App Quản Lý Chi Tiêu đã được thiết lập lại thành công và cực kỳ bảo mật.</p>

            <a href="#" class="btn-action" onclick="closeOrRedirect(event)">Quay Lại Ứng Dụng</a>
            
            <div class="footer-note">Sếp có thể đóng tab trình duyệt này và đăng nhập lại bằng mật khẩu mới.</div>
        </div>
    </div>

    <script>
        function closeOrRedirect(e) {
            e.preventDefault();
            // Try opening default deep links if applicable
            // For now, prompt the user that they can safely go back
            alert('Mật khẩu đã đổi thành công! Sếp vui lòng mở lại App Quản Lý Chi Tiêu trên điện thoại hoặc trình duyệt web để đăng nhập bằng mật khẩu mới nhé.');
            
            try {
                window.close();
            } catch(err) {
                // If browser blocks window.close
            }
        }
    </script>
</body>
</html>
