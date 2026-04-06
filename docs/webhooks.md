# Webhooks

The `sp-laravel-api` package includes a built-in, queue-driven Webhook module. This allows external systems to subscribe to real-time data changes (CRUD events) within your application.

## 1. Setup & Configuration

By default, the webhook module is disabled to save resources. To enable it, add the following to your `.env` file:

```env
SP_LARAVEL_API_WEBHOOKS_ENABLED=true
SP_LARAVEL_API_WEBHOOKS_QUEUE=default
```

### Run Migrations
Since webhooks require database tables to store endpoints, subscriptions, and delivery logs, you must run the migrations:

```bash
php artisan migrate
```

### Queue Worker
Webhooks are dispatched asynchronously to prevent blocking your API responses. Ensure your queue worker is running:

```bash
php artisan queue:work
```

---

## 2. Managing Webhooks via API

Because the webhook tables are registered using the core `RecordTableType` engine, you automatically get full CRUD APIs to manage them.

### A. Create an Endpoint
First, register the external URL that will receive the webhooks.

**POST** `/api/sp_webhook_endpoints`
```json
{
    "name": "Zapier Integration",
    "url": "https://hooks.zapier.com/hooks/catch/123456/abcde/",
    "secret": "your_super_secret_key_for_hmac",
    "is_active": true
}
```

### B. Subscribe to Events
Next, tell the system which events this endpoint should listen to.

**POST** `/api/sp_webhook_subscriptions`
```json
{
    "endpoint_id": "uuid-of-the-endpoint-created-above",
    "table_name": "invoices",
    "event": "created"
}
```

**Wildcards:**
You can use `*` to listen to all tables or all events.
*   `table_name: "*", event: "created"` (Listen to all creations)
*   `table_name: "users", event: "*"` (Listen to all user changes)

---

## 3. How it Works (The Flow)

1. A user creates a new invoice via `POST /api/invoices`.
2. The core `RecordService` saves the invoice to the database.
3. The `WebhookTrigger` detects the `afterCreate` event.
4. It finds the subscription for `invoices.created`.
5. It dispatches a `DispatchWebhookJob` to the queue.
6. The queue worker picks up the job and sends an HTTP POST request to the Zapier URL.

---

## 4. Receiving Webhooks (For External Systems)

When the external system receives the webhook, the payload will look like this:

**Headers:**
```http
Content-Type: application/json
X-Webhook-Event: invoices.created
X-Webhook-Signature: 8f434346648f6b96df89dda901c5176b10a6d83961dd3c1ac88b59b2dc327aa4
```

**Body:**
```json
{
    "id": "123",
    "amount": 500.00,
    "status": "draft",
    "created_at": "2024-01-01T12:00:00.000000Z"
}
```

### Verifying the Signature (Security)
To ensure the webhook actually came from your application, the receiving server should verify the HMAC SHA256 signature using the `secret` defined in the endpoint.

**PHP Example (Receiver Side):**
```php
$payload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];
$secret = 'your_super_secret_key_for_hmac';

$expectedSignature = hash_hmac('sha256', $payload, $secret);

if (hash_equals($expectedSignature, $signatureHeader)) {
    // Valid! Process the webhook
} else {
    // Invalid signature! Reject the request
    http_response_code(401);
    exit;
}
```

---

## 5. Debugging & Audit Logs

Every webhook attempt is logged in the `sp_webhook_deliveries` table. You can view these logs via the API to debug failed webhooks.

**GET** `/api/sp_webhook_deliveries?endpoint_id=eq.uuid-here`

This will return the exact payload sent, the HTTP status code returned by the external server (e.g., 200, 500), and the response body.

*Note: The deliveries table is read-only via the API for security and auditing purposes.*
