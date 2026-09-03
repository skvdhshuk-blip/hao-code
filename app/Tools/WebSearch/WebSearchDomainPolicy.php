<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

/** @internal */
final class WebSearchDomainPolicy
{
    /**
     * @param list<string> $allowedDomains
     * @param list<string> $blockedDomains
     */
    public function __construct(
        private readonly array $allowedDomains,
        private readonly array $blockedDomains,
    ) {}

    public static function fromInput(mixed $allowedDomains, mixed $blockedDomains): self
    {
        return new self(self::normalize($allowedDomains), self::normalize($blockedDomains));
    }

    public function allows(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower(rtrim($host, '.'));

        if ($this->allowedDomains !== []) {
            $allowed = false;
            foreach ($this->allowedDomains as $domain) {
                if (self::matches($host, $domain)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                return false;
            }
        }

        foreach ($this->blockedDomains as $domain) {
            if (self::matches($host, $domain)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function normalize(mixed $domains): array
    {
        if (! is_array($domains)) {
            return [];
        }

        $normalized = [];
        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $domain = strtolower(trim($domain));
            if (str_starts_with($domain, '*.')) {
                $domain = substr($domain, 2);
            }
            if ($domain === '') {
                continue;
            }

            $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST);
            if (is_string($host) && ($host = trim(strtolower($host), '.')) !== '') {
                $normalized[] = $host;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function matches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
