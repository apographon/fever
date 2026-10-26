<?php
/**
 * Fever Feed Health — optional single-page status tool.
 *
 * Install: copy from extras/feed-health/ next to index.php (same folder as
 * feed-api.php if you also use that add-on). Uninstall: delete the copy.
 * Auth: reuses Fever’s web login cookie. No credentials in this file.
 *
 * GET  → HTML dashboard
 * POST action=inventory → feed list + Fever metadata (JSON)
 * POST action=probe     → live-check one batch of feed ids (JSON)
 * POST action=update    → change title / feed URL / site URL (JSON)
 * POST action=delete    → delete one feed; requires confirm=1 (JSON)
 */

if (!defined('FIREWALLED')) {
	define('FIREWALLED', true);
}
define('FIREWALL_ROOT', dirname(__FILE__).'/firewall/');

ini_set('display_errors', '0');
error_reporting(0);
ob_start();

require FIREWALL_ROOT.'app/libs/util.php';
require FIREWALL_ROOT.'app/libs/SIDB423.php';
require FIREWALL_ROOT.'app/libs/fever.php';
require FIREWALL_ROOT.'config/db.php';
require FIREWALL_ROOT.'config/key.php';
require FIREWALL_ROOT.'app/libs/request.php';

class FeverFeedHealth extends Fever
{
	const BATCH_MAX = 8;
	const STALE_DAYS = 365;
	const PROBE_TIMEOUT = 12;
	const URL_MAX = 255;

	public function handle()
	{
		$wants_json = $this->wants_json();
		$action = isset($_REQUEST['action']) ? strtolower(trim((string) $_REQUEST['action'])) : '';

		if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			$ok = $this->authenticate();
			if ($wants_json) {
				$this->json($ok ? 200 : 401, array(
					'auth' => $ok ? 1 : 0,
					'error' => $ok ? '' : 'Email/password incorrect.',
				));
			}
			if ($ok) {
				header('Location: '.$_SERVER['PHP_SELF']);
				exit;
			}
			$this->render_login('Anmeldung fehlgeschlagen.');
			return;
		}

		if ($action === 'logout') {
			$this->logout();
			if ($wants_json) {
				$this->json(200, array('auth' => 0));
			}
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}

		if (!$this->is_logged_in()) {
			if ($wants_json || $action !== '') {
				$this->json(401, array('auth' => 0, 'error' => 'Not logged in.'));
			}
			$this->render_login();
			return;
		}

		if ($action === 'inventory') {
			$this->json(200, $this->inventory());
			return;
		}

		if ($action === 'probe') {
			$this->json(200, $this->probe_batch());
			return;
		}

		if ($action === 'update') {
			$this->json(200, $this->update_feed());
			return;
		}

		if ($action === 'delete') {
			$this->json(200, $this->delete_feed_request());
			return;
		}

		$this->render_page();
	}

	private function wants_json()
	{
		if (isset($_REQUEST['format']) && $_REQUEST['format'] === 'json') {
			return true;
		}
		$accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
		return strpos($accept, 'application/json') !== false;
	}

	private function inventory()
	{
		$groups_by_id = array();
		foreach ($this->get_all('groups') as $group) {
			$groups_by_id[(int) $group['id']] = $group['title'];
		}

		$feed_groups = array();
		foreach ($this->get_all('feeds_groups') as $row) {
			$fid = (int) $row['feed_id'];
			$gid = (int) $row['group_id'];
			if (!isset($feed_groups[$fid])) {
				$feed_groups[$fid] = array();
			}
			if (isset($groups_by_id[$gid])) {
				$feed_groups[$fid][] = $groups_by_id[$gid];
			}
		}

		$feeds = array();
		foreach ($this->get_all('feeds', '1 ORDER BY `title` ASC') as $feed) {
			$id = (int) $feed['id'];
			$items = (int) $this->get_count('items', $this->prepare_sql('`feed_id` = ?', $id));
			$feeds[] = array(
				'id' => $id,
				'title' => $this->title($feed),
				'url' => isset($feed['url']) ? $feed['url'] : '',
				'site_url' => isset($feed['site_url']) ? $feed['site_url'] : '',
				'groups' => isset($feed_groups[$id]) ? $feed_groups[$id] : array(),
				'items' => $items,
				'last_refreshed_on_time' => (int) $feed['last_refreshed_on_time'],
				'last_updated_on_time' => (int) $feed['last_updated_on_time'],
				'requires_auth' => !empty($feed['requires_auth']) ? 1 : 0,
			);
		}

		return array(
			'auth' => 1,
			'checked_at' => time(),
			'stale_days' => self::STALE_DAYS,
			'feeds_total' => count($feeds),
			'feeds' => $feeds,
		);
	}

	private function probe_batch()
	{
		$input = $this->input();
		$ids = array();
		if (isset($input['ids'])) {
			$raw = $input['ids'];
			if (is_string($raw)) {
				$raw = preg_split('/[,\s]+/', $raw);
			}
			if (is_array($raw)) {
				foreach ($raw as $id) {
					$id = (int) $id;
					if ($id > 0) {
						$ids[] = $id;
					}
				}
			}
		}
		$ids = array_values(array_unique($ids));
		$ids = array_slice($ids, 0, self::BATCH_MAX);

		global $REQUEST_TIMEOUT;
		$REQUEST_TIMEOUT = self::PROBE_TIMEOUT;
		@set_time_limit(max(30, (count($ids) * (self::PROBE_TIMEOUT + 2)) + 10));

		$results = array();
		foreach ($ids as $id) {
			$feed = $this->get_one('feeds', $this->prepare_sql('`id` = ?', $id));
			if (!$feed) {
				$results[] = array(
					'id' => $id,
					'level' => 'red',
					'reason' => 'Feed not found in Fever.',
				);
				continue;
			}
			$results[] = $this->probe_one($feed);
		}

		return array(
			'auth' => 1,
			'probed' => count($results),
			'results' => $results,
		);
	}

	private function update_feed()
	{
		$input = $this->input();
		$id = isset($input['id']) ? (int) $input['id'] : (isset($input['feed_id']) ? (int) $input['feed_id'] : 0);
		$feed = $id > 0 ? $this->get_one('feeds', $this->prepare_sql('`id` = ?', $id)) : false;
		if (!$feed) {
			return array('auth' => 1, 'ok' => 0, 'error' => 'Feed not found.');
		}

		$row = array('id' => (int) $feed['id']);
		$previous_url = isset($feed['url']) ? $feed['url'] : '';

		if (isset($input['new_url']) || isset($input['url'])) {
			$raw = isset($input['new_url']) ? $input['new_url'] : $input['url'];
			$new_url = $this->clean_feed_url($raw);
			if ($new_url === null) {
				return array('auth' => 1, 'ok' => 0, 'error' => 'Feed URL must be http(s) and ≤ 255 characters.', 'id' => (int) $feed['id']);
			}
			$checksum = checksum(normalize_url($new_url));
			$other = $this->get_one('feeds', $this->prepare_sql('`url_checksum` = ? AND `id` != ?', $checksum, (int) $feed['id']));
			if ($other) {
				return array(
					'auth' => 1,
					'ok' => 0,
					'error' => 'Another feed already uses this URL.',
					'id' => (int) $feed['id'],
					'conflict_id' => (int) $other['id'],
				);
			}
			$row['url'] = $new_url;
			$row['url_checksum'] = $checksum;
			if ($new_url !== $previous_url) {
				$row['last_refreshed_on_time'] = 0;
			}
		}

		if (array_key_exists('title', $input)) {
			$title = trim((string) $input['title']);
			if (strlen($title) > self::URL_MAX) {
				return array('auth' => 1, 'ok' => 0, 'error' => 'Title is longer than 255 characters.', 'id' => (int) $feed['id']);
			}
			$row['title'] = $title;
		}

		if (array_key_exists('site_url', $input)) {
			$site_url = trim((string) $input['site_url']);
			if ($site_url === '') {
				$row['site_url'] = '';
				$row['domain'] = null;
			} else {
				$site_url = $this->clean_feed_url($site_url);
				if ($site_url === null) {
					return array('auth' => 1, 'ok' => 0, 'error' => 'Site URL must be http(s) and ≤ 255 characters.', 'id' => (int) $feed['id']);
				}
				$row['site_url'] = $site_url;
				$row['domain'] = r('#(/.*)$#', '', normalize_url($site_url));
			}
		}

		if (count($row) === 1) {
			return array('auth' => 1, 'ok' => 0, 'error' => 'Nothing to change.', 'id' => (int) $feed['id']);
		}

		$this->reset_errors();
		$this->save_one('feeds', $row);
		if (!empty($this->errors['list'])) {
			return array(
				'auth' => 1,
				'ok' => 0,
				'error' => 'Fever rejected the update.',
				'id' => (int) $feed['id'],
				'errors' => array_values($this->errors['list']),
			);
		}

		$saved = $this->get_one('feeds', $this->prepare_sql('`id` = ?', (int) $feed['id']));
		$result = array(
			'auth' => 1,
			'ok' => 1,
			'previous_url' => $previous_url,
			'feed' => array(
				'id' => (int) $saved['id'],
				'title' => $this->title($saved),
				'url' => isset($saved['url']) ? $saved['url'] : '',
				'site_url' => isset($saved['site_url']) ? $saved['site_url'] : '',
				'last_refreshed_on_time' => (int) $saved['last_refreshed_on_time'],
				'last_updated_on_time' => (int) $saved['last_updated_on_time'],
			),
		);

		$refresh = !empty($input['refresh']) && (
			$input['refresh'] === true || $input['refresh'] === 1 || $input['refresh'] === '1' || $input['refresh'] === 'true'
		);
		if ($refresh && $saved) {
			$this->is_silent = true;
			$this->refresh_feed($saved);
			$saved = $this->get_one('feeds', $this->prepare_sql('`id` = ?', (int) $feed['id']));
			$result['feed']['last_refreshed_on_time'] = (int) $saved['last_refreshed_on_time'];
			$result['feed']['last_updated_on_time'] = (int) $saved['last_updated_on_time'];
			$result['refreshed'] = 1;
		}

		return $result;
	}

	private function delete_feed_request()
	{
		$input = $this->input();
		$confirm = isset($input['confirm']) ? $input['confirm'] : 0;
		$ok_confirm = ($confirm === true || $confirm === 1 || $confirm === '1' || $confirm === 'true');
		if (!$ok_confirm) {
			return array('auth' => 1, 'ok' => 0, 'error' => 'delete requires confirm=1');
		}

		$id = isset($input['id']) ? (int) $input['id'] : (isset($input['feed_id']) ? (int) $input['feed_id'] : 0);
		$feed = $id > 0 ? $this->get_one('feeds', $this->prepare_sql('`id` = ?', $id)) : false;
		if (!$feed) {
			return array('auth' => 1, 'ok' => 0, 'error' => 'Feed not found.');
		}

		$snapshot = array(
			'id' => (int) $feed['id'],
			'title' => $this->title($feed),
			'url' => isset($feed['url']) ? $feed['url'] : '',
		);
		$this->reset_errors();
		$this->delete_feed((int) $feed['id']);
		$still = $this->get_one('feeds', $this->prepare_sql('`id` = ?', (int) $feed['id']));
		return array(
			'auth' => 1,
			'ok' => $still ? 0 : 1,
			'deleted' => $snapshot,
			'still_present' => $still ? 1 : 0,
			'error' => $still ? 'Feed still present after delete.' : '',
		);
	}

	private function clean_feed_url($url)
	{
		$url = trim((string) $url);
		if ($url === '') {
			return null;
		}
		$url = r('#^feed://#', 'http://', $url);
		if (!preg_match('#^https?://#i', $url)) {
			return null;
		}
		if (strlen($url) > self::URL_MAX) {
			return null;
		}
		return $url;
	}

	private function probe_one($feed)
	{
		$id = (int) $feed['id'];
		$url = isset($feed['url']) ? trim($feed['url']) : '';
		$row = array(
			'id' => $id,
			'url' => $url,
			'final_url' => '',
			'http' => 0,
			'level' => 'red',
			'reason' => '',
			'entries' => 0,
			'latest' => null,
			'hints' => array(),
		);

		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			$row['reason'] = 'Invalid feed URL.';
			return $row;
		}

		if (stripos($url, 'http://') === 0) {
			$row['hints'][] = 'Uses http:// — consider https://';
		}

		$headers = array(
			'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
		);
		if (!empty($feed['auth'])) {
			$headers[] = 'Authorization: Basic '.$feed['auth'];
		}

		$request = get($url, $headers);
		$code = isset($request['headers']['response_code']) ? (int) $request['headers']['response_code'] : 0;
		$body = isset($request['body']) ? $request['body'] : '';
		$error = isset($request['error']['msg']) ? trim((string) $request['error']['msg']) : '';
		$final = isset($request['headers']['request_url']) ? $request['headers']['request_url'] : $url;

		$row['http'] = $code;
		$row['final_url'] = $final;

		if ($error !== '' && ($code < 100 || $body === '')) {
			$row['reason'] = 'Request failed: '.$error;
			return $row;
		}

		if ($code === 401 || !empty($feed['requires_auth'])) {
			$row['level'] = 'yellow';
			$row['reason'] = 'Feed requires authentication.';
			return $row;
		}

		if ($code >= 400 || $code === 0) {
			$row['reason'] = $code ? ('HTTP '.$code) : 'No HTTP response.';
			return $row;
		}

		if ($body === '' || !preg_match('#<(\?xml|rss|feed|rdf:RDF)\b#i', $body)) {
			$row['reason'] = 'Response is not RSS/Atom.';
			return $row;
		}

		$meta = $this->feed_meta($body);
		$row['entries'] = $meta['entries'];
		$row['latest'] = $meta['latest'];

		if ($final !== '' && strcasecmp($final, $url) !== 0) {
			$row['hints'][] = 'Redirects to '.$final;
		}

		$stale_before = time() - (self::STALE_DAYS * 86400);
		if ($meta['latest'] !== null && $meta['latest'] < $stale_before) {
			$row['level'] = 'yellow';
			$row['reason'] = 'Valid feed, but newest item is older than '.self::STALE_DAYS.' days.';
			return $row;
		}

		if ($meta['entries'] < 1) {
			$row['level'] = 'yellow';
			$row['reason'] = 'Valid feed markup, but no items found.';
			return $row;
		}

		$row['level'] = 'green';
		if ($meta['latest'] !== null) {
			$row['reason'] = 'OK — newest item '.$this->ago($meta['latest']).'.';
		} else {
			$row['reason'] = 'OK — valid feed with '.$meta['entries'].' items (no reliable dates).';
		}
		return $row;
	}

	private function feed_meta($body)
	{
		$entries = 0;
		if (preg_match_all('#<(item|entry)\b#i', $body, $m)) {
			$entries = count($m[0]);
		}

		$dates = array();
		if (preg_match_all('#<(pubDate|published|updated|dc:date|date)[^>]*>([^<]+)</#i', $body, $m)) {
			foreach ($m[2] as $raw) {
				$ts = strtotime(trim($raw));
				if ($ts !== false && $ts > 0 && $ts < (time() + 86400)) {
					$dates[] = $ts;
				}
			}
		}

		return array(
			'entries' => $entries,
			'latest' => $dates ? max($dates) : null,
		);
	}

	private function ago($ts)
	{
		$days = (int) floor((time() - $ts) / 86400);
		if ($days <= 0) {
			return 'today';
		}
		if ($days === 1) {
			return '1 day ago';
		}
		if ($days < 60) {
			return $days.' days ago';
		}
		return date('Y-m-d', $ts);
	}

	private function input()
	{
		$raw = file_get_contents('php://input');
		if (is_string($raw) && $raw !== '') {
			$trimmed = ltrim($raw);
			if ($trimmed !== '' && $trimmed[0] === '{') {
				$json = json_decode($raw, true);
				if (is_array($json)) {
					return $json;
				}
			}
		}
		return is_array($_POST) ? $_POST : array();
	}

	private function json($status, $data)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	private function h($value)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}

	private function asset_version()
	{
		return isset($this->version) ? rawurlencode((string) $this->version) : '1';
	}

	private function render_shell_start($title, $wide = false)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		$v = $this->asset_version();
		$wrap = $wide ? 'health-page' : 'page';
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width">';
		echo '<meta name="robots" content="noindex,nofollow" />';
		echo '<title>'.$this->h($title).'</title>';
		echo '<link rel="shortcut icon" type="image/png" href="firewall/app/images/favicon.png" />';
		echo '<link rel="apple-touch-icon" href="firewall/app/images/webclip.png" />';
		echo '<link rel="stylesheet" type="text/css" href="firewall/app/views/default/styles/page.css?v='.$v.'" />';
		echo '<style type="text/css">'.$this->css().'</style></head><body>';
		echo '<div class="'.$wrap.'"><div class="box"><div class="content">';
	}

	private function render_shell_end()
	{
		echo '</div>';
		echo '<s class="box"><u><u></u></u><i><i></i></i><b><b></b></b><s><s></s></s></s>';
		echo '</div><div class="footer">';
		echo '<a href="./" id="logo" title="Fever"><img src="firewall/app/images/logo-fever.png" alt="Fever" width="50" height="17" /></a>';
		echo ' Feed Health · <a href="./">reader</a>';
		echo '</div></div></body></html>';
	}

	private function render_login($error = '')
	{
		$this->render_shell_start('Feed Health · Fever');
		echo '<form method="post" action="" id="login-form">';
		echo '<input type="hidden" name="action" value="login" />';
		echo '<h1>Feed Health</h1>';
		echo '<h2>Login required</h2>';
		if ($error !== '') {
			echo '<p class="text">'.$this->h($error).'</p>';
		} else {
			echo '<p class="text">Use your Fever account. Already logged in? <a href="./">Open the reader</a> first.</p>';
		}
		echo '<table>';
		echo '<tr><th>Email</th><td colspan="2"><span class="w"><input type="email" name="email" required autocomplete="username" /></span></td></tr>';
		echo '<tr><th class="proto">Password</th><td class="proto"><span class="w"><input type="password" name="password" required autocomplete="current-password" /></span></td>';
		echo '<td class="btn-row"><button type="submit"><span class="btn text default">Login<i></i></span></button></td></tr>';
		echo '</table></form>';
		$this->render_shell_end();
		exit;
	}

	private function render_page()
	{
		$batch = self::BATCH_MAX;
		$stale = self::STALE_DAYS;
		$this->render_shell_start('Feed Health · Fever', true);
		echo '<div class="health-top">';
		echo '<h1>Feed Health</h1>';
		echo '<p class="health-nav"><a href="./">Fever</a> · <a href="?action=logout">Logout</a></p>';
		echo '</div>';
		echo '<h2>Live-check every subscribed feed (HTTP, TLS, RSS/Atom)</h2>';

		echo '<div class="health-toolbar">';
		echo '<span class="pill" id="pill">loading…</span>';
		echo '<span class="counts" id="counts"></span>';
		echo '<span class="health-actions">';
		echo '<button type="button" id="btn-run" disabled><span class="btn text default">Check feeds<i></i></span></button> ';
		echo '<button type="button" id="btn-stop" hidden><span class="btn text">Stop<i></i></span></button> ';
		echo '<label class="filter"><input type="checkbox" id="only-bad" /> problems only</label>';
		echo '</span></div>';

		echo '<div class="progress" id="progress" hidden><div class="bar"><span id="bar"></span></div><p id="progress-text"></p></div>';

		echo '<div class="health-table-wrap"><table class="health-table" id="health-table">';
		echo '<thead><tr><th>Status</th><th>Feed</th><th>HTTP</th><th>Note</th><th></th></tr></thead>';
		echo '<tbody id="rows"><tr><td colspan="5">Loading inventory…</td></tr></tbody>';
		echo '</table></div>';

		echo '<div id="edit-dialog" class="health-dialog" hidden>';
		echo '<form id="edit-form">';
		echo '<h2>Edit feed</h2>';
		echo '<input type="hidden" id="edit-id" />';
		echo '<table>';
		echo '<tr><th>Title</th><td><span class="w"><input type="text" id="edit-title" maxlength="255" /></span></td></tr>';
		echo '<tr><th class="proto">Feed URL</th><td class="proto"><span class="w"><input type="url" id="edit-url" maxlength="255" required /></span>';
		echo '<p class="hint" id="edit-redirect" hidden></p></td></tr>';
		echo '<tr><th>Site URL</th><td><span class="w"><input type="url" id="edit-site" maxlength="255" /></span></td></tr>';
		echo '<tr><td colspan="2" class="btn-row">';
		echo '<label class="filter"><input type="checkbox" id="edit-refresh" checked /> refresh after save</label> ';
		echo '<button type="submit"><span class="btn text default">Save<i></i></span></button> ';
		echo '<button type="button" id="edit-cancel"><span class="btn text">Cancel<i></i></span></button>';
		echo '</td></tr></table>';
		echo '<p class="text" id="edit-error" hidden></p>';
		echo '</form></div>';

		echo '<script>window.FEED_HEALTH={batch:'.$batch.',staleDays:'.$stale.'};</script>';
		echo '<script>'.$this->js().'</script>';
		$this->render_shell_end();
		exit;
	}

	private function css()
	{
		return <<<'CSS'
/* Feed Health — sits on top of Fever page.css */
.health-page {
	width: min(920px, calc(100% - 24px));
	margin: 0 auto;
}
.health-page h1 {
	font-weight: 300;
	font-size: 42px;
	line-height: 44px;
	font-family: 'HelveticaNeue-Light', 'Helvetica Neue Light', 'Helvetica Neue', Helvetica, Arial, sans-serif;
	color: #FFF;
	text-shadow: 1px 1px 1px #CCC;
	padding: 12px 0 0;
	margin: 0 13px 4px;
}
.health-page h2 {
	font-size: 12px;
	color: #A10;
	margin: 0 15px 12px;
	font-weight: bold;
}
.health-top {
	display: flex;
	justify-content: space-between;
	align-items: flex-end;
	gap: 12px;
	flex-wrap: wrap;
}
.health-nav {
	margin: 0 15px 10px 0;
	color: #666;
	font-size: 11px;
}
.health-nav a { color: #333; }
.health-nav a:hover { color: #A10; }

.health-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px 14px;
	margin: 0 8px 10px;
	padding: 8px 10px;
	background: rgba(0,0,0,0.04);
}
.pill {
	display: inline-block;
	font-weight: bold;
	font-size: 11px;
	padding: 2px 8px;
	background: #fff;
	border: 1px solid #ccc;
	color: #333;
}
.pill.ok { color: #1f6b45; border-color: #9ccbb0; background: #e8f5ee; }
.pill.warn { color: #9a6700; border-color: #e0c57a; background: #fff6de; }
.pill.bad { color: #A10; border-color: #e0a0a8; background: #fdecee; }
.counts { flex: 1; color: #666; font-size: 11px; }
.counts b { color: #333; }
.counts .ok { color: #1f6b45; }
.counts .warn { color: #9a6700; }
.counts .err { color: #A10; }
.health-actions { white-space: nowrap; }
.filter { color: #666; font-size: 11px; font-weight: normal; margin-left: 6px; vertical-align: middle; }

.progress { margin: 0 15px 10px; }
.progress .bar {
	height: 4px;
	background: #ddd;
	overflow: hidden;
}
.progress .bar > span {
	display: block;
	height: 100%;
	width: 0;
	background: #A10;
	transition: width .2s ease;
}
#progress-text { margin: 4px 0 0; color: #666; font-size: 10px; }

.health-table-wrap {
	margin: 0 5px 8px;
	overflow: auto;
	max-height: min(70vh, 720px);
	background: rgba(255,255,255,0.35);
}
.health-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 11px;
	line-height: 1.35;
}
.health-table th,
.health-table td {
	padding: 6px 10px;
	text-align: left;
	vertical-align: top;
	border-bottom: 1px solid rgba(0,0,0,0.06);
}
.health-table th {
	position: sticky;
	top: 0;
	z-index: 2;
	background: #f0f0f0;
	color: #A10;
	font-size: 10px;
	font-weight: bold;
}
.health-table tr:nth-child(even) td { background: rgba(0,0,0,0.03); }
.health-table tr.hidden { display: none; }
.health-table a { font-weight: normal; word-break: break-all; }
.health-table a:hover { color: #A10; }

.dot {
	display: inline-block;
	min-width: 3.6rem;
	padding: 1px 5px;
	text-align: center;
	font-size: 9px;
	font-weight: bold;
	letter-spacing: .04em;
	text-transform: uppercase;
	background: #eee;
	color: #666;
}
.dot.green { color: #1f6b45; background: #e8f5ee; }
.dot.yellow { color: #9a6700; background: #fff6de; }
.dot.red { color: #A10; background: #fdecee; }
.dot.pending { color: #888; background: #eee; }

.title { font-weight: bold; color: #333; }
.url, .hint, .groups { color: #666; font-size: 10px; }
.groups { display: block; margin-top: 1px; }
.hint { margin-top: 2px; }
.muted { color: #666; }
.row-actions { white-space: nowrap; font-size: 10px; }
.row-actions a { font-weight: bold; margin: 0 2px; }
.row-actions a.danger:hover { color: #A10; }

.health-dialog {
	position: fixed;
	z-index: 2000;
	left: 50%;
	top: 12%;
	transform: translateX(-50%);
	width: min(640px, calc(100% - 24px));
	background: #f4f4f4;
	border: 1px solid #bbb;
	box-shadow: 3px 3px 12px rgba(0,0,0,.18);
	padding: 8px 4px 4px;
}
.health-dialog[hidden] { display: none !important; }
.health-dialog h2 { margin: 4px 15px 8px; }
.health-dialog .btn-row { text-align: right; }
.health-dialog #edit-error { color: #A10; margin: 0 15px 8px; }
.health-dialog #edit-redirect a { font-weight: bold; }
.health-dialog table {
	width: 100%;
	table-layout: fixed;
}
.health-dialog th {
	width: 72px;
	white-space: nowrap;
}
.health-dialog td.proto,
.health-dialog td {
	width: auto;
}
.health-dialog span.w {
	width: 100%;
}
.health-dialog #edit-url,
.health-dialog #edit-site,
.health-dialog #edit-title {
	width: 100%;
	box-sizing: border-box;
}
.health-backdrop {
	position: fixed;
	z-index: 1999;
	inset: 0;
	background: rgba(0,0,0,.25);
}
.health-backdrop[hidden] { display: none !important; }

@media (max-width: 640px) {
	.health-page h1 { font-size: 32px; line-height: 34px; }
	.health-table th:nth-child(3),
	.health-table td:nth-child(3) { display: none; }
}
CSS;
	}

	private function js()
	{
		return <<<'JS'
(function(){
  const cfg=window.FEED_HEALTH||{batch:8};
  const rowsEl=document.getElementById('rows');
  const pill=document.getElementById('pill');
  const counts=document.getElementById('counts');
  const btn=document.getElementById('btn-run');
  const btnStop=document.getElementById('btn-stop');
  const onlyBad=document.getElementById('only-bad');
  const progress=document.getElementById('progress');
  const bar=document.getElementById('bar');
  const progressText=document.getElementById('progress-text');
  const dialog=document.getElementById('edit-dialog');
  const editForm=document.getElementById('edit-form');
  const editId=document.getElementById('edit-id');
  const editTitle=document.getElementById('edit-title');
  const editUrl=document.getElementById('edit-url');
  const editSite=document.getElementById('edit-site');
  const editRefresh=document.getElementById('edit-refresh');
  const editError=document.getElementById('edit-error');
  const editRedirect=document.getElementById('edit-redirect');
  const editCancel=document.getElementById('edit-cancel');
  let feeds=[];
  let byId={};
  let backdrop=null;
  let probeAbort=null;

  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  function ago(ts){if(!ts)return '—';const d=Math.floor((Date.now()/1000-ts)/86400);if(d<=0)return 'today';if(d===1)return '1 day ago';if(d<60)return d+' days ago';return new Date(ts*1000).toISOString().slice(0,10);}
  function post(action,body,signal){
    return fetch('?action='+encodeURIComponent(action)+'&format=json',{
      method:'POST',credentials:'same-origin',signal:signal,
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify(body||{})
    }).then(r=>{if(r.status===401){location.reload();throw new Error('auth');}return r.json();});
  }
  function setRunning(on){
    btn.disabled=on || !feeds.length;
    btnStop.hidden=!on;
  }

  function ensureBackdrop(){
    if(backdrop) return backdrop;
    backdrop=document.createElement('div');
    backdrop.className='health-backdrop';
    backdrop.hidden=true;
    backdrop.addEventListener('click',closeEdit);
    document.body.appendChild(backdrop);
    return backdrop;
  }

  function openEdit(id){
    const f=byId[id]; if(!f) return;
    editId.value=String(f.id);
    editTitle.value=f.title||'';
    editUrl.value=f.url||'';
    editSite.value=f.site_url||'';
    editError.hidden=true; editError.textContent='';
    const finalUrl=f.probe&&f.probe.final_url;
    if(finalUrl && finalUrl!==f.url){
      editRedirect.hidden=false;
      editRedirect.innerHTML='Detected redirect → <a href="#" id="use-redirect">'+esc(finalUrl)+'</a>';
      const link=document.getElementById('use-redirect');
      if(link) link.addEventListener('click',function(e){e.preventDefault();editUrl.value=finalUrl;});
    } else {
      editRedirect.hidden=true; editRedirect.textContent='';
    }
    ensureBackdrop().hidden=false;
    dialog.hidden=false;
    editUrl.focus(); editUrl.select();
  }

  function closeEdit(){
    dialog.hidden=true;
    if(backdrop) backdrop.hidden=true;
  }

  function render(){
    const filterOn=onlyBad.checked;
    rowsEl.innerHTML=feeds.map(f=>{
      const p=f.probe||{};
      const level=p.level||'pending';
      const show=!(filterOn&&(level==='green'||level==='pending'));
      const groups=(f.groups||[]).join(' · ');
      const reason=p.reason||('Fever: '+f.items+' items, update '+ago(f.last_updated_on_time));
      const hints=(p.hints||[]).map(h=>'<div class="hint">'+esc(h)+'</div>').join('');
      return '<tr class="'+(show?'':'hidden')+'" data-id="'+f.id+'">'+
        '<td><span class="dot '+level+'">'+esc(level==='pending'?'…':level)+'</span></td>'+
        '<td><div class="title">'+esc(f.title)+'</div><div class="url"><a href="'+esc(f.url)+'" target="_blank" rel="noopener">'+esc(f.url)+'</a></div>'+
        (groups?'<span class="groups">'+esc(groups)+'</span>':'')+'</td>'+
        '<td>'+(p.http||'—')+'</td>'+
        '<td><div>'+esc(reason)+'</div>'+hints+'</td>'+
        '<td class="row-actions">'+
          '<a href="#" data-act="edit" data-id="'+f.id+'">edit</a>·'+
          '<a href="#" class="danger" data-act="delete" data-id="'+f.id+'">delete</a>'+
        '</td></tr>';
    }).join('')||'<tr><td colspan="5" class="muted">No feeds.</td></tr>';
    updateSummary();
  }

  function updateSummary(){
    let g=0,y=0,r=0,p=0;
    feeds.forEach(f=>{const l=(f.probe&&f.probe.level)||'pending';if(l==='green')g++;else if(l==='yellow')y++;else if(l==='red')r++;else p++;});
    counts.innerHTML='<span><b>'+feeds.length+'</b> feeds</span> · '+
      '<span class="ok"><b>'+g+'</b> green</span> · '+
      '<span class="warn"><b>'+y+'</b> yellow</span> · '+
      '<span class="err"><b>'+r+'</b> red</span>'+
      (p?' · <span><b>'+p+'</b> pending</span>':'');
    pill.className='pill';
    if(p&&(g+y+r)===0){pill.textContent='ready to check';}
    else if(p){pill.textContent='checking…';}
    else if(r){pill.classList.add('bad');pill.textContent='problems found';}
    else if(y){pill.classList.add('warn');pill.textContent='reachable, some stale';}
    else if(feeds.length){pill.classList.add('ok');pill.textContent='all clear';}
    else{pill.textContent='no feeds';}
  }

  async function loadInventory(){
    const data=await post('inventory');
    byId={};
    feeds=(data.feeds||[]).map(f=>{byId[f.id]=f;return f;});
    setRunning(false);
    render();
  }

  async function runProbe(){
    if(probeAbort) return;
    probeAbort=new AbortController();
    setRunning(true);
    progress.hidden=false;
    const ids=feeds.map(f=>f.id);
    let done=0;
    let stopped=false;
    try{
      for(let i=0;i<ids.length;i+=cfg.batch){
        if(probeAbort.signal.aborted){ stopped=true; break; }
        const batch=ids.slice(i,i+cfg.batch);
        const data=await post('probe',{ids:batch},probeAbort.signal);
        (data.results||[]).forEach(res=>{
          const f=byId[res.id];if(!f)return;f.probe=res;
        });
        done+=batch.length;
        const pct=Math.round(100*done/ids.length);
        bar.style.width=pct+'%';
        progressText.textContent=done+' / '+ids.length+' checked';
        render();
      }
    }catch(err){
      if(err && err.name==='AbortError') stopped=true;
      else throw err;
    }finally{
      probeAbort=null;
      setRunning(false);
    }
    progressText.textContent=stopped
      ? 'Stopped — '+done+' / '+ids.length+' checked. Already finished rows stay.'
      : 'Done — '+ids.length+' feeds checked.';
  }

  function stopProbe(){
    if(probeAbort) probeAbort.abort();
  }

  async function deleteFeed(id){
    const f=byId[id]; if(!f) return;
    if(!confirm('Delete feed "'+f.title+'"?\n\n'+f.url)) return;
    const data=await post('delete',{id:id,confirm:1});
    if(!data.ok){ alert(data.error||'Delete failed'); return; }
    feeds=feeds.filter(x=>x.id!==id);
    delete byId[id];
    render();
  }

  rowsEl.addEventListener('click',function(e){
    const a=e.target.closest('a[data-act]');
    if(!a) return;
    e.preventDefault();
    const id=parseInt(a.getAttribute('data-id'),10);
    if(a.getAttribute('data-act')==='edit') openEdit(id);
    if(a.getAttribute('data-act')==='delete') deleteFeed(id);
  });

  editCancel.addEventListener('click',function(e){e.preventDefault();closeEdit();});
  editForm.addEventListener('submit',async function(e){
    e.preventDefault();
    editError.hidden=true;
    const id=parseInt(editId.value,10);
    const payload={
      id:id,
      title:editTitle.value,
      new_url:editUrl.value.trim(),
      site_url:editSite.value.trim(),
      refresh:editRefresh.checked?1:0
    };
    const data=await post('update',payload);
    if(!data.ok){
      editError.textContent=data.error||'Update failed';
      editError.hidden=false;
      return;
    }
    const f=byId[id];
    if(f && data.feed){
      f.title=data.feed.title;
      f.url=data.feed.url;
      f.site_url=data.feed.site_url;
      f.last_refreshed_on_time=data.feed.last_refreshed_on_time;
      f.last_updated_on_time=data.feed.last_updated_on_time;
      f.probe=undefined;
    }
    closeEdit();
    render();
    if(editRefresh.checked){
      const probed=await post('probe',{ids:[id]});
      (probed.results||[]).forEach(res=>{const row=byId[res.id];if(row)row.probe=res;});
      render();
    }
  });

  onlyBad.addEventListener('change',render);
  btn.addEventListener('click',runProbe);
  btnStop.addEventListener('click',stopProbe);
  loadInventory().catch(err=>{
    rowsEl.innerHTML='<tr><td colspan="5" class="err">Could not load inventory.</td></tr>';
  });
})();
JS;
	}
}

$app = new FeverFeedHealth();
$app->handle();
