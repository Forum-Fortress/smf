<?php

namespace ForumFortress\Smf;

class DecisionMapper
{
	public static function is_valid_response(?array $response): bool
	{
		if (!is_array($response) || !isset($response['decision']))
		{
			return false;
		}

		return in_array(strtolower(trim((string) $response['decision'])), ['allow', 'block'], true);
	}

	public static function is_above_limit(?array $response): bool
	{
		return is_array($response) && strtoupper((string) ($response['status_code'] ?? '')) === 'ABOVELIMIT';
	}

	public static function decision(?array $response, bool $fail_open = false): string
	{
		if (!self::is_valid_response($response))
		{
			return $fail_open ? 'allow' : 'block';
		}
		$decision = strtolower(trim((string) $response['decision']));

		return $decision;
	}
}
