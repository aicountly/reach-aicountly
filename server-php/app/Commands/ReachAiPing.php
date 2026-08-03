<?php

namespace App\Commands;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderRegistry;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * `php spark reach:ai-ping` — smoke-test configured AI providers from CLI.
 */
class ReachAiPing extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:ai-ping';
    protected $description = 'Smoke-test OpenAI/Gemini connectivity and a tiny structured generation call.';
    protected $usage       = 'reach:ai-ping [--provider=openai] [--generate]';

    public function run(array $params): int
    {
        $providerKey = (string) (CLI::getOption('provider') ?? ($params['provider'] ?? 'openai'));
        $doGenerate  = CLI::getOption('generate') !== null || array_key_exists('generate', $params);

        $registry = new AiProviderRegistry();
        $report   = [
            'event'    => 'ai_ping',
            'ts'       => gmdate('c'),
            'provider' => $providerKey,
            'env'      => [
                'openai_key_set' => $this->envSet('AI_OPENAI_API_KEY'),
                'gemini_key_set' => $this->envSet('AI_GEMINI_API_KEY'),
                'mock'           => (($_ENV['REACH_AI_MOCK'] ?? getenv('REACH_AI_MOCK') ?: 'false') === 'true'),
            ],
        ];

        try {
            $provider = $registry->get($providerKey);
            $report['configured'] = $provider->isConfigured();
            $health = $provider->healthCheck();
            $report['health'] = [
                'ok'           => $health->healthy,
                'latency_ms'   => $health->responseTimeMs,
                'message'      => $health->errorMessage,
            ];

            if ($doGenerate && $health->healthy) {
                $result = $provider->generate(new AiGenerationInput(
                    systemPrompt: 'Reply with compact JSON only.',
                    userPrompt: 'Return {"ok":true,"ping":"pong"}',
                    outputSchema: [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['ok', 'ping'],
                        'properties'           => [
                            'ok'   => ['type' => 'boolean'],
                            'ping' => ['type' => 'string'],
                        ],
                    ],
                    modelKey: $providerKey === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4o-mini',
                    maxOutputTokens: 64,
                    timeoutSeconds: 45,
                ));
                $report['generate'] = [
                    'ok'            => true,
                    'duration_ms'   => $result->durationMs,
                    'total_tokens'  => $result->totalTokens,
                    'parsed_json'   => $result->parsedJson,
                    'raw_preview'   => mb_substr($result->rawContent, 0, 200),
                ];
            }
        } catch (Throwable $e) {
            $report['ok'] = false;
            $report['error'] = [
                'class'   => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 500),
            ];
            CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 1;
        }

        $report['ok'] = (bool) ($report['health']['ok'] ?? false);
        CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $report['ok'] ? 0 : 2;
    }

    private function envSet(string $key): bool
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (($v === false || $v === null || $v === '') && function_exists('env')) {
            $v = env($key);
        }

        return is_string($v) && trim($v) !== '';
    }
}
