<?php

declare(strict_types=1);

namespace App\Libraries\Publishing;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Resolves / seeds the default public-site blog publication connection.
 *
 * PUBLISH_BLOG hard-coded `aicountly_com`; production often has the env
 * credentials but an empty reach_publication_connections table.
 */
class PublicationConnectionService
{
    public const DEFAULT_BLOG_CONNECTION_KEY = 'aicountly_com';

    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Return an enabled blog connection key, creating the default row if needed.
     */
    public function resolveBlogConnectionKey(?string $preferredKey = null): string
    {
        $preferred = trim((string) ($preferredKey
            ?: ($_ENV['AICOUNTLY_PUBLICATION_CONNECTION_KEY'] ?? self::DEFAULT_BLOG_CONNECTION_KEY)));
        if ($preferred === '') {
            $preferred = self::DEFAULT_BLOG_CONNECTION_KEY;
        }

        $row = $this->db->table('reach_publication_connections')
            ->where('connection_key', $preferred)
            ->get()
            ->getRowArray();

        if ($row) {
            if (empty($row['enabled'])) {
                $this->db->table('reach_publication_connections')
                    ->where('id', (int) $row['id'])
                    ->update([
                        'enabled'    => true,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }

            return $preferred;
        }

        // Prefer any already-enabled blog-capable connection.
        $existing = $this->db->table('reach_publication_connections')
            ->where('enabled', true)
            ->orderBy('id', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();
        if ($existing && ! empty($existing['connection_key'])) {
            return (string) $existing['connection_key'];
        }

        $this->ensureDefaultBlogConnection($preferred);

        return $preferred;
    }

    /**
     * Idempotently insert/enable the default aicountly.com connection from env.
     *
     * @return array<string,mixed>
     */
    public function ensureDefaultBlogConnection(?string $connectionKey = null): array
    {
        $key = trim((string) ($connectionKey ?: self::DEFAULT_BLOG_CONNECTION_KEY))
            ?: self::DEFAULT_BLOG_CONNECTION_KEY;

        $baseUrl = rtrim((string) (
            $_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL']
            ?? $_SERVER['AICOUNTLY_PUBLIC_SITE_BASE_URL']
            ?? getenv('AICOUNTLY_PUBLIC_SITE_BASE_URL')
            ?: 'https://aicountly.com'
        ), '/');

        $now = date('Y-m-d H:i:s');
        $row = $this->db->table('reach_publication_connections')
            ->where('connection_key', $key)
            ->get()
            ->getRowArray();

        $payload = [
            'display_name'              => 'AICOUNTLY.com public site',
            'base_url'                  => $baseUrl,
            'api_version'               => (int) ($_ENV['AICOUNTLY_PUBLIC_SITE_API_VERSION'] ?? 1),
            'authentication_type'       => 'hmac_bearer',
            'secret_env_reference'      => 'AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN',
            'signing_key_env_reference' => 'AICOUNTLY_PUBLIC_SITE_SIGNING_KEY',
            'key_id_env_reference'      => 'AICOUNTLY_PUBLIC_SITE_KEY_ID',
            'timeout_seconds'           => (int) ($_ENV['AICOUNTLY_PUBLIC_SITE_TIMEOUT'] ?? 15),
            'enabled'                   => true,
            'health_status'             => 'unknown',
            'updated_at'                => $now,
        ];

        if ($row) {
            $this->db->table('reach_publication_connections')
                ->where('id', (int) $row['id'])
                ->update($payload);

            return $this->db->table('reach_publication_connections')
                ->where('id', (int) $row['id'])
                ->get()
                ->getRowArray() ?: array_merge($row, $payload);
        }

        $this->db->table('reach_publication_connections')->insert(array_merge($payload, [
            'connection_key' => $key,
            'created_at'     => $now,
        ]));

        $id = (int) $this->db->insertID();

        return $this->db->table('reach_publication_connections')
            ->where('id', $id)
            ->get()
            ->getRowArray() ?: ['connection_key' => $key, 'id' => $id];
    }
}
