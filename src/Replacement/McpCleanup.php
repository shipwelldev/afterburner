<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

use Shipwell\Afterburner\Publication\Paths;

/**
 * Removes Laravel Boost's MCP server entry from project-local agent configuration.
 *
 * Only an entry named `laravel-boost` that launches `artisan boost:mcp`
 * (directly, through Sail, or through WSL) is removed. Everything else in
 * the file, including other servers, comments, and formatting, is kept. An
 * entry that cannot be recognized or edited safely is reported and left alone.
 */
final class McpCleanup
{
    public const SERVER_KEY = 'laravel-boost';

    /**
     * Project-local MCP files Boost v2.10.4 writes, with their format and server container key.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    public const LOCATIONS = [
        ['.mcp.json', 'json', 'mcpServers'],
        ['.cursor/mcp.json', 'json', 'mcpServers'],
        ['.pi/mcp.json', 'json', 'mcpServers'],
        ['.junie/mcp/mcp.json', 'json', 'mcpServers'],
        ['.kiro/settings/mcp.json', 'json', 'mcpServers'],
        ['.agents/mcp_config.json', 'json', 'mcpServers'],
        ['.vscode/mcp.json', 'json', 'servers'],
        ['.amp/settings.json', 'json', 'amp.mcpServers'],
        ['.zed/settings.json', 'json', 'context_servers'],
        ['opencode.json', 'json', 'mcp'],
        ['opencode.jsonc', 'json', 'mcp'],
        ['.codex/config.toml', 'toml', 'mcp_servers'],
        ['.grok/config.toml', 'toml', 'mcp_servers'],
    ];

    /**
     * Server container keys for agents whose MCP path can be customized in config/boost.php.
     *
     * @var array<string, string>
     */
    public const AGENT_CONTAINERS = [
        'claude_code' => 'mcpServers',
        'cursor' => 'mcpServers',
        'pi' => 'mcpServers',
        'junie' => 'mcpServers',
        'kiro' => 'mcpServers',
        'antigravity' => 'mcpServers',
        'copilot' => 'servers',
        'amp' => 'amp.mcpServers',
        'zed' => 'context_servers',
        'opencode' => 'mcp',
        'codex' => 'mcp_servers',
        'grok_build' => 'mcp_servers',
    ];

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $locations  project-relative path, format, container
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $locations = self::LOCATIONS,
    ) {
        //
    }

    /**
     * Known locations plus any project-local custom MCP paths from Boost's configuration.
     *
     * @param  array<string, mixed>  $agentConfiguration  `config('boost.agents')`
     * @param  array<int, string>  $outside  receives configured paths outside the application
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function locationsFor(string $basePath, array $agentConfiguration, array &$outside = []): array
    {
        $locations = self::LOCATIONS;

        foreach ($agentConfiguration as $agent => $settings) {
            $path = is_array($settings) ? ($settings['mcp_config_path'] ?? null) : null;

            if (! is_string($path) || $path === '' || ! isset(self::AGENT_CONTAINERS[$agent])) {
                continue;
            }

            $relative = ProjectPath::relative($basePath, $path);

            if ($relative === null) {
                $outside[] = $path;

                continue;
            }

            $locations[] = [$relative, str_ends_with($relative, '.toml') ? 'toml' : 'json', self::AGENT_CONTAINERS[$agent]];
        }

        return array_values(array_unique($locations, SORT_REGULAR));
    }

    /**
     * Describe what cleanup would do without changing anything.
     *
     * @return array<int, array{file: string, status: string, detail: string}>
     */
    public function inspect(): array
    {
        return $this->run(false);
    }

    /**
     * Remove recognized entries and report every finding.
     *
     * @return array<int, array{file: string, status: string, detail: string}>
     */
    public function apply(): array
    {
        return $this->run(true);
    }

    /**
     * @return array<int, array{file: string, status: string, detail: string}>
     */
    private function run(bool $write): array
    {
        $findings = [];

        foreach ($this->locations as [$relative, $format, $container]) {
            $path = Paths::join($this->basePath, $relative);

            if (! is_file($path)) {
                continue;
            }

            $contents = @file_get_contents($path);

            if ($contents === false) {
                $findings[] = ['file' => $relative, 'status' => 'unsupported', 'detail' => 'the file is not readable; check it for a laravel-boost server by hand'];

                continue;
            }

            $finding = $format === 'toml'
                ? $this->toml($contents, $container)
                : $this->json($contents, $container, $relative);

            if ($finding === null) {
                continue;
            }

            if ($finding['status'] === 'remove' && $write) {
                $finding = $this->write($path, $relative, $finding);
            }

            $findings[] = ['file' => $relative, 'status' => $finding['status'], 'detail' => $finding['detail']];
        }

        return $findings;
    }

    /**
     * @return array{status: string, detail: string, contents?: string, empty?: bool}|null
     */
    private function json(string $contents, string $container, string $relative): ?array
    {
        $entry = JsonEntryEditor::find($contents, $container, self::SERVER_KEY);

        if ($entry === false) {
            return str_contains($contents, 'boost:mcp')
                ? ['status' => 'unsupported', 'detail' => 'the file uses syntax Afterburner cannot edit safely; remove the laravel-boost server by hand']
                : null;
        }

        if ($entry === null) {
            return str_contains($contents, 'boost:mcp')
                ? ['status' => 'ambiguous', 'detail' => 'a server other than laravel-boost runs boost:mcp; review it by hand']
                : null;
        }

        if (! is_array($entry['value']) || ! self::launchesBoost(self::jsonLaunch($entry['value']))) {
            return ['status' => 'ambiguous', 'detail' => 'the laravel-boost entry does not launch `artisan boost:mcp`, so it was kept; review it by hand'];
        }

        $remaining = JsonEntryEditor::remove($contents, $entry['start'], $entry['end']);

        $decoded = json_decode(JsonEntryEditor::stripComments($remaining), true);
        $onlyBoostDefaults = is_array($decoded) && in_array($decoded, [
            [$container => []],
            ['$schema' => 'https://opencode.ai/config.json', $container => []],
        ], true) && ! JsonEntryEditor::hasComments($remaining);

        return ['status' => 'remove', 'detail' => 'removed the laravel-boost server', 'contents' => $remaining, 'empty' => $onlyBoostDefaults && ! str_starts_with($relative, '.vscode/') && ! str_starts_with($relative, '.zed/') && ! str_starts_with($relative, '.amp/')];
    }

    /**
     * @return array{status: string, detail: string, contents?: string, empty?: bool}|null
     */
    private function toml(string $contents, string $container): ?array
    {
        $entry = TomlSectionEditor::find($contents, $container, self::SERVER_KEY);

        if ($entry === false) {
            return ['status' => 'unsupported', 'detail' => 'the laravel-boost server is not written as a [mcp_servers.laravel-boost] table; remove it by hand'];
        }

        if ($entry === null) {
            return str_contains($contents, 'boost:mcp')
                ? ['status' => 'ambiguous', 'detail' => 'a server other than laravel-boost runs boost:mcp; review it by hand']
                : null;
        }

        $launch = [$entry['command'], ...(is_array($entry['args']) ? $entry['args'] : [])];

        if (! self::launchesBoost($launch)) {
            return ['status' => 'ambiguous', 'detail' => 'the laravel-boost entry does not launch `artisan boost:mcp`, so it was kept; review it by hand'];
        }

        $remaining = TomlSectionEditor::remove($contents, $entry['start'], $entry['end']);

        return ['status' => 'remove', 'detail' => 'removed the laravel-boost server', 'contents' => $remaining, 'empty' => trim($remaining) === ''];
    }

    /**
     * @param  array{status: string, detail: string, contents?: string, empty?: bool}  $finding
     * @return array{status: string, detail: string}
     */
    private function write(string $path, string $relative, array $finding): array
    {
        if ($finding['empty'] ?? false) {
            if (! @unlink($path)) {
                return ['status' => 'failed', 'detail' => "unable to remove {$relative}"];
            }

            $directory = dirname($path);

            if ($directory !== $this->basePath && is_dir($directory) && ! (new \FilesystemIterator($directory))->valid()) {
                @rmdir($directory);
            }

            return ['status' => 'removed', 'detail' => 'removed the laravel-boost server and the file, which contained nothing else'];
        }

        if (@file_put_contents($path, $finding['contents'] ?? '') === false) {
            return ['status' => 'failed', 'detail' => "unable to write {$relative}"];
        }

        return ['status' => 'removed', 'detail' => $finding['detail']];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, mixed>
     */
    private static function jsonLaunch(array $entry): array
    {
        $command = $entry['command'] ?? null;

        if (is_array($command) && array_is_list($command)) {
            return $command;
        }

        if (is_array($command)) {
            return [$command['path'] ?? null, ...(is_array($command['args'] ?? null) ? $command['args'] : [])];
        }

        return [$command, ...(is_array($entry['args'] ?? null) ? $entry['args'] : [])];
    }

    /**
     * Whether a launch command runs Boost's MCP server through PHP, Sail, or WSL.
     *
     * @param  array<int, mixed>  $launch
     */
    public static function launchesBoost(array $launch): bool
    {
        $tokens = array_values(array_filter($launch, 'is_string'));

        if (count($tokens) < 3 || end($tokens) !== 'boost:mcp') {
            return false;
        }

        $artisan = $tokens[count($tokens) - 2];

        if ($artisan !== 'artisan' && ! preg_match('#[/\\\\]artisan$#', $artisan)) {
            return false;
        }

        $executable = strtolower(basename(str_replace('\\', '/', $tokens[0])));

        return match (true) {
            count($tokens) === 3 => preg_match('/^php[\d.]*(\.exe)?$/', $executable) === 1 || $executable === 'sail',
            count($tokens) === 4 => in_array($executable, ['wsl', 'wsl.exe'], true)
                && preg_match('/^php[\d.]*(\.exe)?$/', strtolower(basename(str_replace('\\', '/', $tokens[1])))) === 1,
            default => false,
        };
    }
}
