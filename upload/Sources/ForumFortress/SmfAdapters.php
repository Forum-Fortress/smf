<?php

namespace ForumFortress\Smf;

/**
 * SMF $modSettings bridge for phpBB-style config access in ApiClient.
 */
class SmfConfig implements \ArrayAccess
{
	public function offsetExists($offset): bool
	{
		global $modSettings;
		return isset($modSettings[$offset]);
	}

	public function offsetGet($offset): mixed
	{
		global $modSettings;
		return $modSettings[$offset] ?? null;
	}

	public function offsetSet($offset, $value): void
	{
		$this->set((string) $offset, $value);
	}

	public function offsetUnset($offset): void
	{
		$this->set((string) $offset, '');
	}

	public function set(string $key, mixed $value): void
	{
		updateSettings([$key => $value]);
		global $modSettings;
		$modSettings[$key] = $value;
	}
}

class SmfUser
{
	/** @var array<string, mixed> */
	public array $data;
	public string $ip;

	public function __construct()
	{
		global $user_info;
		$id = (int) ($user_info['id'] ?? 0);
		$this->data = [
			'user_id' => $id,
			'username' => (string) ($user_info['username'] ?? ''),
			'user_email' => (string) ($user_info['email'] ?? ''),
			'user_posts' => (int) ($user_info['posts'] ?? 0),
			'user_regdate' => (int) ($user_info['register_date'] ?? 0),
		];
		$this->ip = (string) ($user_info['ip'] ?? '');
	}
}

class SmfAuth
{
	public function acl_get(string $perm): bool
	{
		return allowedTo(str_replace('a_', 'admin_', $perm === 'a_' ? 'admin_forum' : $perm));
	}

	public function acl_getf_global(string $perm): bool
	{
		if ($perm === 'm_')
		{
			return allowedTo('moderate_forum');
		}
		return false;
	}
}

class SmfRequest
{
	public function header(string $name): string
	{
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		return (string) ($_SERVER[$key] ?? '');
	}

	public function server(string $name, mixed $default = ''): mixed
	{
		return $_SERVER[$name] ?? $default;
	}
}

function ffp_client(): ApiClient
{
	static $client = null;
	if ($client === null)
	{
		global $boarddir, $sourcedir;
		if (!defined('SMF_VERSION'))
		{
			require_once $boarddir . '/Settings.php';
		}
		$client = new ApiClient(
			new SmfConfig(),
			new SmfUser(),
			new SmfAuth(),
			new SmfRequest(),
			$boarddir . '/',
			'php'
		);
	}
	return $client;
}
