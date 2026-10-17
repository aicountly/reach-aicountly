<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Enums\SuppressionReason;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\SuppressionService;
use App\Libraries\Distribution\UnsubscribeLinks;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public one-click unsubscribe for campaign email (no login). The link is
 * HMAC-signed per address (UnsubscribeLinks); a valid hit adds the address to
 * the email suppression list, which every later audience snapshot excludes.
 * GET and POST both work (POST is what mail clients use for one-click).
 */
class UnsubscribeController extends Controller
{
    public function unsubscribe(): ResponseInterface
    {
        $req      = $this->request;
        $verified = UnsubscribeLinks::verify(
            (string) ($req->getGetPost('t') ?? ''),
            (string) ($req->getGetPost('e') ?? ''),
            (string) ($req->getGetPost('s') ?? ''),
        );
        if ($verified === null) {
            return $this->page(400, 'This unsubscribe link is not valid', 'The link may be incomplete. Reply to the email and ask to be removed, and we will do it by hand.');
        }

        $audit = new AuditLogger();
        (new SuppressionService(new ChannelSuppressionModel(), $audit))->suppress(
            $verified['tenant_id'],
            'email',
            $verified['email'],
            SuppressionReason::Unsubscribe,
            'unsubscribe_link',
            null,
        );
        $audit->record(AuditLogger::DISTRIBUTION_EMAIL_UNSUBSCRIBED, [
            'address_masked' => ChannelSuppressionModel::maskAddress($verified['email']),
            'source'         => 'unsubscribe_link',
        ], null);

        return $this->page(200, 'You are unsubscribed', 'You will not receive further AICOUNTLY marketing email at ' . ChannelSuppressionModel::maskAddress($verified['email']) . '.');
    }

    private function page(int $status, string $title, string $body): ResponseInterface
    {
        $t = htmlspecialchars($title, ENT_QUOTES);
        $b = htmlspecialchars($body, ENT_QUOTES);

        return $this->response->setStatusCode($status)->setContentType('text/html')->setBody(
            "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            . "<title>{$t}</title></head><body style=\"font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;color:#222\">"
            . "<h1 style=\"font-size:1.4rem\">{$t}</h1><p>{$b}</p></body></html>"
        );
    }
}
