<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Launch prompt set for AI-visibility (GEO) monitoring: the buyer questions
 * people put to AI assistants when choosing accounting / billing / inventory
 * software — India first, then the SE Asia, Middle East and Japan markets the
 * expansion plan tests — plus the competitors and aliases the answer parser
 * looks for.
 *
 * Insert-only and idempotent: a prompt (same tenant + name), competitor (same
 * tenant + name) or alias already present is left as an operator edited it.
 * Each seeded prompt gets an approved, active version 1; later edits go
 * through the normal version → approve flow in Intelligence → Prompt Library.
 */
class SeedAiVisibilityPrompts extends Migration
{
    /** @var list<array{0:string,1:string,2:string,3:string,4:string}> name, locale, market, intent, question */
    public const PROMPTS = [
        // India
        ['in-best-accounting-smb', 'en-IN', 'India', 'category', 'What is the best accounting software for a small business in India?'],
        ['in-gst-billing', 'en-IN', 'India', 'category', 'Which is the best GST billing software in India for small businesses?'],
        ['in-inventory-traders', 'en-IN', 'India', 'category', 'Recommend inventory management software for traders and wholesalers in India.'],
        ['in-tally-alternative', 'en-IN', 'India', 'alternative', 'What are the best Tally alternatives for small businesses in India?'],
        ['in-free-invoicing-app', 'en-IN', 'India', 'category', 'What is the best free invoicing app for small businesses in India?'],
        ['in-cloud-accounting-ca', 'en-IN', 'India', 'category', 'Which cloud accounting software do chartered accountants in India recommend for their clients?'],
        ['in-ai-accounting', 'en-IN', 'India', 'category', 'Is there AI-powered accounting software built for Indian businesses? Which ones are best?'],
        ['in-einvoice-ewaybill', 'en-IN', 'India', 'category', 'Which software is best for GST e-invoicing and e-way bills for an Indian SME?'],
        ['in-retail-billing', 'en-IN', 'India', 'category', 'Best billing software for a retail shop in India?'],
        ['in-billing-inventory-combo', 'en-IN', 'India', 'category', 'Which app can manage both billing and inventory for a small business in India?'],
        ['in-zoho-vs-tally', 'en-IN', 'India', 'comparison', 'Zoho Books vs Tally vs other options: which accounting software should an Indian SME choose in 2026?'],
        ['in-hinglish-accounting', 'hi-IN', 'India', 'category', 'Chhote business ke liye sabse accha accounting aur GST billing software kaunsa hai?'],
        ['in-document-storage-accountants', 'en-IN', 'India', 'category', 'What is a secure cloud document storage option for accountants and small firms in India?'],
        ['brand-what-is-aicountly', 'en-IN', 'India', 'brand', 'What is AICOUNTLY and is it good for small businesses?'],
        // South-East Asia
        ['sg-best-accounting-smb', 'en-SG', 'Singapore', 'category', 'What is the best accounting software for small businesses in Singapore?'],
        ['my-invoicing-sst', 'en-MY', 'Malaysia', 'category', 'Which invoicing and accounting software is best for SMEs in Malaysia with e-invoicing (MyInvois) support?'],
        ['id-ph-affordable-accounting', 'en-ID', 'Indonesia / Philippines', 'category', 'What affordable accounting software do SMEs in Indonesia and the Philippines use?'],
        // Middle East
        ['ae-vat-accounting', 'en-AE', 'UAE', 'category', 'What is the best VAT-compliant accounting software for small businesses in the UAE?'],
        ['sa-zatca-einvoicing', 'en-SA', 'Saudi Arabia', 'category', 'Which accounting and invoicing software is best for SMEs in Saudi Arabia that need ZATCA e-invoicing?'],
        // Japan
        ['jp-cloud-accounting', 'en-JP', 'Japan', 'category', 'What is the best cloud accounting software for small businesses in Japan?'],
        ['jp-qualified-invoice', 'en-JP', 'Japan', 'category', 'Which invoicing software supports Japan\'s qualified invoice system for small businesses?'],
        // Global
        ['global-ai-accounting', 'en', 'Global', 'category', 'What is the best AI-powered accounting software for small businesses?'],
        ['global-free-accounting-freelancers', 'en', 'Global', 'category', 'What is the best free accounting and invoicing software for freelancers?'],
        ['global-quickbooks-alternatives', 'en', 'Global', 'alternative', 'What are the best QuickBooks alternatives for small businesses outside the US?'],
    ];

    /** @var list<array{0:string,1:string,2:list<array{0:string,1:string}>}> name, domain, [alias_type, alias_value] */
    public const COMPETITORS = [
        ['Tally', 'tallysolutions.com', [['product', 'TallyPrime'], ['product', 'Tally Prime'], ['product', 'Tally ERP'], ['product', 'Tally.ERP 9'], ['domain', 'tallysolutions.com']]],
        ['Zoho Books', 'zoho.com', [['brand', 'Zoho'], ['product', 'Zoho Invoice'], ['product', 'Zoho Inventory']]],
        ['Vyapar', 'vyaparapp.in', [['product', 'Vyapar App'], ['domain', 'vyaparapp.in']]],
        ['Busy', 'busy.in', [['product', 'BUSY Accounting'], ['domain', 'busy.in']]],
        ['Marg ERP', 'margcompusoft.com', [['brand', 'Marg'], ['domain', 'margcompusoft.com']]],
        ['myBillBook', 'mybillbook.in', [['product', 'my Bill Book'], ['domain', 'mybillbook.in']]],
        ['ClearTax', 'cleartax.in', [['domain', 'cleartax.in']]],
        ['QuickBooks', 'quickbooks.intuit.com', [['product', 'QuickBooks Online'], ['domain', 'quickbooks.intuit.com']]],
        ['Khatabook', 'khatabook.com', [['product', 'Khata Book'], ['domain', 'khatabook.com']]],
        ['Swipe', 'getswipe.in', [['product', 'Swipe Invoice'], ['domain', 'getswipe.in']]],
        ['Xero', 'xero.com', [['domain', 'xero.com']]],
        ['FreshBooks', 'freshbooks.com', [['domain', 'freshbooks.com']]],
        ['Odoo', 'odoo.com', [['domain', 'odoo.com']]],
        ['Sage', 'sage.com', [['product', 'Sage Accounting'], ['domain', 'sage.com']]],
        ['Wave', 'waveapps.com', [['product', 'Wave Accounting'], ['domain', 'waveapps.com']]],
        ['Refrens', 'refrens.com', [['domain', 'refrens.com']]],
        ['freee', 'freee.co.jp', [['domain', 'freee.co.jp']]],
        ['Money Forward', 'moneyforward.com', [['product', 'MoneyForward'], ['domain', 'moneyforward.com']]],
    ];

    public function up(): void
    {
        foreach (self::PROMPTS as [$name, $locale, $market, $intent, $question]) {
            $existing = $this->db->query('SELECT id FROM reach_ai_visibility_prompts WHERE tenant_id = 1 AND name = ?', [$name])->getRowArray();
            if ($existing) {
                continue;
            }
            $this->db->query(
                "INSERT INTO reach_ai_visibility_prompts (tenant_id, name, topic, intent, persona, locale, purpose, schedule_cron, status)
                 VALUES (1, ?, ?, ?, ?, ?, 'ai_visibility_monitoring', 'daily', 'active')",
                [$name, mb_substr($question, 0, 240), $intent, $market, $locale]
            );
            $promptId = (int) $this->db->insertID();
            $this->db->query(
                'INSERT INTO reach_ai_visibility_prompt_versions (prompt_id, version_number, prompt_text, content_hash, is_active, approved_at)
                 VALUES (?, 1, ?, ?, TRUE, NOW())',
                [$promptId, $question, hash('sha256', $question)]
            );
        }

        foreach (self::COMPETITORS as [$name, $domain, $aliases]) {
            $this->db->query(
                "INSERT INTO reach_competitors (tenant_id, name, website_domain, category, monitoring_enabled, monitoring_status, notes)
                 VALUES (1, ?, ?, 'accounting_saas', TRUE, 'active', 'Seeded for AI-visibility monitoring.')
                 ON CONFLICT (tenant_id, name) DO NOTHING",
                [$name, $domain]
            );
            $row = $this->db->query('SELECT id FROM reach_competitors WHERE tenant_id = 1 AND name = ?', [$name])->getRowArray();
            if (! $row) {
                continue;
            }
            foreach ($aliases as [$type, $value]) {
                // An alias another competitor already owns is skipped, not reassigned.
                $this->db->query(
                    'INSERT INTO reach_competitor_aliases (competitor_id, alias_type, alias_value)
                     SELECT ?, ?, ?
                     WHERE NOT EXISTS (SELECT 1 FROM reach_competitor_aliases WHERE LOWER(alias_value) = LOWER(?))',
                    [(int) $row['id'], $type, $value, $value]
                );
            }
        }
    }

    public function down(): void
    {
        $names = array_map(static fn (array $p): string => $p[0], self::PROMPTS);
        // Only prompts that never ran are removed; run history is kept.
        $this->db->query(
            'DELETE FROM reach_ai_visibility_prompt_versions v
              USING reach_ai_visibility_prompts p
              WHERE v.prompt_id = p.id AND p.tenant_id = 1 AND p.name IN ?
                AND NOT EXISTS (SELECT 1 FROM reach_ai_visibility_runs r WHERE r.prompt_version_id = v.id)',
            [$names]
        );
        $this->db->query(
            'DELETE FROM reach_ai_visibility_prompts p
              WHERE p.tenant_id = 1 AND p.name IN ?
                AND NOT EXISTS (SELECT 1 FROM reach_ai_visibility_prompt_versions v WHERE v.prompt_id = p.id)',
            [$names]
        );
        // Competitors are shared with the SEO Tracker and keep their aliases.
    }
}
