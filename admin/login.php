<?php
session_start();
require_once '../config.php';

// If already logged in, redirect to index
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = 'الرجاء إدخال اسم المستخدم وكلمة المرور.';
        header("Location: login.php");
        exit;
    } else {
        try {
            // Generate the HMAC blind index for the entered username
            $usernameHash = sport_username_hash($username);

            // Lookup user by HMAC blind index (no plaintext username ever stored)
            $stmt = $pdo->prepare("
                SELECT id, username_encrypted, password_hash, role, is_active, login_attempts, locked_until 
                FROM users 
                WHERE username_hash = ?
            ");
            $stmt->execute([$usernameHash]);
            $user = $stmt->fetch();

            if ($user) {
                // Verify password BEFORE checking status to prevent enumeration
                if (password_verify($password, $user['password_hash'])) {
                    // Check if account is locked
                    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                        $remainingMinutes = ceil((strtotime($user['locked_until']) - time()) / 60);
                        $_SESSION['login_error'] = "الحساب مقفل. حاول مرة أخرى بعد {$remainingMinutes} دقيقة.";
                    }
                    // Check if account is active
                    elseif (!$user['is_active']) {
                        $_SESSION['login_error'] = 'هذا الحساب غير مفعل.';
                    }
                    else {
                        // Successful Login!
                        $decryptedUsername = sport_decrypt_username($user['username_encrypted']);

                        // Set session variables
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_user_id'] = $user['id'];
                        $_SESSION['admin_username'] = $decryptedUsername;
                        $_SESSION['admin_role'] = $user['role'];

                        // Regenerate session ID for security (prevent session fixation)
                        session_regenerate_id(true);

                        // Reset login attempts and update last_login
                        $stmt = $pdo->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?");
                        $stmt->execute([$user['id']]);

                        header("Location: index.php");
                        exit;
                    }
                } else {
                    // Wrong password - increment attempts
                    $attempts = $user['login_attempts'] + 1;
                    $lockedUntil = null;

                    // Lock account after 5 failed attempts for 15 minutes
                    if ($attempts >= 5) {
                        $lockedUntil = date('Y-m-d H:i:s', time() + (15 * 60));
                    }
                    
                    // Generic error to prevent username enumeration
                    $_SESSION['login_error'] = 'اسم المستخدم أو كلمة المرور غير صحيحة.';

                    $stmt = $pdo->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?");
                    $stmt->execute([$attempts, $lockedUntil, $user['id']]);
                }
            } else {
                // User not found - generic error to prevent username enumeration
                $_SESSION['login_error'] = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
            }

            // Anti-brute force delay
            sleep(1);
            
            header("Location: login.php");
            exit;

        } catch (Exception $e) {
            $_SESSION['login_error'] = 'حدث خطأ في النظام. حاول مرة أخرى.';
            header("Location: login.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - لوحة التحكم</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #ffffffff 0%, #f6fcffff 100%);
            --card-bg: rgba(255, 255, 255, 0.8);
            --accent: #3b82f6;
            --accent-hover: #2563eb;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: rgba(255, 255, 255, 0.4);
            --radius: 24px;
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background: var(--bg-gradient);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-icon {
            font-size: 3rem;
            color: var(--accent);
            margin-bottom: 15px;
            background: rgba(59, 130, 246, 0.1);
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .login-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text-primary);
        }

        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

      
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .input-group {
            position: relative;
        }

        .input-group i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 12px 40px 12px 15px;
            background: #ffffff;
            border: 1px solid #dbe3e9;
            border-radius: 14px;
            color: var(--text-primary);
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            background: white;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.2);
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            body {
                align-items: flex-start;
                padding: 16px;
            }

            .login-container {
                max-width: none;
                margin-top: 5vh;
            }

            .login-header {
                margin-bottom: 22px;
            }

            .login-icon {
                width: 68px;
                height: 68px;
                font-size: 2.4rem;
                border-radius: 18px;
            }

            .login-title {
                font-size: 1.25rem;
            }

            .login-subtitle {
                font-size: 0.84rem;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-header">
        <i class="fas fa-shield-alt login-icon"></i>
        <h1 class="login-title">مركز التحكم الآمن</h1>
        <p class="login-subtitle">الرجاء تسجيل الدخول للمتابعة</p>
    </div>

    <?php if ($error): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label class="form-label" for="username">اسم المستخدم</label>
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" id="username" name="username" class="form-control" required autocomplete="username">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">كلمة المرور</label>
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
            </div>
        </div>

        <button type="submit" class="btn-login">
            <span>تسجيل الدخول</span>
            <i class="fas fa-sign-in-alt"></i>
        </button>
    </form>
</div>

</body>
</html>
