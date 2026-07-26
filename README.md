# vive/sdk

Official PHP client for the [Vive](https://app.getvive.ai) WhatsApp messaging API.

```bash
composer require vive/sdk
```

```php
use Vive\Vive;

$vive = new Vive(); // reads VIVE_API_KEY
$message = $vive->sendText('15551234567', 'Your order shipped.', 'order-4417-shipped');
```

- All ten message types: text, template, media, buttons, list, CTA, location, reaction
- `ViveException` / `AuthException` / `RateLimitException` / `ValidationException`
- Automatic retry of `429` and `5xx`; never retries `4xx`
- Constant-time webhook signature verification

Full documentation: [docs](../../docs).
