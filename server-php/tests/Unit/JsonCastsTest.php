<?php

namespace Tests\Unit;

use App\Models\Cast\PgTextArrayCast;
use App\Models\Cast\TolerantJsonCast;
use App\Models\ReachModel;
use App\Services\JsonColumnRepairService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * JSON columns: writers used to json_encode() into fields that already have a JSON cast (stored as a
 * JSON string; reading it threw a TypeError), and non-nullable casts on nullable columns threw on
 * SQL NULL. Every model with a JSON cast now extends ReachModel (TolerantJsonCast), casts are nullable
 * where the column is, writers pass arrays, and `php spark reach:repair-json` repairs old rows.
 *
 * @internal
 */
final class JsonCastsTest extends CIUnitTestCase
{
    /** Writers that legitimately encode: query-builder writes, where no cast applies (file => fields). */
    private const BUILDER_WRITES = [
        'Libraries/Community/OfficialAnswerValidationService.php'          => ['validation_results'], // query builder
        'Libraries/Community/CommunityPublicationReconciliationService.php' => ['details'],            // query builder
        'Libraries/Community/CommunityPublicationVerificationService.php'   => ['details'],            // query builder
        'Libraries/ConsoleAuditClient.php'                                  => ['payload'],            // query builder
        'Libraries/Distribution/AudienceResolverService.php'                => ['snapshot_criteria'],  // query builder
        'Libraries/Distribution/ChannelDispatchService.php'                 => ['channels'],           // query builder
        'Libraries/Distribution/DistributionAuditService.php'               => ['stats'],              // query builder
        'Libraries/AuditLogger.php'                                         => ['metadata'],           // AuditLogModel has no JSON cast
        'Libraries/Community/CommunityOperationalAgentService.php'          => ['metadata'],           // CommunityAgentRunModel has no JSON cast
    ];

    public function testTolerantCast(): void
    {
        $this->assertSame(['a' => 1], TolerantJsonCast::get('{"a":1}', ['array']));
        $this->assertSame(['a' => 1], TolerantJsonCast::get(json_encode('{"a":1}'), ['array']), 'double-encoded reads as the document');
        $this->assertNull(TolerantJsonCast::get(null, ['array']), 'SQL NULL on a non-nullable cast');
        $this->assertNull(TolerantJsonCast::get('null', ['array']), 'JSON null');
        $this->assertSame('confirm', TolerantJsonCast::get('"confirm"', ['array']), 'a JSON string scalar passes through');
        $this->assertSame('{"a":1}', TolerantJsonCast::set(['a' => 1], ['array']));
        $this->assertSame('{"a":1}', TolerantJsonCast::set('{"a":1}', ['array']), 'pre-encoded JSON is not encoded again');
        $this->assertSame('null', TolerantJsonCast::set(null, ['array']), 'null on a non-nullable cast stays JSON null, as CodeIgniter stores it');
    }

    public function testRepairedText(): void
    {
        $this->assertSame('{"a":1}', JsonColumnRepairService::repairedText(json_encode('{"a":1}')));
        $this->assertSame('[1,2]', JsonColumnRepairService::repairedText(json_encode('[1,2]')));
        $this->assertNull(JsonColumnRepairService::repairedText('{"a":1}'), 'a document is left alone');
        $this->assertNull(JsonColumnRepairService::repairedText('"plain text"'), 'a genuine JSON string is left alone');
    }

    /** @return array<string, array<string, string>> class => [field => cast] */
    private static function jsonCasts(): array
    {
        $out = [];
        $dir = APPPATH . 'Models/';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Models\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($dir), -4));
            if (! class_exists($class) || (new \ReflectionClass($class))->isAbstract() || ! (new \ReflectionClass($class))->hasProperty('casts')) {
                continue;
            }
            $casts = (new \ReflectionClass($class))->getDefaultProperties()['casts'] ?? [];
            foreach ((array) $casts as $field => $type) {
                if (preg_match('/\A\??json/', (string) $type) === 1) {
                    $out[$class][$field] = (string) $type;
                }
            }
        }

        return $out;
    }

    public function testEveryModelWithAJsonCastUsesTheTolerantBase(): void
    {
        $casts = self::jsonCasts();
        $this->assertNotSame([], $casts);
        $outside = array_values(array_filter(array_keys($casts), static fn (string $c): bool => ! is_subclass_of($c, ReachModel::class)));
        $this->assertSame([], $outside);
    }

    public function testNoWriterEncodesIntoACastField(): void
    {
        $fields = [];
        foreach (self::jsonCasts() as $map) {
            $fields += array_fill_keys(array_keys($map), true);
        }
        $pattern = '/[\'"](' . implode('|', array_map('preg_quote', array_keys($fields))) . ')[\'"]\s*(?:=>|\]\s*=)\s*[^,;]*json_encode\(/';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen(APPPATH));
            if ($file->getExtension() !== 'php' || str_starts_with($rel, 'Database/') || str_starts_with($rel, 'Views/')) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match_all($pattern, $line, $m) > 0) {
                    foreach ($m[1] as $field) {
                        if (! in_array($field, self::BUILDER_WRITES[$rel] ?? [], true)) {
                            $hits[] = "{$rel}:" . ($n + 1) . " {$field}";
                        }
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'pass the array: the model\'s JSON cast encodes it');
    }

    public function testPgTextArrayCast(): void
    {
        $this->assertSame([], PgTextArrayCast::get('{}'));
        $this->assertSame(['faq', 'how to', 'a"b'], PgTextArrayCast::get('{faq,"how to","a\"b"}'));
        $this->assertSame(['faq'], PgTextArrayCast::get('["faq"]'), 'a value the old json cast wrote');
        $this->assertNull(PgTextArrayCast::get(null));
        $this->assertSame('{"faq","how to","a\"b"}', PgTextArrayCast::set(['faq', 'how to', 'a"b']));
        $this->assertSame('{}', PgTextArrayCast::set([]));
        $this->assertSame('{"faq"}', PgTextArrayCast::set('["faq"]'));
    }
}
