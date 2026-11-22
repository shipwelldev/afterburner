<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Database;

/**
 * How a database engine, under one combination of its server modes, reads
 * comments and quoted text.
 *
 * The validator has to see a query the way the engine will: a comment or
 * literal it misreads could hide code the engine executes, such as MySQL's
 * `SELECT 1--1 INTO OUTFILE …`, where `--` is subtraction rather than a comment.
 */
final readonly class SqlDialect
{
    public function __construct(
        // MySQL and MariaDB: "--" starts a comment only before whitespace or a control character.
        public bool $dashCommentNeedsSpace = false,
        // MySQL and MariaDB: "#" starts a line comment.
        public bool $hashComments = false,
        // PostgreSQL: block comments nest.
        public bool $nestedBlockComments = false,
        // Characters that open quoted text closed by the same character.
        public string $quotes = '\'"',
        // SQLite: [bracketed identifiers].
        public bool $bracketIdentifiers = false,
        // PostgreSQL: E'…' escape strings and $tag$…$tag$ dollar-quoted strings.
        public bool $postgresStrings = false,
        // Whether a backslash escapes the next character inside '…' or "…".
        public bool $singleQuoteBackslash = false,
        public bool $doubleQuoteBackslash = false,
    ) {}

    /**
     * Every way the driver can read a query under its server modes, its default first.
     *
     * An unknown driver gets every supported engine's readings.
     *
     * @return list<self>
     */
    public static function readings(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [new self(quotes: '\'"`', bracketIdentifiers: true)],
            // The default modes, ANSI_QUOTES, and NO_BACKSLASH_ESCAPES.
            'mysql', 'mariadb' => [
                new self(dashCommentNeedsSpace: true, hashComments: true, quotes: '\'"`', singleQuoteBackslash: true, doubleQuoteBackslash: true),
                new self(dashCommentNeedsSpace: true, hashComments: true, quotes: '\'"`', singleQuoteBackslash: true),
                new self(dashCommentNeedsSpace: true, hashComments: true, quotes: '\'"`'),
            ],
            // standard_conforming_strings on (the default) and off.
            'pgsql' => [
                new self(nestedBlockComments: true, postgresStrings: true),
                new self(nestedBlockComments: true, postgresStrings: true, singleQuoteBackslash: true),
            ],
            default => [...self::readings('sqlite'), ...self::readings('mysql'), ...self::readings('pgsql')],
        };
    }
}
