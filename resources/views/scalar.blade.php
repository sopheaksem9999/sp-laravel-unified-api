<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ERP API v2 – Records Docs</title>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
    <style>
        html,
        body {
            height: 100%;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        /* Login overlay styles */
        #login-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }

        #login-form {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
        }

        #login-form h2 {
            margin: 0 0 1.5rem 0;
            color: #333;
            text-align: center;
        }

        #login-form .form-group {
            margin-bottom: 1rem;
        }

        #login-form label {
            display: block;
            margin-bottom: 0.5rem;
            color: #555;
            font-weight: 500;
        }

        #login-form input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            box-sizing: border-box;
        }

        #login-form input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }

        #login-form button {
            width: 100%;
            padding: 0.75rem;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        #login-form button:hover {
            background: #0056b3;
        }

        #login-form button:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        .error-message {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        .success-message {
            color: #28a745;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        /* Auth status bar */
        #auth-status {
            position: relative;
            top: 0;
            left: 0;
            right: 0;
            background: #28a745;
            color: white;
            padding: 0.5rem 1rem;
            display: none;
            align-items: center;
            justify-content: space-between;
            z-index: 9999;
            font-size: 0.875rem;
        }

        #auth-status.show {
            display: flex;
        }

        #auth-status button {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.75rem;
        }

        #auth-status button:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        #docs {
            height: 100%;
            padding-top: 0;
            transition: padding-top 0.3s ease;
        }

        /* Loading overlay styles */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9998;
            transition: opacity 0.3s ease;
        }

        #loading-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .loading-text {
            color: #333;
            font-size: 1rem;
            font-weight: 500;
            text-align: center;
        }

        .loading-subtext {
            color: #666;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            text-align: center;
        }
    </style>
    <link rel="icon" href="{{ asset('favicon.png') }}" />
</head>

<body>
    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loading-text">Initializing API Documentation...</div>
    </div>

    <!-- Authentication Status Bar -->
    <div id="auth-status">
        <span id="auth-user-info"></span>
        <button onclick="logout()">Logout</button>
    </div>

    <!-- Login Overlay -->
    <div id="login-overlay">
        <form id="login-form" onsubmit="handleLogin(event)">
            <h2>API Documentation</h2>
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" id="login-btn">Login</button>
            <div id="login-message"></div>
            @if (app()->environment('local'))
                <div
                    style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; font-size: 0.875rem; color: #666;">
                    <strong>Demo Credentials:</strong><br>
                    Email: superadmin@mail.com<br>
                    Password: app@#2024<br>
                </div>
            @endif
        </form>
    </div>

    <!-- API Documentation -->
    <div id="docs"></div>


    <script>
        const API_BASE_URL = '{{ url('/api') }}';
        let authToken = localStorage.getItem('api_token');
        let currentUser = null;


        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            showLoading('Initializing API Documentation...', 'Checking authentication status...');

            if (authToken) {
                validateToken();
            } else {
                hideLoading();
                showLoginOverlay();
            }
        });

        function showLoading(text = 'Loading...', subtext = '') {
            const loadingOverlay = document.getElementById('loading-overlay');
            const loadingText = document.getElementById('loading-text');

            loadingText.textContent = text;
            loadingOverlay.classList.remove('hidden');
        }

        function hideLoading() {
            const loadingOverlay = document.getElementById('loading-overlay');
            loadingOverlay.classList.add('hidden');
        }

        function showLoginOverlay() {
            document.getElementById('login-overlay').style.display = 'flex';
            document.getElementById('docs').classList.remove('authenticated');
            document.getElementById('auth-status').classList.remove('show');
            hideLoading();
        }

        function hideLoginOverlay() {
            document.getElementById('login-overlay').style.display = 'none';
            document.getElementById('docs').classList.add('authenticated');
            document.getElementById('auth-status').classList.add('show');

            // Show loading while initializing Scalar
            showLoading('Loading API Documentation...', 'Initializing Scalar API reference...');

            // Update Scalar with authentication after login
            setTimeout(() => {
                initializeScalar();
            }, 500);
        }

        async function handleLogin(event) {
            event.preventDefault();

            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const loginBtn = document.getElementById('login-btn');
            const messageDiv = document.getElementById('login-message');

            loginBtn.disabled = true;
            loginBtn.textContent = 'Logging in...';
            messageDiv.innerHTML = '';

            try {
                const response = await fetch(`${API_BASE_URL}/auth/login`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        email,
                        password
                    })
                });

                const data = await response.json();

                if (response.ok && data.access_token) {
                    authToken = data.access_token;
                    localStorage.setItem('api_token', authToken);

                    messageDiv.innerHTML = '<div class="success-message">Login successful!</div>';

                    // Show loading while getting user info
                    showLoading('Authenticating...', 'Retrieving user information...');

                    // Get user info
                    await getCurrentUser();

                    setTimeout(() => {
                        hideLoginOverlay();
                    }, 1000);
                } else {
                    messageDiv.innerHTML = `<div class="error-message">${data.message || 'Login failed'}</div>`;
                }
            } catch (error) {
                messageDiv.innerHTML = '<div class="error-message">Network error. Please try again.</div>';
                console.error('Login error:', error);
            } finally {
                loginBtn.disabled = false;
                loginBtn.textContent = 'Login';
            }
        }

        async function validateToken() {
            showLoading('Validating Authentication...', 'Checking your access token...');

            try {
                const response = await fetch(`${API_BASE_URL}/auth/whoami`, {
                    headers: {
                        'Authorization': `Bearer ${authToken}`,
                        'Accept': 'application/json',
                    }
                });

                if (response.ok) {
                    const userData = await response.json();
                    currentUser = userData;
                    updateAuthStatus();
                    hideLoginOverlay();

                    // Show loading while waiting for Scalar
                    showLoading('Loading API Documentation...', 'Preparing Scalar API reference...');

                    // Wait for Scalar to be available before initializing
                    waitForScalar();
                } else {
                    // Token is invalid
                    localStorage.removeItem('api_token');
                    authToken = null;
                    currentUser = null;
                    updateAuthStatus();
                    showLoginOverlay();
                }
            } catch (error) {
                console.error('Token validation error:', error);
                localStorage.removeItem('api_token');
                authToken = null;
                currentUser = null;
                updateAuthStatus();
                showLoginOverlay();
            }
        }

        function waitForScalar() {
            if (window.Scalar && window.Scalar.createApiReference) {
                showLoading('Loading API Documentation...', 'Initializing Scalar interface...');
                initializeScalar();
            } else {
                setTimeout(waitForScalar, 100);
            }
        }

        function initializeScalar() {
            const token = localStorage.getItem('api_token');
            const config = {
                spec: {
                    url: '{{ url('/api/docs/openapi/v2/record') }}'
                },
                "theme": "default",
                "expandAllResponses": true,
                "layout": "modern",
                "title": "API #1",
                "slug": "api-1",
                "hideClientButton": true,
                "showSidebar": true,
                "showToolbar": "localhost",
                "operationTitleSource": "summary",
                "persistAuth": false,
                "telemetry": true,
                "isEditable": false,
                "isLoading": false,
                "hideModels": false,
                "documentDownloadType": "both",
                "hideTestRequestButton": false,
                "hideSearch": false,
                "showOperationId": false,
                "hideDarkModeToggle": false,
                "withDefaultFonts": true,
                "defaultOpenAllTags": false,
                "expandAllModelSections": false,
                "orderSchemaPropertiesBy": "alpha",
                "orderRequiredPropertiesFirst": true,
            };

            if (token) {
                config.authentication = {
                    preferredSecurityScheme: 'bearerAuth',
                    bearerAuth: {
                        token: token
                    }
                };
            }

            try {
                const apiReference = window.Scalar.createApiReference(document.getElementById('docs'), config);

                // Hide loading after Scalar is initialized
                setTimeout(() => {
                    hideLoading();
                }, 1000);

                // Auto-fill authentication fields after Scalar loads
                if (token) {
                    setTimeout(() => {
                        // Look for authentication input fields and auto-fill them
                        const authSelectors = [
                            'input[placeholder*="Bearer"]',
                            'input[placeholder*="Authorization"]',
                            'input[placeholder*="Token"]',
                            'input[name*="bearer"]',
                            'input[name*="authorization"]',
                            'input[type="password"]'
                        ];

                        authSelectors.forEach(selector => {
                            const inputs = document.querySelectorAll(selector);
                            inputs.forEach(input => {
                                if (input.placeholder && (
                                        input.placeholder.toLowerCase().includes('bearer') ||
                                        input.placeholder.toLowerCase().includes('authorization') ||
                                        input.placeholder.toLowerCase().includes('token')
                                    )) {
                                    input.value = token;
                                    input.dispatchEvent(new Event('input', {
                                        bubbles: true
                                    }));
                                    input.dispatchEvent(new Event('change', {
                                        bubbles: true
                                    }));
                                }
                            });
                        });
                    }, 2000);
                }
            } catch (error) {
                console.error('Error initializing Scalar:', error);
                hideLoading();
            }
        }

        function updateScalarAuth() {
            // Re-initialize Scalar with new auth
            initializeScalar();
        }

        async function getCurrentUser() {
            try {
                const response = await fetch(`${API_BASE_URL}/auth/whoami`, {
                    headers: {
                        'Authorization': `Bearer ${authToken}`,
                        'Accept': 'application/json',
                    }
                });

                if (response.ok) {
                    currentUser = await response.json();
                    updateAuthStatus();
                }
            } catch (error) {
                console.error('Get user error:', error);
            }
        }

        function updateAuthStatus() {
            const userInfo = document.getElementById('auth-user-info');
            if (currentUser) {
                userInfo.textContent = `Logged in as: ${currentUser.name} (${currentUser.email})`;
            }
        }

        async function logout() {
            showLoading('Logging out...', 'Clearing your session...');

            try {
                if (authToken) {
                    await fetch(`${API_BASE_URL}/auth/logout`, {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${authToken}`,
                            'Accept': 'application/json',
                        }
                    });
                }
            } catch (error) {
                console.error('Logout error:', error);
            } finally {
                localStorage.removeItem('api_token');
                authToken = null;
                currentUser = null;

                setTimeout(() => {
                    showLoginOverlay();

                    // Clear form
                    document.getElementById('email').value = '';
                    document.getElementById('password').value = '';
                    document.getElementById('login-message').innerHTML = '';
                }, 500);
            }
        }



        // Handle page refresh - maintain auth state
        window.addEventListener('beforeunload', function() {
            if (authToken) {
                localStorage.setItem('api_token', authToken);
            }
        });
    </script>
</body>

</html>
