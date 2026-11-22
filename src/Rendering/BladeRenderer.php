<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rendering;

use Illuminate\Support\Facades\Blade;
use Shipwell\Afterburner\Compatibility\BoostAuthoringAliases;
use Shipwell\Afterburner\Publication\Markdown;
use Shipwell\Afterburner\Publication\Paths;
use Shipwell\Afterburner\Publication\PublicationFailed;
use Shipwell\Afterburner\Publication\Rendered;
use Shipwell\Afterburner\Publication\Renderer;
use Throwable;

/**
 * Renders `.blade.php` guidelines and skills with Laravel Boost's authoring conventions.
 *
 * The pre-processing (code-example masking, Blade escapes, `@boostsnippet`,
 * and `@scoped` markers) follows Laravel Boost v2.10.4 (MIT). Unlike Boost,
 * a template that fails to render fails publication instead of being skipped.
 * Plain Markdown is returned literally and is never evaluated.
 */
class BladeRenderer extends Renderer
{
    /**
     * Tokens masked before Blade runs so code examples are not compiled.
     */
    private const PLACEHOLDERS = [
        '`' => '___SINGLE_BACKTICK___',
        '<?php' => '___OPEN_PHP_TAG___',
        '@volt' => '___VOLT_DIRECTIVE___',
        '@endvolt' => '___ENDVOLT_DIRECTIVE___',
        '@can' => '___CAN_DIRECTIVE___',
        '@include' => '___INCLUDE_DIRECTIVE___',
        '@props' => '___PROPS_DIRECTIVE___',
        '</x-' => '___BLADE_COMPONENT_CLOSE___',
        '<x-' => '___BLADE_COMPONENT_OPEN___',
    ];

    /** @var array<string, string> */
    private array $snippets = [];

    public function __construct(string $basePath, private readonly GuidelineAssist $assist)
    {
        parent::__construct($basePath);
    }

    public function assist(): GuidelineAssist
    {
        return $this->assist;
    }

    public function useSkills(array $skills): void
    {
        $this->assist->skills = collect($skills);
    }

    /**
     * @throws PublicationFailed
     */
    public function render(string $path): Rendered
    {
        if (! str_ends_with($path, '.blade.php')) {
            return parent::render($path);
        }

        $source = Paths::relative($this->basePath, $path);
        $contents = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw PublicationFailed::source($source, 'the file is not readable');
        }

        $this->snippets = [];
        BoostAuthoringAliases::register();

        $prepared = $this->markScopedBlocks($this->processSnippets($contents, $source));
        $rendered = str_replace(array_keys($this->snippets), array_values($this->snippets), $this->renderBlade($prepared, $source));

        return $this->extractScopedBlocks($rendered);
    }

    /**
     * @throws PublicationFailed
     */
    private function renderBlade(string $content, string $source): string
    {
        [$content, $escapes] = $this->extractBladeEscapes($content);

        // Hide literal ampersands in fenced code so only Blade's own escaping is decoded afterwards.
        $content = preg_replace_callback(
            '/(?<fence>`{3,}|~{3,}).*?\k<fence>/s',
            fn (array $matches): string => str_replace('&', '___AMPERSAND___', $matches[0]),
            $content,
        ) ?? $content;

        $content = str_replace(array_keys(self::PLACEHOLDERS), array_values(self::PLACEHOLDERS), $content);

        try {
            $rendered = Blade::render($content, ['assist' => $this->assist], deleteCachedView: true);
        } catch (Throwable $exception) {
            throw PublicationFailed::source($source, 'the template failed to render: '.self::describe($exception));
        }

        $rendered = html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5);
        $rendered = str_replace('___AMPERSAND___', '&', $rendered);
        $rendered = str_replace(array_keys($escapes), array_values($escapes), $rendered);

        return str_replace(array_values(self::PLACEHOLDERS), array_keys(self::PLACEHOLDERS), $rendered);
    }

    private static function describe(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // Blade wraps template errors in a view exception that names a temporary file; keep the cause.
        $message = (string) preg_replace('/\s*\(View: [^)]*\)$/', '', $message);

        return $message === '' ? $exception::class : $message;
    }

    /**
     * Replace Blade escapes (`@{{`, `@{!!`, `@@`) with placeholders restored after rendering.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractBladeEscapes(string $content): array
    {
        $escapes = [];
        $skipVerbatim = '@verbatim.*?@endverbatim(*SKIP)(*FAIL)|';
        // Stop at a blank line or fence so an escape in prose cannot swallow a later code block.
        $bounded = '(?:(?!\n[ \t]*\n|\n[ \t]*`{3,}|\n[ \t]*~{3,}).)*?';

        $patterns = [
            '/'.$skipVerbatim.'(?<!@)@\{!!'.$bounded.'!!\}/s',
            '/'.$skipVerbatim.'(?<!@)@\{\{'.$bounded.'\}\}/s',
            '/'.$skipVerbatim.'(?<!@)@(?:\{!!|\{\{)/s',
            '/'.$skipVerbatim.'(?<![\w@])@@(?=\w)/s',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace_callback($pattern, function (array $matches) use (&$escapes): string {
                $placeholder = '___BLADE_ESCAPE_'.count($escapes).'___';
                $escapes[$placeholder] = substr($matches[0], 1);

                return $placeholder;
            }, $content) ?? $content;
        }

        return [$content, $escapes];
    }

    /**
     * Turn `@boostsnippet('name', 'lang') … @endboostsnippet` into labelled fenced code.
     *
     * @throws PublicationFailed
     */
    private function processSnippets(string $content, string $source): string
    {
        return (string) preg_replace_callback(
            '/(?<!@)@boostsnippet\(\s*(?P<nameQuote>[\'"])(?P<name>[^\1]*?)\1(?:\s*,\s*(?P<langQuote>[\'"])(?P<lang>[^\3]*?)\3)?\s*\)(?P<content>.*?)@endboostsnippet/s',
            function (array $matches) use ($source): string {
                $language = empty($matches['lang']) ? 'html' : $matches['lang'];
                $body = $this->renderSnippetAssistExpressions(trim($matches['content']), $source);

                $placeholder = '___BOOST_SNIPPET_'.count($this->snippets).'___';
                $this->snippets[$placeholder] = '<!-- '.$matches['name'].' -->'."\n".'```'.$language."\n".$body."\n".'```'."\n\n";

                return $placeholder;
            },
            $content,
        );
    }

    /**
     * Render only `{{ $assist->… }}` expressions inside a snippet; other braces are example code.
     *
     * @throws PublicationFailed
     */
    private function renderSnippetAssistExpressions(string $content, string $source): string
    {
        return (string) preg_replace_callback('/(?<!@)\{\{.*?\}\}/s', function (array $matches) use ($source): string {
            if (! str_contains($matches[0], '$assist->')) {
                return $matches[0];
            }

            return $this->renderBlade($matches[0], $source);
        }, $content);
    }

    /**
     * Replace `@scoped([...])` / `@endscoped` outside fenced code with sentinels that survive rendering.
     */
    private function markScopedBlocks(string $content): string
    {
        return Markdown::outsideFences($content, function (string $markdown): string {
            $marked = preg_replace_callback(
                '/(?<!@)@scoped\(\s*(?P<paths>\[(?:[\s,]|\'[^\']*\'|"[^"]*")*\])\s*\)/s',
                fn (array $matches): string => '___SCOPED_START_'.base64_encode((string) json_encode($this->parseScopedPaths($matches['paths']))).'___',
                $markdown,
            ) ?? $markdown;

            return preg_replace('/(?<!@)@endscoped/', '___SCOPED_END___', $marked) ?? $marked;
        });
    }

    /**
     * Keep scoped bodies inline and record each top-level block's globs and body.
     *
     * Nested or path-less blocks stay inline without being recorded, so their
     * text never disappears.
     */
    private function extractScopedBlocks(string $content): Rendered
    {
        $matched = preg_match_all(
            '/___SCOPED_START_(?P<paths>[A-Za-z0-9+\/=]*)___|___SCOPED_END___/',
            $content,
            $tokens,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        if ($matched === false || $matched === 0) {
            return new Rendered($this->stripScopedSentinels($content));
        }

        $blocks = [];
        $depth = 0;
        $bodyStart = 0;
        $paths = [];
        $nested = false;

        foreach ($tokens as $token) {
            [$text, $offset] = $token[0];

            if (str_starts_with($text, '___SCOPED_START_')) {
                if ($depth === 0) {
                    $bodyStart = $offset + strlen($text);
                    $paths = $this->decodeScopedPaths($token['paths'][0]);
                    $nested = false;
                } else {
                    $nested = true;
                }

                $depth++;

                continue;
            }

            if ($depth === 0) {
                continue;
            }

            $depth--;

            if ($depth === 0 && ! $nested && $paths !== []) {
                $body = trim($this->stripScopedSentinels(substr($content, $bodyStart, $offset - $bodyStart)));

                if ($body !== '') {
                    $blocks[] = ['paths' => $paths, 'body' => $body, 'start' => $bodyStart, 'end' => $offset];
                }
            }
        }

        return new Rendered($this->stripScopedSentinels($content), array_map(
            fn (array $block): array => ['paths' => $block['paths'], 'body' => $block['body']],
            $blocks,
        ), $this->withRemovableMarkers($content, $blocks));
    }

    /**
     * The rendered content with each recorded block wrapped in removable markers.
     *
     * @param  array<int, array{paths: array<int, string>, body: string, start: int, end: int}>  $blocks
     */
    private function withRemovableMarkers(string $content, array $blocks): string
    {
        foreach (array_reverse(array_keys($blocks)) as $index) {
            $block = $blocks[$index];
            $content = substr($content, 0, $block['start'])
                .Rendered::scopedMarker($index)
                .substr($content, $block['start'], $block['end'] - $block['start'])
                .Rendered::scopedMarker($index)
                .substr($content, $block['end']);
        }

        return $this->stripScopedSentinels($content);
    }

    private function stripScopedSentinels(string $content): string
    {
        return preg_replace('/___SCOPED_(?:START_[A-Za-z0-9+\/=]*|END)___/', '', $content) ?? $content;
    }

    /**
     * @return array<int, string>
     */
    private function decodeScopedPaths(string $encoded): array
    {
        $decoded = json_decode(base64_decode($encoded, true) ?: '', true);

        return array_values(array_filter(is_array($decoded) ? $decoded : [], is_string(...)));
    }

    /**
     * @return array<int, string>
     */
    private function parseScopedPaths(string $expression): array
    {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $expression, $matches);

        return array_values(array_unique($matches[1]));
    }
}
