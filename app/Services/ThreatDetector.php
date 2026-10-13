<?php

namespace App\Services;

/**
 * WAF-style analysis of one request: SQL injection, XSS, path traversal, file
 * inclusion, command injection, scanner probes, and known attack tools.
 * Nothing is blocked here; flagged requests show on the Monitoring tab and can
 * be blocked by IP from there.
 */
class ThreatDetector
{
    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'sql_injection' => 'SQL injection',
        'xss' => 'XSS',
        'path_traversal' => 'Path traversal',
        'file_inclusion' => 'File inclusion',
        'command_injection' => 'Command injection',
        'attack_tool' => 'Attack tool',
        'scanner' => 'Scanner probe',
    ];

    /**
     * Category => [risk 0-100, pattern, what it is matched against].
     *
     * @var array<string, array{int, string, string}>
     */
    private const RULES = [
        'sql_injection' => [90, '/(\bunion\b[\s\S]{0,40}\bselect\b|\bselect\b[\s\S]{1,80}\bfrom\b[\s\S]{0,80}(\bwhere\b|--|#|information_schema)|information_schema|\bsleep\s*\(\s*\d|\bbenchmark\s*\(|\bwaitfor\s+delay\b|[\'"]\s*\bor\b\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+|\bor\b\s+1\s*=\s*1\b|;\s*drop\s+table\b|\bextractvalue\s*\(|\bupdatexml\s*\()/i', 'uri'],
        'xss' => [80, '/(<\s*\/?\s*script\b|javascript\s*:|<[^>]*\bon(error|load|mouseover|focus|toggle|animationstart)\s*=|document\.cookie|\balert\s*\(|<\s*(iframe|object|embed)\b)/i', 'uri'],
        'path_traversal' => [80, '#(\.\./|\.\.\\\\)#', 'uri'],
        'file_inclusion' => [85, '#(/etc/(passwd|shadow|hosts)\b|\bphp://(input|filter)|\b(expect|zip|phar|file)://|/proc/self/environ|[?&][^=&]*=(https?|ftp)://[^&]*\.(txt|php|sh)(\?|&|$))#i', 'uri'],
        'command_injection' => [85, '/([;|`]\s*(cat|ls|id|whoami|uname|wget|curl|bash|sh|nc|ncat|python3?|perl|rm|chmod)\b|\$\(\s*(cat|id|whoami|curl|wget|uname)\b|\$\{jndi:)/i', 'uri'],
        'attack_tool' => [70, '/(sqlmap|nikto|nmap|masscan|zgrab|nuclei|wpscan|acunetix|dirbuster|gobuster|ffuf|feroxbuster|havij|netsparker|jorgee|openvas|w3af|hydra|fimap|commix)/i', 'agent'],
        'scanner' => [40, '#(^|/)(\.env(\.[\w-]+)?$|\.git/|\.svn/|\.aws/|\.ds_store$|wp-login\.php|wp-admin/|wp-content/|wp-includes/|xmlrpc\.php|phpmyadmin|adminer\.php|vendor/phpunit/|eval-stdin\.php|cgi-bin/|server-status$|actuator/|boaform/|\.sql$|backup\.(zip|tar|gz|sql)$|shell\.php$|config\.php\.bak$)#i', 'path'],
    ];

    /**
     * @return array{threat: ?string, risk: int}
     */
    public function inspect(string $uri, ?string $userAgent = null): array
    {
        $decoded = $this->decode($uri);
        $subjects = [
            'uri' => $decoded,
            'path' => explode('?', $decoded, 2)[0],
            'agent' => (string) $userAgent,
        ];

        $found = [];

        foreach (self::RULES as $category => [$risk, $pattern, $subject]) {
            if ($subjects[$subject] !== '' && preg_match($pattern, $subjects[$subject]) === 1) {
                $found[$category] = $risk;
            }
        }

        if ($found === []) {
            return ['threat' => null, 'risk' => 0];
        }

        arsort($found);

        return [
            'threat' => implode(',', array_keys($found)),
            'risk' => min(100, max($found) + 5 * (count($found) - 1)),
        ];
    }

    /**
     * critical, high, medium, or low.
     */
    public static function level(int $risk): string
    {
        return match (true) {
            $risk >= 85 => 'critical',
            $risk >= 65 => 'high',
            $risk >= 40 => 'medium',
            default => 'low',
        };
    }

    /**
     * "sql_injection,scanner" => "SQL injection, Scanner probe".
     */
    public static function label(?string $threat): string
    {
        return collect(explode(',', (string) $threat))
            ->filter()
            ->map(fn (string $category): string => self::CATEGORIES[$category] ?? $category)
            ->implode(', ');
    }

    /**
     * Undo URL encoding twice, which is how most payloads try to slip past filters.
     */
    private function decode(string $uri): string
    {
        $decoded = rawurldecode(str_replace('+', ' ', $uri));

        return rawurldecode($decoded);
    }
}
