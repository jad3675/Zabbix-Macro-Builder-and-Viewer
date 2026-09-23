<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Lib;

use CParser,
	CUserMacroParser,
	InvalidArgumentException;

/**
 * Parsing of user macros and of the name patterns typed into the filter.
 */
class MacroKey {

	/**
	 * Parses a stored user macro.
	 *
	 * @return array|null  ['name', 'context', 'regex', 'macro' (minified)] or null if not a valid user macro.
	 */
	public static function parse(string $macro): ?array {
		$parser = new CUserMacroParser(['allow_regex' => true]);

		if ($parser->parse($macro) != CParser::PARSE_SUCCESS) {
			return null;
		}

		return [
			'name' => $parser->getMacro(),
			'context' => $parser->getContext(),
			'regex' => $parser->getRegex(),
			'macro' => $parser->getMinifiedMacro()
		];
	}

	/**
	 * Builds the minified macro for a name with a static context.
	 */
	public static function withContext(string $name, string $context): ?array {
		$parsed = self::parse('{$'.$name.':"'.str_replace('"', '\\"', $context).'"}');

		return ($parsed !== null && $parsed['context'] === $context) ? $parsed : null;
	}

	/**
	 * Turns the filter input into name patterns.
	 *
	 * Accepts a comma or whitespace separated list of names, with or without "{$" and "}", with "*" as wildcard. A
	 * context typed after the name is ignored: contexts come from the definitions and from the context filter.
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return array [
	 *     'tokens' => ['SNMP_*', 'LOW_SPACE_LIMIT', ...],   normalized name patterns
	 *     'exact' => ['LOW_SPACE_LIMIT', ...],               tokens without a wildcard
	 *     'search' => ['{$SNMP_*', '{$LOW_SPACE_LIMIT*'],    API search patterns (searchWildcardsEnabled)
	 *     'regex' => '/^(?:SNMP_.*|LOW_SPACE_LIMIT)$/'       name matcher
	 * ]
	 */
	public static function parsePatterns(string $input): array {
		$tokens = [];

		foreach (preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) as $raw) {
			$token = $raw;

			if (strpos($token, '{$') === 0) {
				$token = substr($token, 2);
			}

			$token = rtrim($token, '}');

			if (($pos = strpos($token, ':')) !== false) {
				$token = substr($token, 0, $pos);
			}

			$token = strtoupper($token);
			$token = preg_replace('/\*+/', '*', $token);

			if ($token === '' || !preg_match('/^[A-Z0-9_.*]+$/', $token)) {
				throw new InvalidArgumentException(_s('Invalid macro name pattern "%1$s".', $raw));
			}

			$tokens[$token] = true;
		}

		if (!$tokens) {
			throw new InvalidArgumentException(_('Enter at least one macro name or pattern, e.g. {$SNMP_*}.'));
		}

		$tokens = array_keys($tokens);
		$exact = array_values(array_filter($tokens, static fn(string $t): bool => strpos($t, '*') === false));

		$search = [];
		$alternatives = [];

		foreach ($tokens as $token) {
			// Trailing "*" also catches "}" and ":context}".
			$search[] = '{$'.rtrim($token, '*').'*';
			$alternatives[] = str_replace('\\*', '.*', preg_quote($token, '/'));
		}

		return [
			'tokens' => $tokens,
			'exact' => $exact,
			'search' => array_values(array_unique($search)),
			'regex' => '/^(?:'.implode('|', $alternatives).')$/'
		];
	}
}
