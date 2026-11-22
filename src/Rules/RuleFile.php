<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rules;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A rule document and the scope declared by its `paths` frontmatter.
 */
final readonly class RuleFile
{
    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(
        public string $path,
        public string $relative,
        public array $paths,
        public ?string $problem,
        public bool $conflicted,
    ) {
        //
    }

    public static function read(string $path, string $relative): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return new self($path, $relative, [], 'the file is not readable', false);
        }

        return self::fromContents($contents, $path, $relative);
    }

    public static function fromContents(string $contents, string $path, string $relative): self
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $conflicted = preg_match('/^(<{7}|>{7})(\s|$)/m', $contents) === 1;

        if (preg_match('/^\s*---\s*\n(.*?)\n---\s*(\n|$)/s', $contents, $matches) !== 1) {
            return new self($path, $relative, [], 'no valid `paths` frontmatter', $conflicted);
        }

        try {
            $metadata = Yaml::parse($matches[1]);
        } catch (ParseException $exception) {
            return new self($path, $relative, [], 'the frontmatter could not be parsed ('.$exception->getMessage().')', $conflicted);
        }

        $declared = is_array($metadata) ? ($metadata['paths'] ?? []) : [];
        $paths = array_values(array_filter(
            array_map(fn (mixed $glob): mixed => is_string($glob) ? trim($glob) : null, (array) $declared),
            fn (mixed $glob): bool => is_string($glob) && $glob !== '',
        ));

        return new self($path, $relative, $paths, $paths === [] ? 'no valid `paths` frontmatter' : null, $conflicted);
    }

    public function indexable(): bool
    {
        return $this->problem === null;
    }
}
