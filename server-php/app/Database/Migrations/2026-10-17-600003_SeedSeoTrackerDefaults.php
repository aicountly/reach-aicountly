<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Launch defaults for the SEO Tracker: the Indian SMB accounting / billing /
 * inventory competitors we measure ourselves against, and the keywords the
 * launch apps (Smart Books, Billing, Inventory, Notes, Drive) and the brand
 * need to win.
 *
 * Insert-only: an existing competitor (same tenant + name) or keyword (same
 * text, case-insensitive) is left exactly as an operator configured it, so
 * re-running never overwrites edits. Positions are not seeded — only a real
 * SERP check writes those.
 */
class SeedSeoTrackerDefaults extends Migration
{
    /** @var list<array{0:string,1:string}> name, domain */
    public const COMPETITORS = [
        ['Tally', 'tallysolutions.com'],
        ['Zoho Books', 'zoho.com'],
        ['Vyapar', 'vyaparapp.in'],
        ['Busy', 'busy.in'],
        ['Marg ERP', 'margcompusoft.com'],
        ['myBillBook', 'mybillbook.in'],
        ['ClearTax', 'cleartax.in'],
        ['QuickBooks', 'quickbooks.intuit.com'],
        ['Khatabook', 'khatabook.com'],
        ['Swipe', 'getswipe.in'],
    ];

    /** @var list<array{0:string,1:string,2:string}> keyword, product, priority */
    public const KEYWORDS = [
        // Smart Books (accounting)
        ['accounting software for small business india', 'smart_books', 'high'],
        ['gst accounting software', 'smart_books', 'high'],
        ['online accounting software india', 'smart_books', 'high'],
        ['cloud accounting software', 'smart_books', 'medium'],
        ['tally alternative', 'smart_books', 'high'],
        ['free accounting software india', 'smart_books', 'medium'],
        ['bookkeeping software for small business', 'smart_books', 'medium'],
        ['ai accounting software', 'smart_books', 'medium'],
        // Billing / GST invoicing
        ['gst billing software', 'billing', 'high'],
        ['free gst billing software', 'billing', 'high'],
        ['online invoice generator india', 'billing', 'medium'],
        ['gst invoice format', 'billing', 'medium'],
        ['e-invoice software', 'billing', 'medium'],
        ['billing software for retail shop', 'billing', 'medium'],
        ['invoicing software for small business', 'billing', 'medium'],
        ['e-way bill software', 'billing', 'low'],
        // Inventory
        ['inventory management software india', 'inventory', 'high'],
        ['stock management software', 'inventory', 'medium'],
        ['inventory software for small business', 'inventory', 'medium'],
        ['free inventory management software', 'inventory', 'medium'],
        ['warehouse management software india', 'inventory', 'low'],
        // Notes
        ['notes app for business', 'notes', 'low'],
        ['team notes app', 'notes', 'low'],
        // Drive / document storage
        ['document management software india', 'drive', 'medium'],
        ['cloud storage for business documents', 'drive', 'low'],
        ['secure document storage for accountants', 'drive', 'low'],
        ['online file storage for small business', 'drive', 'low'],
        // Brand
        ['aicountly', 'brand', 'high'],
        ['aicountly smart books', 'brand', 'high'],
        ['aicountly billing software', 'brand', 'medium'],
        ['aicountly inventory', 'brand', 'medium'],
    ];

    public function up(): void
    {
        foreach (self::COMPETITORS as [$name, $domain]) {
            $this->db->query(
                "INSERT INTO reach_competitors (tenant_id, name, website_domain, category, monitoring_enabled, monitoring_status, notes)
                 VALUES (1, ?, ?, 'accounting_saas', TRUE, 'active', 'Seeded for the SEO Tracker (launch defaults).')
                 ON CONFLICT (tenant_id, name) DO NOTHING",
                [$name, $domain]
            );
        }

        foreach (self::KEYWORDS as [$keyword, $product, $priority]) {
            $this->db->query(
                'INSERT INTO reach_seo_tracked_keywords (keyword, product, priority, enabled)
                 SELECT ?, ?, ?, TRUE
                 WHERE NOT EXISTS (SELECT 1 FROM reach_seo_tracked_keywords WHERE LOWER(keyword) = LOWER(?))',
                [$keyword, $product, $priority, $keyword]
            );
        }
    }

    public function down(): void
    {
        // Keywords are dropped with their table by the previous migration's
        // down(). Competitors are shared with AI-visibility monitoring and may
        // have gathered aliases/observations since, so they are left alone.
    }
}
