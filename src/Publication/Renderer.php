<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * Turns guideline and skill source files into agent-readable text.
 */
class Renderer
{
    public function __construct(protected readonly string $basePath)
    {
        //
    }

    /**
     * Make the selected skills (name => description) available to templates.
     *
     * @param  array<string, string>  $skills
     */
    public function useSkills(array $skills): void
    {
        //
    }

    /**
     * @throws PublicationFailed
     */
    public function render(string $path): Rendered
    {
        $source = Paths::relative($this->basePath, $path);

        if (! is_file($path) || ! is_readable($path)) {
            throw PublicationFailed::source($source, 'the file is not readable');
        }

        if (str_ends_with($path, '.blade.php')) {
            throw PublicationFailed::source($source, 'Blade templates are not supported in this development build of Afterburner');
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw PublicationFailed::source($source, 'the file is not readable');
        }

        return new Rendered($contents);
    }
}
