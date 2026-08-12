<?php

declare(strict_types=1);

namespace HaoCode\Services\Api\Capability;

use HaoCode\Services\Settings\ProviderType;
use HaoCode\Services\Settings\ResolvedProviderConfig;

/**
 * Canonical capability matrix for the three supported provider wire formats.
 *
 * @internal
 */
final class ProviderCapabilityRegistry
{
    public const TEXT = 'text';
    public const STREAMING = 'streaming';
    public const TOOLS = 'tools';
    public const MULTI_TURN_TOOLS = 'multi_turn_tools';
    public const STRUCTURED_OUTPUT = 'structured_output';
    public const THINKING = 'thinking';
    public const IMAGES = 'images';
    public const ABORT = 'abort';
    public const OAUTH_BEARER = 'oauth_bearer';
    public const CUSTOM_HEADERS = 'custom_headers';

    /** @var array<string, ProviderCapabilityProfile> */
    private array $profiles = [];

    /** @param list<ProviderCapabilityProfile> $profiles */
    public function __construct(array $profiles)
    {
        foreach ($profiles as $profile) {
            $type = ProviderType::normalizeRequired($profile->providerType);
            if (isset($this->profiles[$type])) {
                throw new \LogicException("Duplicate provider capability profile: {$type}");
            }
            $this->profiles[$type] = $profile;
        }
    }

    public static function defaults(): self
    {
        $common = [
            self::TEXT => CapabilityStatus::Supported,
            self::STREAMING => CapabilityStatus::Supported,
            self::TOOLS => CapabilityStatus::Supported,
            self::MULTI_TURN_TOOLS => CapabilityStatus::Supported,
            self::STRUCTURED_OUTPUT => CapabilityStatus::Supported,
            self::THINKING => CapabilityStatus::Unknown,
            self::IMAGES => CapabilityStatus::Supported,
            self::ABORT => CapabilityStatus::Supported,
            self::CUSTOM_HEADERS => CapabilityStatus::Supported,
        ];

        return new self([
            new ProviderCapabilityProfile(
                providerType: ProviderType::ANTHROPIC,
                providerCapabilities: $common + [
                    self::OAUTH_BEARER => CapabilityStatus::Unknown,
                ],
                modelRules: [[
                    'pattern' => '/^claude-(?:3-7|(?:haiku|sonnet|opus)-4)/i',
                    'label' => 'claude-extended-thinking',
                    'capabilities' => [self::THINKING => CapabilityStatus::Supported],
                ]],
                endpointRules: [[
                    'pattern' => '#^https://api\.anthropic\.com(?:/|$)#i',
                    'label' => 'api.anthropic.com',
                    'capabilities' => [self::OAUTH_BEARER => CapabilityStatus::Supported],
                ]],
            ),
            new ProviderCapabilityProfile(
                providerType: ProviderType::OPENAI,
                providerCapabilities: $common + [
                    self::OAUTH_BEARER => [
                        'status' => CapabilityStatus::Unsupported,
                        'reason' => 'OAuth bearer mode is implemented only by the Anthropic wire adapter.',
                    ],
                ],
                modelRules: [[
                    'pattern' => '/^(?:o[134](?:-|$)|gpt-5(?:[.\-]|$))/i',
                    'label' => 'openai-reasoning-model',
                    'capabilities' => [self::THINKING => CapabilityStatus::Supported],
                ]],
                endpointRules: [[
                    'pattern' => '#^https://api\.openai\.com(?:/|$)#i',
                    'label' => 'api.openai.com',
                    'capabilities' => [self::STREAMING => CapabilityStatus::Supported],
                ]],
            ),
            new ProviderCapabilityProfile(
                providerType: ProviderType::OPENAI_CHAT,
                providerCapabilities: $common + [
                    self::OAUTH_BEARER => [
                        'status' => CapabilityStatus::Unsupported,
                        'reason' => 'OAuth bearer mode is implemented only by the Anthropic wire adapter.',
                    ],
                ],
                modelRules: [[
                    'pattern' => '/^(?:deepseek-(?:r1|reasoner|v4-flash)|o[134](?:-|$)|gpt-5(?:[.\-]|$)|.*reasoning.*)/i',
                    'label' => 'chat-reasoning-model',
                    'capabilities' => [self::THINKING => CapabilityStatus::Supported],
                ]],
                endpointRules: [[
                    'pattern' => '#^https://api\.openai\.com(?:/|$)#i',
                    'label' => 'api.openai.com',
                    'capabilities' => [self::STREAMING => CapabilityStatus::Supported],
                ]],
            ),
        ]);
    }

    public function resolve(ResolvedProviderConfig $config): ResolvedProviderCapabilities
    {
        $type = ProviderType::normalizeRequired($config->providerType);
        $profile = $this->profiles[$type] ?? null;
        if ($profile === null) {
            throw new \LogicException("Provider capability profile is not registered: {$type}");
        }

        return $profile->resolve($config->model, $config->baseUrl);
    }

    /** @return list<string> */
    public function types(): array
    {
        $types = array_keys($this->profiles);
        sort($types);

        return $types;
    }
}
