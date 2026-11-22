<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses YAML frontmatter at the start of a Markdown document.
 */
final class Frontmatter
{
    /**
     * Read a skill entry point's required `name` and `description`.
     *
     * @return array{name: string, description: string}
     *
     * @throws PublicationFailed
     */
    public static function skill(string $content, string $source): array
    {
        $metadata = self::parse($content, $source);

        if ($metadata === null) {
            throw PublicationFailed::source($source, 'the skill has no YAML frontmatter; add `name` and `description`');
        }

        foreach (['name', 'description'] as $field) {
            if (! isset($metadata[$field]) || ! is_string($metadata[$field]) || trim($metadata[$field]) === '') {
                throw PublicationFailed::source($source, "the skill frontmatter must define a nonempty `{$field}`");
            }
        }

        $name = trim($metadata['name']);

        if (! Paths::isValidSkillName($name)) {
            throw PublicationFailed::source($source, "the skill name \"{$name}\" cannot be used as a directory name");
        }

        return ['name' => $name, 'description' => trim($metadata['description'])];
    }

    /**
     * @return array<string, mixed>|null null when the document has no frontmatter
     *
     * @throws PublicationFailed
     */
    public static function parse(string $content, string $source): ?array
    {
        $content = (string) preg_replace('/^(\s*<!--.*?-->\s*)+/s', '', $content);

        if (preg_match('/^\s*---[^\S\r\n]*\R(.*?)\R---[^\S\r\n]*(?:\R|$)/s', $content, $matches) === 1) {
            try {
                $metadata = Yaml::parse($matches[1]);
            } catch (ParseException $exception) {
                throw PublicationFailed::source($source, 'the frontmatter is not valid YAML ('.$exception->getMessage().')');
            }

            if ($metadata === null) {
                return [];
            }

            if (! is_array($metadata) || array_is_list($metadata)) {
                throw PublicationFailed::source($source, 'the frontmatter must be a YAML mapping');
            }

            return $metadata;
        }

        if (preg_match('/^\s*---[^\S\r\n]*(?:\R|$)/', $content) === 1) {
            throw PublicationFailed::source($source, 'the frontmatter has no closing `---` delimiter');
        }

        return null;
    }
}
