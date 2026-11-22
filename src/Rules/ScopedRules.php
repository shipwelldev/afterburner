<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rules;

use Illuminate\Support\Str;
use Shipwell\Afterburner\Publication\Markdown;
use Symfony\Component\Yaml\Yaml;

/**
 * Groups extracted `@scoped` guideline bodies into managed rule files.
 *
 * Blocks with the same set of globs share one file, named and titled as
 * Laravel Boost's RuleComposer does (v2.10.4, MIT).
 */
final class ScopedRules
{
    /**
     * @param  array<int, array{paths: array<int, string>, body: string}>  $blocks  in guideline composition order
     * @return array<string, string> project-relative rule file => contents
     */
    public static function compose(array $blocks): array
    {
        $groups = [];

        foreach ($blocks as $block) {
            $paths = self::normalize($block['paths']);

            if ($paths === [] || trim($block['body']) === '') {
                continue;
            }

            $groups[implode('|', $paths)] ??= ['paths' => $paths, 'bodies' => []];
            $groups[implode('|', $paths)]['bodies'][] = trim($block['body']);
        }

        $files = [];
        $taken = [];

        foreach ($groups as $group) {
            $content = Markdown::format(implode("\n\n", $group['bodies']));
            $slug = self::uniqueSlug($group['paths'], $taken);
            $taken[] = $slug;

            $files[RuleIndex::MANAGED_DIRECTORY.'/'.$slug.'.md'] = self::render($group['paths'], self::title($content, $slug), $content);
        }

        return $files;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private static function normalize(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(array_map('trim', $paths), fn (string $path): bool => $path !== '')));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private static function render(array $paths, string $title, string $content): string
    {
        $content = trim($content);
        $heading = preg_match('/^#+\s/', $content) === 1 ? '' : '# '.$title."\n\n";

        return "---\n".Yaml::dump(['paths' => $paths], 2, 2)."---\n\n".$heading.$content."\n";
    }

    private static function title(string $content, string $slug): string
    {
        $firstLine = trim(Str::before(trim($content), "\n"));

        if (preg_match('/^#+\s+(?<heading>.+)$/', $firstLine, $matches) === 1) {
            return trim($matches['heading']);
        }

        return Str::headline($slug);
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $taken
     */
    private static function uniqueSlug(array $paths, array $taken): string
    {
        $segments = [];

        foreach ($paths as $glob) {
            $meaningful = array_filter(
                explode('/', $glob),
                fn (string $segment): bool => $segment !== '' && ! Str::contains($segment, ['*', '.']),
            );

            if ($meaningful !== []) {
                $segments[] = end($meaningful);
            }
        }

        $segments = array_values(array_unique($segments));
        sort($segments, SORT_STRING);

        $base = $segments === [] ? 'rules' : Str::slug(Str::snake(implode(' ', $segments)));
        $base = $base === '' ? 'rules' : $base;

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $suffix = 2;

        while (in_array($base.'-'.$suffix, $taken, true)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }
}
