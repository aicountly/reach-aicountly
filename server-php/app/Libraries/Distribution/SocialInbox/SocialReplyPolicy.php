<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\SocialInbox;

/**
 * The last checks a reply passes before it is posted, after vetting, plus the
 * fixed texts used where Reach never drafts freely (complaint
 * acknowledgements, privacy nudges). Pure, so the rules are unit-tested.
 */
final class SocialReplyPolicy
{
    public const SIGN_OFF = '— Aicountly team';

    /** Character limit per network (X counts 280; the others allow far more, Reach keeps replies short). */
    public static function limit(?string $network): int
    {
        return $network === 'x' ? 280 : 600;
    }

    /**
     * The reply as it will be posted, or a reason it must not be.
     *
     * @return array{ok: bool, text: string, reason: ?string}
     */
    public static function finalize(string $text, ?string $network): array
    {
        $t = trim(preg_replace('/[ \t]+/u', ' ', strip_tags($text)) ?? '');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        if ($t === '') {
            return ['ok' => false, 'text' => '', 'reason' => 'The reply is empty.'];
        }
        if (stripos($t, 'Aicountly team') === false) { // an older "— AICOUNTLY team" counts too
            $t = rtrim($t) . ' ' . self::SIGN_OFF;
        }
        $limit = self::limit($network);
        if (mb_strlen($t) > $limit) {
            return ['ok' => false, 'text' => $t, 'reason' => sprintf('The reply is %d characters; the limit for %s is %d.', mb_strlen($t), $network ?? 'this network', $limit)];
        }
        $pii = self::personalData($t);
        if ($pii !== null) {
            return ['ok' => false, 'text' => $t, 'reason' => 'The reply contains ' . $pii . ' — never posted.'];
        }
        foreach (self::links($t) as $host) {
            if ($host !== 'aicountly.com' && ! str_ends_with($host, '.aicountly.com')) {
                return ['ok' => false, 'text' => $t, 'reason' => 'The reply links to ' . $host . ' — only aicountly.com links are allowed.'];
            }
        }
        return ['ok' => true, 'text' => $t, 'reason' => null];
    }

    /** What kind of personal data a text carries (our own support@ address excepted), or null. */
    public static function personalData(string $text): ?string
    {
        if (preg_match('/\b[A-Z]{5}[0-9]{4}[A-Z]\b/', $text)) {
            return 'something that looks like a PAN';
        }
        if (preg_match('/\b\d{4}[ -]?\d{4}[ -]?\d{4}\b/', $text)) {
            return 'something that looks like an Aadhaar or card number';
        }
        if (preg_match('/(?<!\d)(?:\+?91[ -]?)?[6-9]\d{4}[ -]?\d{5}(?!\d)/', $text)) {
            return 'a phone number';
        }
        if (preg_match_all('/[A-Z0-9._%+\-]+@([A-Z0-9.\-]+\.[A-Z]{2,})/i', $text, $m)) {
            foreach ($m[1] as $domain) {
                $d = strtolower($domain);
                if ($d !== 'aicountly.com' && ! str_ends_with($d, '.aicountly.com')) {
                    return 'an e-mail address';
                }
            }
        }
        return null;
    }

    /** @return list<string> hosts of the links in a text (e-mail domains excluded) */
    public static function links(string $text): array
    {
        preg_match_all('#(https?://)?((?:[a-z0-9\-]+\.)+([a-z]{2,}))(/[^\s]*)?#i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tlds  = ['com', 'in', 'org', 'net', 'io', 'ai', 'co', 'ly', 'me', 'app', 'dev', 'xyz', 'info', 'biz', 'gl', 'gg', 'to', 'us', 'uk', 'site', 'online', 'link', 'page'];
        $hosts = [];
        foreach ($m as $hit) {
            $offset = $hit[0][1];
            if ($offset > 0 && $text[$offset - 1] === '@') {
                continue; // the domain of an e-mail address
            }
            $host = strtolower($hit[2][0]);
            $isLink = ($hit[1][0] ?? '') !== '' || str_starts_with($host, 'www.') || (($hit[4][0] ?? '') !== '' && strlen($hit[4][0]) > 1)
                || in_array(strtolower($hit[3][0]), $tlds, true);
            if ($isLink) {
                $hosts[] = preg_replace('/^www\./', '', $host) ?? $host;
            }
        }
        return array_values(array_unique($hosts));
    }

    /** The public acknowledgement of a complaint, quoting the Flow ticket. */
    public static function complaintAck(string $ticketNumber, string $kind): string
    {
        return $kind === 'message'
            ? "We're sorry about this. We've logged it as {$ticketNumber} and our support team will follow up. You can also email support@aicountly.com quoting {$ticketNumber}. " . self::SIGN_OFF
            : "We're sorry about this. We've logged it as {$ticketNumber} and our support team will follow up. Please send us a direct message or email support@aicountly.com quoting {$ticketNumber} (no account details here, please). " . self::SIGN_OFF;
    }

    /** The reply to a message that shares personal data / a private matter. */
    public static function privacyNudge(string $kind): string
    {
        return $kind === 'message'
            ? 'Thanks for reaching out. Please never send PAN, Aadhaar, passwords or OTPs here — email support@aicountly.com and our team will help securely. ' . self::SIGN_OFF
            : 'Thanks for reaching out. For your privacy, please do not share personal or account details in public comments — send us a direct message or email support@aicountly.com and our team will help. ' . self::SIGN_OFF;
    }
}
