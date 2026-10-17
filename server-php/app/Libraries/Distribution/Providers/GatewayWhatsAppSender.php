<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Gateways\WhatsappSender;

/**
 * WhatsApp via Infobip. Campaign (marketing) messages go out as approved
 * templates; a message without a template is refused, because a free-form
 * text to someone outside the 24-hour customer-service window is against
 * WhatsApp Business policy and is rejected by Meta.
 */
class GatewayWhatsAppSender implements WhatsAppSenderInterface
{
    public function send(ChannelMessage $message): ProviderReceipt
    {
        $phone    = $this->resolveRecipient($message);
        $template = trim((string) ($message->templateId ?? $message->metadata['template_name'] ?? $message->metadata['template_id'] ?? ''));
        if ($template === '') {
            throw new \RuntimeException('WhatsApp campaign messages must use an approved template (template_name).', 422);
        }

        // WhatsappSender returns "true" under WHATSAPP_SKIP_SEND without calling
        // Infobip. That is not a send.
        if (WhatsappSender::isSendDisabled()) {
            throw new SendSkippedException('WHATSAPP_SKIP_SEND is on — WhatsApp message not sent. Set WHATSAPP_SKIP_SEND=false to send for real.');
        }

        $params = $message->templateVars;
        if ($params === [] && is_array($message->metadata['template_params'] ?? null)) {
            $params = $message->metadata['template_params'];
        }
        $language = (string) ($message->metadata['language'] ?? 'en');

        $messageId = WhatsappSender::sendTemplate($phone, $template, array_values($params), $language, $message->idempotencyKey);
        if ($messageId === null) {
            $http = WhatsappSender::getLastHttpCode();
            throw new \RuntimeException(
                'WhatsApp gateway did not accept the message: ' . (WhatsappSender::getLastError() ?? 'unknown error'),
                $http === 429 ? 429 : ($http >= 400 && $http < 500 ? 400 : 502),
            );
        }

        return new ProviderReceipt(
            providerMessageId: $messageId,
            status:            'accepted',
            acceptedAt:        new \DateTimeImmutable(),
        );
    }

    public function getTemplates(): array
    {
        return [];
    }

    public function getTemplateStatus(string $templateId): array
    {
        return ['template_id' => $templateId, 'status' => 'unknown'];
    }

    public function getStatus(string $providerMessageId): ProviderStatus
    {
        // No delivery-report polling yet: we only know Infobip accepted it.
        return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'accepted');
    }

    public function getCapabilities(): array
    {
        return [
            'template_required' => true,
            'messaging_window'  => 24,
            'media_types'       => ['text'],
            'provider_name'     => 'infobip_whatsapp',
        ];
    }

    public function isEnabled(): bool
    {
        return WhatsappSender::isConfigured() && !WhatsappSender::isSendDisabled();
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return false; // delivery-report webhooks are not wired yet
    }

    public function providerName(): string
    {
        return 'infobip_whatsapp';
    }

    /** Exactly the number on the message — no broadcast placeholder, no test-number fallback. */
    private function resolveRecipient(ChannelMessage $message): string
    {
        $phone = WhatsappSender::normalizePhone($message->recipientAddress);
        if (strlen($phone) >= 10) {
            return $phone;
        }

        throw new \RuntimeException('Invalid WhatsApp recipient number.', 422);
    }
}
