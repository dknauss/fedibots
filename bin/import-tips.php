#!/usr/bin/env php
<?php

/**
 * Parse WordPress Security Benchmark markdown into tips.json.
 *
 * Usage: php bin/import-tips.php /path/to/WordPress-Security-Benchmark.md
 *   Output: content/examples/wp-security/tips.json
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/import-tips.php /path/to/WordPress-Security-Benchmark.md\n");
    exit(1);
}

$inputFile = $argv[1];
if (!file_exists($inputFile)) {
    fwrite(STDERR, "Error: File not found: {$inputFile}\n");
    exit(1);
}

$markdown = file_get_contents($inputFile);
$lines = explode("\n", $markdown);

$tips = [];
$currentSection = '';
$currentControl = null;

foreach ($lines as $line) {
    // Match section headers: ## X.0 Section Name
    if (preg_match('/^## (\d+)\.0 (.+)$/', $line, $m)) {
        $currentSection = trim($m[2]);
        continue;
    }

    // Match control headers: #### X.Y Control Title
    if (preg_match('/^#### (\d+\.\d+) (.+)$/', $line, $m)) {
        // Save previous control if exists
        if ($currentControl !== null) {
            $tips[] = finalizeTip($currentControl);
        }

        $currentControl = [
            'id'          => $m[1],
            'title'       => trim($m[2]),
            'section'     => $currentSection,
            'level'       => null,
            'description' => '',
            'rationale'   => '',
            '_parsing'    => null,
        ];
        continue;
    }

    if ($currentControl === null) {
        continue;
    }

    // Match profile level
    if (preg_match('/^\*\*Profile Applicability:\*\*\s*\*\*Level (\d)\*\*/', $line, $m)) {
        $currentControl['level'] = (int) $m[1];
        continue;
    }

    // Track which field we're parsing
    if (preg_match('/^\*\*Description:\*\*\s*(.*)/', $line, $m)) {
        $currentControl['_parsing'] = 'description';
        $currentControl['description'] = trim($m[1]);
        continue;
    }
    if (preg_match('/^\*\*Rationale:\*\*\s*(.*)/', $line, $m)) {
        $currentControl['_parsing'] = 'rationale';
        $currentControl['rationale'] = trim($m[1]);
        continue;
    }
    if (preg_match('/^\*\*(Impact|Audit|Remediation|Default Value|References):\*\*/', $line)) {
        $currentControl['_parsing'] = null;
        continue;
    }

    // Append to current field
    if ($currentControl['_parsing'] === 'description' && trim($line) !== '') {
        $currentControl['description'] .= ' ' . trim($line);
    }
    if ($currentControl['_parsing'] === 'rationale' && trim($line) !== '') {
        $currentControl['rationale'] .= ' ' . trim($line);
    }
}

// Don't forget the last control
if ($currentControl !== null) {
    $tips[] = finalizeTip($currentControl);
}

// Write output
$outputFile = $rootDir . '/content/examples/wp-security/tips.json';
$json = json_encode($tips, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents($outputFile, $json . "\n");

echo "Extracted " . count($tips) . " tips to {$outputFile}\n";

// Summary by section
$sections = [];
foreach ($tips as $tip) {
    $sections[$tip['section']] = ($sections[$tip['section']] ?? 0) + 1;
}
foreach ($sections as $section => $count) {
    echo "  {$section}: {$count}\n";
}

/**
 * Build the final tip from parsed control data.
 */
function finalizeTip(array $control): array
{
    // Generate hashtags from title keywords
    $hashtags = generateHashtags($control['title'], $control['section']);

    // Build a concise tip text from description + rationale, targeting ≤400 chars
    // (leaving room for title line, level line, and hashtags within 500 char post)
    $tipText = $control['description'];
    if (strlen($tipText) > 280 && !empty($control['rationale'])) {
        // Description is long enough on its own
    } elseif (!empty($control['rationale'])) {
        $tipText .= ' ' . $control['rationale'];
    }

    // Truncate to ~350 chars if needed (leave room for post formatting)
    if (strlen($tipText) > 350) {
        $tipText = substr($tipText, 0, 347) . '...';
    }

    return [
        'id'       => $control['id'],
        'title'    => $control['title'],
        'section'  => $control['section'],
        'level'    => $control['level'] ?? 1,
        'tip'      => trim($tipText),
        'hashtags' => $hashtags,
    ];
}

/**
 * Generate relevant hashtags from the control title and section.
 */
function generateHashtags(string $title, string $section): array
{
    $tags = ['WordPress', 'InfoSec'];

    // Map keywords to hashtags
    $keywordMap = [
        'TLS'            => 'TLS',
        'SSL'            => 'TLS',
        'HTTPS'          => 'HTTPS',
        'HTTP'           => 'WebSecurity',
        'header'         => 'WebSecurity',
        'PHP'            => 'PHP',
        'database'       => 'Database',
        'MySQL'          => 'Database',
        'MariaDB'        => 'Database',
        'password'       => 'Passwords',
        'authentication' => 'Authentication',
        'two-factor'     => '2FA',
        '2FA'            => '2FA',
        'session'        => 'Sessions',
        'firewall'       => 'Firewall',
        'WAF'            => 'WAF',
        'backup'         => 'Backup',
        'plugin'         => 'Plugins',
        'theme'          => 'Themes',
        'update'         => 'Updates',
        'permission'     => 'FilePermissions',
        'logging'        => 'Logging',
        'monitor'        => 'Monitoring',
        'malware'        => 'Malware',
        'API'            => 'API',
        'REST'           => 'RESTAPI',
        'XML-RPC'        => 'XMLRPC',
        'cron'           => 'WPCron',
        'SSH'            => 'SSH',
        'SFTP'           => 'SFTP',
        'FTP'            => 'SFTP',
        'AI'             => 'AISecurity',
        'multisite'      => 'Multisite',
        'SBOM'           => 'SupplyChain',
        'enumeration'    => 'UserEnum',
        'debug'          => 'Debugging',
        'wp-config'      => 'WPConfig',
        'salt'           => 'WPConfig',
    ];

    $titleLower = strtolower($title);
    foreach ($keywordMap as $keyword => $tag) {
        if (stripos($title, $keyword) !== false || stripos($section, $keyword) !== false) {
            if (!in_array($tag, $tags)) {
                $tags[] = $tag;
            }
        }
    }

    // Cap at 5 hashtags to keep posts concise
    return array_slice($tags, 0, 5);
}
