<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Seeds an application the way Laravel Boost v2.10.4 leaves it, for replacement tests.
 */
final class BoostApplication
{
    public const MANUAL_CLAUDE = "# Team notes\n\nAlways run the linter.\n";

    public const MANUAL_AFTER = "\n## Footer\n\nKept below the block.\n";

    public const USER_RULE = "---\npaths:\n  - 'app/**'\n---\n\n# Team\n\n## Naming\nUse descriptive names.\n";

    public const MCP_JSON = <<<'JSON'
{
    "mcpServers": {
        "laravel-boost": {
            "command": "php",
            "args": [
                "artisan",
                "boost:mcp"
            ]
        },
        "github": {
            "command": "npx",
            "args": ["-y", "@modelcontextprotocol/server-github"]
        }
    }
}

JSON;

    public const OPENCODE_JSONC = <<<'JSONC'
{
  // Team OpenCode settings
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    /* our docs server */
    "docs": {
      "type": "local",
      "command": ["node", "docs-server.js"],
    },
    "laravel-boost": {
      "type": "local",
      "enabled": true,
      "command": ["php", "artisan", "boost:mcp"],
      "environment": {}
    }
  },
  "theme": "dark", // keep this
}

JSONC;

    public const CODEX_TOML = <<<'TOML'
# Codex project settings
model = "gpt-5"

[mcp_servers.laravel-boost]
command = "vendor/bin/sail"
args = ["artisan", "boost:mcp"]

[mcp_servers.laravel-boost.env]
APP_ENV = "local"

# Our own server
[mcp_servers.docs]
command = "node"
args = ["docs-server.js"]

TOML;

    public const GLOBAL_CURSOR = "{\n  \"mcpServers\": {\n    \"laravel-boost\": {\"command\": \"php\", \"args\": [\"artisan\", \"boost:mcp\"]}\n  }\n}\n";

    public static function seed(FixtureApplication $fixture, string $home, array $boost = []): void
    {
        $boost = array_replace([
            'agents' => ['claude_code', 'cursor', 'copilot'],
            'guidelines' => true,
            'mcp' => true,
            'skills' => ['laravel-best-practices', 'infer-conventions', 'team-custom'],
            'packages' => [],
            'sail' => false,
        ], $boost);

        $fixture->writeJson('boost.json', $boost);
        $fixture->composerPackage('laravel/framework', '12.3.0');

        $block = "<laravel-boost-guidelines>\n=== foundation rules ===\n\n# Laravel Boost Guidelines\n\nOld Boost guidance.\n\n</laravel-boost-guidelines>\n";

        $fixture
            ->write('CLAUDE.md', self::MANUAL_CLAUDE."\n".$block.self::MANUAL_AFTER)
            ->write('AGENTS.md', $block)
            ->write('.github/copilot-instructions.md', "Copilot notes.\n\n".$block)
            ->skillAt('.claude/skills/laravel-best-practices', 'laravel-best-practices', 'Old Boost copy.', '', ['rules/old-only.md' => "Removed upstream.\n"])
            ->skillAt('.claude/skills/infer-conventions', 'infer-conventions', 'Old Boost copy using record-rule.')
            ->skillAt('.claude/skills/hand-made', 'hand-made', 'Untracked team skill.')
            ->skillAt('.cursor/skills/laravel-best-practices', 'laravel-best-practices', 'Old Boost copy for Cursor.')
            ->skillAt('.cursor/skills/cursor-only', 'cursor-only', 'Untracked Cursor skill.')
            ->skill('team-custom', 'The team\'s custom skill.')
            ->write('.ai/rules/team.md', self::USER_RULE)
            ->write('.ai/rules/boost/models.md', "---\npaths:\n  - 'app/Models/**'\n---\n\n# Models\n\nOld scoped rule.\n")
            ->write('.ai/rules/index.md', "# Project Rules Index\n\nOld Boost index.\n")
            ->write('.mcp.json', self::MCP_JSON)
            ->write('opencode.jsonc', self::OPENCODE_JSONC)
            ->write('.codex/config.toml', self::CODEX_TOML)
            ->write('.cursor/mcp.json', "{\n    \"mcpServers\": {\n        \"laravel-boost\": {\n            \"command\": \"wsl.exe\",\n            \"args\": [\"/usr/bin/php8.4\", \"/home/me/app/artisan\", \"boost:mcp\"]\n        }\n    }\n}\n");

        symlink('../../.ai/skills/team-custom', $fixture->path('.claude/skills/team-custom'));

        @mkdir($home.'/.cursor', 0755, true);
        file_put_contents($home.'/.cursor/mcp.json', self::GLOBAL_CURSOR);
    }
}
