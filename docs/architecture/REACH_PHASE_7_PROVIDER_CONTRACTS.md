# Phase 7 — Provider Contracts

**Date:** 2026-07-15

---

## Overview

Phase 7 introduces four channel provider interfaces. All production adapters are **disabled by default**. Mock providers are the CI default for all channels.

---

## Interface locations

All interfaces live in `server-php/app/Libraries/Distribution/Providers/`.

---

## Shared DTOs

```php
// Outbound
final class ChannelMessage {
    string $idempotencyKey;
    string $recipientAddress;
    string $content;
    ?string $templateId;
    array $templateVars;
    array $mediaRefs;
    array $metadata;
}

// Receipt
final class ProviderReceipt {
    string $providerMessageId;
    ?string $remoteUrl;
    string $status;      // accepted|rejected|rate_limited|failed
    ?string $rawResponse;
    \DateTimeImmutable $acceptedAt;
}

// Status
final class ProviderStatus {
    string $providerMessageId;
    string $normalisedStatus; // sent|delivered|read|failed|bounced|complained|unsubscribed
    ?\DateTimeImmutable $statusAt;
    ?string $failureClass;   // permanent|transient|rate_limit|rejected|unknown
    array $raw;
}

// Error
final class ProviderError {
    string $code;
    string $message;
    string $failureClass; // permanent|transient|rate_limit|rejected|unknown
    bool $shouldRetry;
}
```

---

## SocialPublisherInterface

```php
interface SocialPublisherInterface {
    public function publish(ChannelMessage $message, string $platform): ProviderReceipt;
    public function getStatus(string $providerPostId): ProviderStatus;
    public function withdraw(string $providerPostId): bool;
    public function getCapabilities(): array; // platforms, media_types, char_limits
    public function isEnabled(): bool;
    public function verifyCallback(array $headers, string $rawBody): bool;
    public function providerName(): string;
}
```

**Environment variable:** `SOCIAL_PROVIDERS_ENABLED` (comma-separated platform list)

---

## EmailSenderInterface

```php
interface EmailSenderInterface {
    public function send(ChannelMessage $message): ProviderReceipt;
    public function sendBatch(array $messages): array; // ProviderReceipt[]
    public function getStatus(string $providerMessageId): ProviderStatus;
    public function getCapabilities(): array; // batch_size, rate_limit, features
    public function isEnabled(): bool;
    public function verifyCallback(array $headers, string $rawBody): bool;
    public function providerName(): string;
}
```

**Environment variable:** `EMAIL_PROVIDER` (e.g. `mailgun`, `ses`)

---

## WhatsAppSenderInterface

```php
interface WhatsAppSenderInterface {
    public function send(ChannelMessage $message): ProviderReceipt;
    public function getTemplates(): array;
    public function getTemplateStatus(string $templateId): array;
    public function getStatus(string $providerMessageId): ProviderStatus;
    public function getCapabilities(): array; // template_required, messaging_window, media_types
    public function isEnabled(): bool;
    public function verifyCallback(array $headers, string $rawBody): bool;
    public function providerName(): string;
}
```

**Environment variable:** `WHATSAPP_PROVIDER` (e.g. `cloudapi`)

---

## SmsSenderInterface

```php
interface SmsSenderInterface {
    public function send(ChannelMessage $message): ProviderReceipt;
    public function getStatus(string $providerMessageId): ProviderStatus;
    public function getCapabilities(): array; // dlt_required, char_limit, encoding
    public function isEnabled(): bool;
    public function verifyCallback(array $headers, string $rawBody): bool;
    public function providerName(): string;
}
```

**Environment variable:** `SMS_PROVIDER` (e.g. `2factor`, `twilio`)

---

## ChannelProviderFactory

```php
class ChannelProviderFactory {
    public static function makeSocialPublisher(): SocialPublisherInterface;
    public static function makeEmailSender(): EmailSenderInterface;
    public static function makeWhatsAppSender(): WhatsAppSenderInterface;
    public static function makeSmsSender(): SmsSenderInterface;
}
```

Returns mock by default if the respective `*_PROVIDER` env is unset or blank.

---

## DistributionCallbackAuthenticator

Extends the Phase 6 `VideoCallbackAuthenticator` pattern:

1. Extract signature from header (`X-Distribution-Signature`)
2. Verify `sha256=` prefix
3. Compute `hash_hmac('sha256', $rawBody, $connectionSecret)`
4. Compare via `hash_equals`
5. Parse timestamp from header, verify within ±300s tolerance
6. Check `provider_event_id` uniqueness in `reach_campaign_provider_events`

---

## Mock provider behaviour

All mocks are deterministic — results depend on `idempotencyKey` hash, not randomness.

| Scenario | Trigger |
|----------|---------|
| Success | Default |
| Rate limit | idempotencyKey contains `rate_limit` |
| Transient failure | idempotencyKey contains `transient_fail` |
| Permanent rejection | idempotencyKey contains `perm_fail` |
| Duplicate | Same idempotencyKey submitted twice → same receipt |

---

## Production adapter prerequisites

| Channel | Adapter class | Env required | Deployment prerequisite |
|---------|--------------|--------------|------------------------|
| LinkedIn | `LinkedInPublisherAdapter` | `SOCIAL_PROVIDERS_ENABLED=linkedin` + `LINKEDIN_*` | LinkedIn App review, OAuth tokens |
| X (Twitter) | `XPublisherAdapter` | `SOCIAL_PROVIDERS_ENABLED=x` + `X_*` | X Developer App, API v2 access |
| Facebook | `FacebookPublisherAdapter` | `SOCIAL_PROVIDERS_ENABLED=facebook` + `FB_*` | Meta App, page tokens |
| Instagram | `InstagramPublisherAdapter` | `SOCIAL_PROVIDERS_ENABLED=instagram` + `IG_*` | Meta App, IG Business account |
| Email | `MailgunEmailAdapter` | `EMAIL_PROVIDER=mailgun` + `MAILGUN_*` | Mailgun account, domain verification |
| WhatsApp | `CloudApiWhatsAppAdapter` | `WHATSAPP_PROVIDER=cloudapi` + `WHATSAPP_*` | WABA approval, verified number |
| SMS | `TwoFactorSmsAdapter` | `SMS_PROVIDER=2factor` + `TWOFACTOR_*` | 2Factor account, DLT registration |
