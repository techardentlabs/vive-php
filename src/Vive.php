<?php

declare(strict_types=1);

namespace Vive;

/** Every non-2xx response from the API. */
class ViveException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    /** Retrying this exact request may succeed. */
    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    public function code(): ?string
    {
        return $this->fields['reason'] ?? null;
    }
}

class AuthException extends ViveException {}
class RateLimitException extends ViveException {}
class ValidationException extends ViveException {}

class Vive
{
    private const DEFAULT_BASE_URL = 'https://app.getvive.ai';

    private string $apiKey;
    private string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        private int $timeout = 30,
        private int $maxRetries = 2,
    ) {
        $key = $apiKey ?? getenv('VIVE_API_KEY') ?: null;
        if (!$key) {
            throw new \InvalidArgumentException('Vive: an API key is required — pass one or set VIVE_API_KEY.');
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($baseUrl ?? (getenv('VIVE_BASE_URL') ?: self::DEFAULT_BASE_URL), '/');
    }

    /**
     * Send a free-form text message. WhatsApp only delivers these inside the 24-hour
     * service window, which opens when the contact messages you.
     */
    public function sendText(string $to, string $text, ?string $idempotencyKey = null): array
    {
        return $this->post('/v1/messages', ['to' => $to, 'type' => 'text', 'text' => $text], $idempotencyKey);
    }

    /** Send an approved template. Deliverable at any time, in or out of the window. */
    public function sendTemplate(
        string $to,
        string $templateName,
        ?string $templateLanguage = null,
        array $templateParams = [],
        ?string $category = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['to' => $to, 'type' => 'template', 'templateName' => $templateName];
        if ($templateLanguage !== null) {
            $body['templateLanguage'] = $templateLanguage;
        }
        if ($templateParams !== []) {
            $body['templateParams'] = $templateParams;
        }
        if ($category !== null) {
            $body['category'] = $category;
        }
        return $this->post('/v1/messages', $body, $idempotencyKey);
    }

    /**
     * Send an image, video, audio clip, document, or sticker. Supply $mediaUrl (an https
     * link WhatsApp can fetch) or $mediaId (already uploaded to Meta).
     */
    public function sendMedia(
        string $to,
        string $type,
        ?string $mediaUrl = null,
        ?string $mediaId = null,
        ?string $caption = null,
        ?string $filename = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['to' => $to, 'type' => $type];
        foreach (['mediaUrl' => $mediaUrl, 'mediaId' => $mediaId, 'caption' => $caption, 'filename' => $filename] as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }
        return $this->post('/v1/messages', $body, $idempotencyKey);
    }

    /**
     * Up to three quick-reply buttons, each ['id' => ..., 'title' => ...]. The tapped
     * button's id comes back on the webhook as replyId — route on that, not the title.
     */
    public function sendButtons(
        string $to,
        string $text,
        array $buttons,
        ?string $header = null,
        ?string $footer = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['to' => $to, 'type' => 'buttons', 'text' => $text, 'buttons' => $buttons];
        if ($header !== null) {
            $body['header'] = $header;
        }
        if ($footer !== null) {
            $body['footer'] = $footer;
        }
        return $this->post('/v1/messages', $body, $idempotencyKey);
    }

    /** A list picker. WhatsApp allows at most ten rows across all sections. */
    public function sendList(
        string $to,
        string $text,
        array $sections,
        ?string $buttonText = null,
        ?string $header = null,
        ?string $footer = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['to' => $to, 'type' => 'list', 'text' => $text, 'sections' => $sections];
        if ($buttonText !== null) {
            $body['buttonText'] = $buttonText;
        }
        if ($header !== null) {
            $body['header'] = $header;
        }
        if ($footer !== null) {
            $body['footer'] = $footer;
        }
        return $this->post('/v1/messages', $body, $idempotencyKey);
    }

    /** A message with a button that opens a URL. */
    public function sendCTA(
        string $to,
        string $text,
        string $ctaUrl,
        ?string $ctaText = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['to' => $to, 'type' => 'cta_url', 'text' => $text, 'ctaUrl' => $ctaUrl];
        if ($ctaText !== null) {
            $body['ctaText'] = $ctaText;
        }
        return $this->post('/v1/messages', $body, $idempotencyKey);
    }

    public function sendLocation(
        string $to,
        float $latitude,
        float $longitude,
        ?string $locationName = null,
        ?string $locationAddress = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['to' => $to, 'type' => 'location', 'latitude' => $latitude, 'longitude' => $longitude];
        if ($locationName !== null) {
            $body['locationName'] = $locationName;
        }
        if ($locationAddress !== null) {
            $body['locationAddress'] = $locationAddress;
        }
        return $this->post('/v1/messages', $body, $idempotencyKey);
    }

    /** React to a message. An empty emoji removes the reaction. */
    public function react(string $to, string $reactTo, string $emoji = ''): array
    {
        return $this->post('/v1/messages', [
            'to' => $to, 'type' => 'reaction', 'reactTo' => $reactTo, 'emoji' => $emoji,
        ], null);
    }

    private function post(string $path, array $body, ?string $idempotencyKey): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'User-Agent: vive-php/1.0',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep((int) (min(2 ** ($attempt - 1), 8) * 1_000_000));
            }

            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                $lastError = new ViveException(0, "Vive: request failed — {$curlError}");
                if ($attempt === $this->maxRetries) {
                    throw $lastError;
                }
                continue;
            }

            $envelope = json_decode((string) $raw, true) ?: [];
            if ($status >= 200 && $status < 300 && ($envelope['success'] ?? false)) {
                return $envelope['data'] ?? [];
            }

            $error = self::toError($status, $envelope);
            if (!$error->isRetryable() || $attempt === $this->maxRetries) {
                throw $error;
            }
            $lastError = $error;
        }

        throw $lastError ?? new ViveException(0, 'Vive: request failed');
    }

    private static function toError(int $status, array $envelope): ViveException
    {
        $message = $envelope['message'] ?? "Vive: request failed with status {$status}";
        $fields = $envelope['errors'] ?? [];

        return match (true) {
            $status === 401, $status === 403 => new AuthException($status, $message, $fields),
            $status === 429 => new RateLimitException($status, $message, $fields),
            $status >= 400 && $status < 500 => new ValidationException($status, $message, $fields),
            default => new ViveException($status, $message, $fields),
        };
    }

    /**
     * Verify the X-Vive-Signature header on an incoming webhook.
     *
     * Pass the raw request body — a re-encoded array produces different bytes and will not
     * match. Comparison is constant-time.
     */
    public static function verifyWebhookSignature(string $rawBody, ?string $signatureHeader, string $signingSecret): bool
    {
        if (!$signatureHeader || !$signingSecret) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $signingSecret);
        return hash_equals($expected, $signatureHeader);
    }

    /** Verify and decode a webhook. Throws if the signature does not match. */
    public static function parseWebhook(string $rawBody, ?string $signatureHeader, string $signingSecret): array
    {
        if (!self::verifyWebhookSignature($rawBody, $signatureHeader, $signingSecret)) {
            throw new ViveException(400, 'Vive: webhook signature verification failed');
        }
        return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    }
}
