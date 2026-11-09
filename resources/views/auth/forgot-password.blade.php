<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Forgot Password - Employee Dashboard - SITE</title>
    <link rel="icon" type="image/png" href="{{ asset('images/SPUP-final-logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-portal-page" data-font-size="medium">

    <header class="login-portal-header">
        <div class="login-portal-header-inner">
            <div class="login-portal-brand">
                <img src="{{ asset('images/site-logo.png') }}" alt="SITE Logo" class="login-portal-brand-logo">
                <div class="login-portal-brand-text">
                    <h1 class="login-portal-brand-title">School of Information Technology and Engineering</h1>
                    <p class="login-portal-brand-subtitle">St. Paul University Philippines</p>
                </div>
            </div>

            <div class="login-portal-header-meta">
                <button id="themeToggle" type="button" class="login-portal-theme-toggle" aria-label="Toggle theme">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>
    </header>

    <main class="login-portal-main">
        <div class="login-portal-card">
            <div class="login-portal-card-header">
                <h2 class="login-portal-card-title">Forgot Password</h2>
                <p class="login-portal-card-subtitle">Request a password reset from the Dean or Admin</p>
            </div>

            <div class="login-portal-card-body">
                <div class="login-portal-welcome">
                    <h3 class="login-portal-welcome-title">Reset Request</h3>
                    <p class="login-portal-welcome-sub">
                        Enter your username below. Your request will be sent to the Dean or Admin for review.
                        Once approved, they will hand you a temporary password in person.
                    </p>
                </div>

                @if(session('success'))
                <div class="login-auth-message login-auth-message--success" role="status">
                    <i class="fas fa-check-circle"></i> {{ session('success') }}
                </div>
                @endif

                @if(session('info'))
                <div class="login-auth-message login-auth-message--info" role="status">
                    <i class="fas fa-info-circle"></i> {{ session('info') }}
                </div>
                @endif

                @if($errors->any())
                <div class="login-portal-error">
                    {{ $errors->first() }}
                </div>
                @endif

                <form action="{{ route('password.forgot.submit') }}" method="POST" id="forgotForm">
                    @csrf

                    <div class="login-portal-field">
                        <label class="login-portal-label">Username</label>
                        <div class="login-portal-input-wrapper">
                            <i class="fas fa-user login-portal-input-icon"></i>
                            <input type="text"
                                   name="username"
                                   class="login-portal-input"
                                   placeholder="Enter your username"
                                   required
                                   autocomplete="username"
                                   value="{{ old('username') }}">
                        </div>
                    </div>

                    <div class="login-portal-submit-wrap">
                        <button type="submit" class="login-portal-submit">
                            SEND REQUEST <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>

                    <div class="login-portal-row forgot-password-back">
                        <a href="{{ route('login') }}" class="login-portal-forgot">
                            <i class="fas fa-arrow-left"></i> Back to login
                        </a>
                    </div>
                </form>

                <div class="forgot-password-note">
                    <i class="fas fa-shield-alt"></i>
                    For security, your existing password will be replaced with a one-time temporary
                    password the Dean or Admin issues to you. You will be required to change it on first login.
                </div>
            </div>
        </div>

        <p class="login-portal-ay">A.Y. 2025-2026 &nbsp;|&nbsp; <span class="ay-motto">Caritas Christi Urget Nos</span></p>
    </main>

    <footer class="login-portal-footer">
        &copy; {{ date('Y') }} St. Paul University Philippines. All rights reserved.
    </footer>

    <script>
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        const savedTheme = localStorage.getItem('theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        if (savedTheme === 'dark') {
            document.body.classList.add('dark');
            html.classList.add('dark');
        }
        updateThemeIcon(savedTheme);

        themeToggle.addEventListener('click', () => {
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            html.classList.toggle('dark');
            document.body.classList.toggle('dark');
            updateThemeIcon(newTheme);
        });

        function updateThemeIcon(theme) {
            const icon = themeToggle.querySelector('i');
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    </script>
</body>
</html>
