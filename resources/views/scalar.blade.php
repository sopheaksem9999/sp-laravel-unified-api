<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
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
        }

        #docs {
            height: 100%;
        }
    </style>
    <link rel="icon" href="{{ asset('favicon.png') }}" />
</head>

<body>
    <!-- API Documentation -->
    <div id="docs"></div>

    <script>
        // Initialize Scalar API documentation
        document.addEventListener('DOMContentLoaded', function() {
            initializeScalar();
        });

        function initializeScalar() {
            const config = {
                spec: {
                    url: '{{ url(trim(config('record.api_prefix', 'api/v1'), '/') . '/docs/openapi.json') }}'
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
            } catch (error) {
                console.error('Error initializing Scalar:', error);
                document.getElementById('docs').innerHTML =
                    '<div style="padding: 2rem; text-align: center; color: #666;">Error loading API documentation. Please refresh the page.</div>';
            }
        }
    </script>
</body>

</html>
