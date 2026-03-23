<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="googlebot" content="noindex">
    <title>{{ env('APP_NAME', 'SP Laravel Unified') }} - API Docs</title>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
    <style>
        html,
        body {
            height: 100%;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
        }

        #docs {
            height: 100%;
        }

        .auth-shell {
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            box-sizing: border-box;
        }

        .auth-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
            padding: 24px;
        }

        .auth-title {
            margin: 0 0 6px;
            font-size: 20px;
            color: #0f172a;
            font-weight: 700;
        }

        .auth-subtitle {
            margin: 0 0 18px;
            color: #475569;
            font-size: 14px;
        }

        .auth-label {
            display: block;
            margin: 0 0 6px;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
        }

        .auth-input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .auth-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }

        .auth-button {
            width: 100%;
            border: 0;
            border-radius: 8px;
            background: #4f46e5;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 12px;
            cursor: pointer;
        }

        .auth-button:disabled {
            opacity: .7;
            cursor: wait;
        }

        .auth-error {
            margin: 0 0 12px;
            color: #dc2626;
            font-size: 13px;
            display: none;
            white-space: pre-wrap;
        }

        .docs-toolbar {
            position: fixed;
            top: 12px;
            right: 12px;
            z-index: 9999;
            display: none;
            gap: 8px;
            align-items: center;
            background: rgba(15, 23, 42, 0.85);
            color: #fff;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
        }

        .docs-logout {
            border: 0;
            border-radius: 6px;
            padding: 6px 10px;
            background: #ef4444;
            color: #fff;
            font-size: 12px;
            cursor: pointer;
        }
    </style>
    <link rel="icon" href="{{ asset('favicon.png') }}" />
</head>

<body>
    @if (config('record.api_docs.is_private', false))
    <div id="docs-toolbar" class="docs-toolbar">
        <span id="docs-auth-state"></span>
        <button id="docs-logout" class="docs-logout" type="button">Logout</button>
    </div>
    <div id="auth-shell" class="auth-shell" style="display:none;">
        <div class="auth-card">
            <h1 class="auth-title">API Docs Login</h1>
            <p class="auth-subtitle">Sign in to load private API documentation.</p>
            <p id="auth-error" class="auth-error"></p>
            <form id="auth-form">
                {{-- <label class="auth-label" for="auth-endpoint">Login endpoint</label>
                <input id="auth-endpoint" class="auth-input" name="endpoint" type="text" value="{{ (string) config('record.api_docs.login_api', '/api/login') }}" required> --}}
                <label class="auth-label" for="auth-username">Username / Email</label>
                <input id="auth-username" class="auth-input" name="username" type="text" autocomplete="username" required>
                <label class="auth-label" for="auth-password">Password</label>
                <input id="auth-password" class="auth-input" name="password" type="password" autocomplete="current-password" required>
                <button id="auth-submit" class="auth-button" type="submit">Login</button>
            </form>
        </div>
    </div>
    @endif
    <div id="docs"></div>

    <script>
        const docsSettings = {
            isPrivate: {{ config('record.api_docs.is_private', false) ? 'true' : 'false' }},
            accessTokenKey: @json((string) config('record.api_docs.access_token_key', 'access_token')),
            loginApi: @json((string) config('record.api_docs.login_api', '/api/login')),
            email: @json((string) config('record.api_docs.email', '')),
            apiPrefix: @json(trim(config('record.api_prefix', 'api/v1'), '/')),
        };
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const tokenStorageKey = 'sp_api_docs_access_token';

        function persistToken(token) {
            localStorage.setItem(tokenStorageKey, token);
            syncScalarAuthTokenUi(token);
        }

        function clearPersistedToken() {
            localStorage.removeItem(tokenStorageKey);
        }

        function extractAndPersistTokenFromPayload(payload) {
            const token = resolveTokenFromPayload(payload, docsSettings.accessTokenKey);
            if (!token) {
                return false;
            }

            persistToken(token);
            return true;
        }

        function syncScalarAuthTokenUi(token, attempt = 0) {
            if (!token) {
                return;
            }
            const docsRoot = document.getElementById('docs');
            if (!docsRoot) {
                return;
            }

            const selectors = [
                'input[placeholder="Token"]',
                'input[aria-label*="token" i]',
                'input[name*="token" i]',
                'input[id*="token" i]',
            ];

            let updated = 0;
            for (const selector of selectors) {
                const inputs = docsRoot.querySelectorAll(selector);
                for (const input of inputs) {
                    if (!(input instanceof HTMLInputElement)) {
                        continue;
                    }
                    if (input.value === token) {
                        continue;
                    }
                    input.value = token;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'Enter' }));
                    updated++;
                }
            }

            if (updated === 0 && attempt < 8) {
                setTimeout(() => syncScalarAuthTokenUi(token, attempt + 1), 300);
            }
        }

        const nativeFetch = window.fetch.bind(window);

        document.addEventListener('DOMContentLoaded', function() {
            const token = localStorage.getItem(tokenStorageKey);
            if (!docsSettings.isPrivate) {
                clearPersistedToken();
                showDocsToolbar(false);
                initializeScalar();
                return;
            }

            if (token) {
                showDocsToolbar(true);
                initializeScalar();
                return;
            }

            showLoginForm();
        });

        function initializeScalar() {
            const config = {
                spec: {
                    url: '{{ url('/api-docs/openapi.json') }}'
                },
                "theme": "default",
                "expandAllResponses": true,
                "layout": "modern",
                "title": "API Documentation",
                "slug": "api-docs",
                "hideClientButton": false,
                "showSidebar": true,
                "showToolbar": true,
                "operationTitleSource": "summary",
                "persistAuth": true,
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

            try {
                window.Scalar.createApiReference(document.getElementById('docs'), config);
                const existingToken = localStorage.getItem(tokenStorageKey);
                if (existingToken) {
                    setTimeout(() => syncScalarAuthTokenUi(existingToken), 300);
                    setTimeout(() => syncScalarAuthTokenUi(existingToken), 1200);
                }
            } catch (error) {
                console.error('Error initializing Scalar:', error);
                document.getElementById('docs').innerHTML =
                    '<div style="padding: 2rem; text-align: center; color: #666;">Error loading API documentation. Please refresh the page.</div>';
            }
        }

        function showLoginForm() {
            const authShell = document.getElementById('auth-shell');
            if (!authShell) {
                initializeScalar();
                return;
            }
            authShell.style.display = 'flex';
            document.getElementById('docs').style.display = 'none';
            showDocsToolbar(false);
            bindLoginForm();
        }

        function showDocsToolbar(show) {
            const toolbar = document.getElementById('docs-toolbar');
            if (!toolbar) {
                return;
            }
            const state = document.getElementById('docs-auth-state');
            if (!show) {
                toolbar.style.display = 'none';
                return;
            }
            toolbar.style.display = 'flex';
            state.textContent = 'Authenticated';
            const logoutBtn = document.getElementById('docs-logout');
            if (!logoutBtn) {
                return;
            }
            logoutBtn.onclick = function() {
                if (docsSettings.isPrivate) {
                    nativeFetch('{{ url('/api-docs/auth/logout') }}', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                    }).catch(() => ({}));
                }
                clearPersistedToken();
                window.location.href = '{{ url('/api-docs') }}';
            };
        }

        function bindLoginForm() {
            const form = document.getElementById('auth-form');
            if (!form) {
                return;
            }
            const endpointInput = document.getElementById('auth-endpoint');
            const usernameInput = document.getElementById('auth-username');
            const passwordInput = document.getElementById('auth-password');
            if (endpointInput && !endpointInput.value.trim()) {
                endpointInput.value = docsSettings.loginApi || '/api/login';
            }

            form.addEventListener('submit', async function(event) {
                event.preventDefault();
                const submitButton = document.getElementById('auth-submit');
                const errorEl = document.getElementById('auth-error');
                errorEl.style.display = 'none';
                errorEl.textContent = '';
                submitButton.disabled = true;

                const endpointRaw = endpointInput ? endpointInput.value.trim() : (docsSettings.loginApi || '/api/login');
                const endpoint = normalizeLoginEndpoint(endpointRaw);
                const username = usernameInput instanceof HTMLInputElement ? usernameInput.value.trim() : '';
                const password = passwordInput instanceof HTMLInputElement ? passwordInput.value : '';

                try {
                    const response = await nativeFetch('{{ url('/api-docs/auth/login') }}', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            endpoint: endpoint,
                            email: username,
                            username: username,
                            password: password,
                        }),
                    });

                    const payload = await response.json().catch(() => ({}));
                    const token = resolveTokenFromPayload(payload, docsSettings.accessTokenKey);

                    if (!response.ok || !token) {
                        throw new Error(
                            (payload && (payload.message || payload.error)) || 'Invalid login response: token not found.'
                        );
                    }
                    persistToken(token);
                    document.getElementById('auth-shell').style.display = 'none';
                    document.getElementById('docs').style.display = 'block';
                    showDocsToolbar(true);
                    initializeScalar();
                } catch (error) {
                    errorEl.textContent = error instanceof Error ? error.message : 'Login failed';
                    errorEl.style.display = 'block';
                } finally {
                    submitButton.disabled = false;
                }
            });
        }

        function resolveTokenFromPayload(payload, accessTokenKey) {
            if (payload === null || payload === undefined) {
                return null;
            }

            if (typeof payload === 'object' && !Array.isArray(payload)) {
                if (typeof payload[accessTokenKey] === 'string' && payload[accessTokenKey] !== '') {
                    return payload[accessTokenKey];
                }
            }

            const stack = [payload];
            let guard = 0;
            while (stack.length > 0 && guard < 200) {
                guard++;
                const current = stack.shift();
                if (!current || typeof current !== 'object') {
                    continue;
                }

                if (!Array.isArray(current) && typeof current[accessTokenKey] === 'string' && current[accessTokenKey] !== '') {
                    return current[accessTokenKey];
                }

                if (Array.isArray(current)) {
                    for (const item of current) {
                        stack.push(item);
                    }
                } else {
                    for (const key in current) {
                        stack.push(current[key]);
                    }
                }
            }

            return null;
        }

        function normalizeLoginEndpoint(endpoint) {
            if (!endpoint) {
                return '/api/login';
            }
            if (endpoint.startsWith('http://') || endpoint.startsWith('https://')) {
                return endpoint;
            }
            if (endpoint.startsWith('/')) {
                return endpoint;
            }

            return '/' + endpoint;
        }

    </script>
</body>

</html>
