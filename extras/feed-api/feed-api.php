<?php
/**
 * Fever Feed Management API — optional add-on (not part of Fever core).
 *
 * Install: copy this file next to Fever's index.php. Uninstall: delete this file.
 * Fever's bootstrap never loads it automatically.
 *
 * Auth: same api_key as /?api → md5(email + ":" + password) from Fever's existing
 * account config. Accept the key only in the POST body or JSON body, never in the
 * query string. This file contains no credentials.
 *
 *   action=status
 *   action=lookup    id | url
 *   action=refresh   feed_id | url | feed_ids | group_id, limit (1–20, default 5), force
 *   action=update    id | url, new_url, title, site_url, refresh
 *                    or feeds: [ {url, new_url, title, site_url}, ... ]
 *   action=delete    id | url, and confirm=1. One feed only.
 *
 * Refresh calls Fever's own refresh_feed(), so items, read state and groups stay intact.
 * Update rewrites the stored feed URL in place. It does not call edit_feed(), because
 * that method drops every group membership when group_ids is omitted.
 *
 * See README.md in this folder for install notes and examples.
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

class FeverFeedApi extends Fever
{
	const MAX_REFRESH = 20;
	const MAX_UPDATES = 100;
	const URL_MAX = 255;

	public function handle()
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->respond(405, array(
				'api_version' => 3,
				'auth' => 0,
				'error' => 'POST required',
			));
		}

		if (!$this->is_connected() || !$this->is_installed()) {
			$this->respond(503, array(
				'api_version' => 3,
				'auth' => 0,
				'error' => 'Fever database is not available',
			));
		}

		$input = $this->input();
		if (!$this->authorized(isset($input['api_key']) ? $input['api_key'] : '')) {
			$this->respond(200, array(
				'api_version' => 3,
				'auth' => 0,
			));
		}

		$this->is_silent = true;
		$action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : 'status';
		$data = array(
			'api_version' => 3,
			'auth' => 1,
			'last_refreshed_on_time' => (int) $this->get_col(
				'last_refreshed_on_time',
				'feeds',
				'1 ORDER BY `last_refreshed_on_time` DESC'
			),
		);

		if ($action === 'status') {
			$data['status'] = $this->status_report();
		} else if ($action === 'lookup') {
			$data['lookup'] = $this->lookup_request($input);
		} else if ($action === 'refresh') {
			$data['refresh'] = $this->refresh_request($input);
		} else if ($action === 'update') {
			$data['update'] = $this->update_request($input);
		} else if ($action === 'delete') {
			$data['delete'] = $this->delete_request($input);
		} else {
			$this->respond(400, array(
				'api_version' => 3,
				'auth' => 1,
				'error' => 'Unknown action. Use status, lookup, refresh, update or delete.',
			));
		}

		$this->respond(200, $data);
	}

	private function authorized($api_key)
	{
		$api_key = strtolower(trim((string) $api_key));
		$expected = md5($this->cfg['email'].':'.$this->cfg['password']);
		if ($api_key === '' || strlen($api_key) !== strlen($expected)) {
			return false;
		}
		return hash_equals($expected, $api_key);
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

	private function status_report()
	{
		$interval = (int) $this->prefs['refresh_interval'];
		if ($interval < 1) {
			$interval = 15;
		}
		$stale_before = time() - ($interval * 60);
		return array(
			'feeds_total' => (int) $this->get_count('feeds'),
			'feeds_stale' => (int) $this->get_count('feeds', '`last_refreshed_on_time` < '.(int) $stale_before),
			'refresh_interval_minutes' => $interval,
			'oldest_refreshed_on_time' => (int) $this->get_col(
				'last_refreshed_on_time',
				'feeds',
				'1 ORDER BY `last_refreshed_on_time` ASC'
			),
		);
	}

	private function refresh_request($input)
	{
		$limit = $this->bounded_int(isset($input['limit']) ? $input['limit'] : 5, 1, self::MAX_REFRESH);
		$force = $this->truthy(isset($input['force']) ? $input['force'] : 0);
		$feeds = $this->feeds_to_refresh($input, $limit, $force);
		$started = microtime(true);
		@set_time_limit(max(30, (count($feeds) * 25) + 10));

		$rows = array();
		foreach ($feeds as $feed) {
			$rows[] = $this->refresh_feed_row($feed);
		}

		$interval = (int) $this->prefs['refresh_interval'];
		if ($interval < 1) {
			$interval = 15;
		}
		$stale_before = time() - ($interval * 60);

		return array(
			'limit' => $limit,
			'force' => $force ? 1 : 0,
			'refreshed' => count($rows),
			'remaining_stale' => (int) $this->get_count('feeds', '`last_refreshed_on_time` < '.(int) $stale_before),
			'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
			'feeds' => $rows,
		);
	}

	private function feeds_to_refresh($input, $limit, $force)
	{
		if (!empty($input['feed_id']) || !empty($input['url'])) {
			$feed = $this->find_feed(
				isset($input['feed_id']) ? $input['feed_id'] : 0,
				isset($input['url']) ? $input['url'] : ''
			);
			return $feed ? array($feed) : array();
		}

		if (!empty($input['feed_ids'])) {
			$ids = $this->id_list($input['feed_ids']);
			if (!$ids) {
				return array();
			}
			$ids = array_slice($ids, 0, $limit);
			$where = '`id` IN ('.implode(',', $ids).') ORDER BY `last_refreshed_on_time` ASC LIMIT '.$limit;
			return $this->get_all('feeds', $where);
		}

		$where = '1';
		if (!$force) {
			$interval = (int) $this->prefs['refresh_interval'];
			if ($interval < 1) {
				$interval = 15;
			}
			$where = '`last_refreshed_on_time` < '.(int) (time() - ($interval * 60));
		}

		$group_id = isset($input['group_id']) ? (int) $input['group_id'] : 0;
		if ($group_id > 0) {
			$feed_ids = $this->get_cols('feed_id', 'feeds_groups', $this->prepare_sql('`group_id` = ?', $group_id));
			$feed_ids = array_map('intval', $feed_ids ? $feed_ids : array());
			$feed_ids = array_values(array_filter($feed_ids));
			if (!$feed_ids) {
				return array();
			}
			$where .= ' AND `id` IN ('.implode(',', $feed_ids).')';
		}

		$where .= ' ORDER BY `is_spark` ASC, `last_refreshed_on_time` ASC LIMIT '.$limit;
		return $this->get_all('feeds', $where);
	}

	private function refresh_feed_row($feed)
	{
		$id = (int) $feed['id'];
		$before = (int) $this->get_count('items', $this->prepare_sql('`feed_id` = ?', $id));
		$updated_before = (int) $feed['last_updated_on_time'];
		$this->reset_errors();
		$this->is_silent = true;
		$this->refresh_feed($feed);

		$after_row = $this->get_one('feeds', $this->prepare_sql('`id` = ?', $id));
		if (!$after_row) {
			$after_row = $feed;
		}
		$after = (int) $this->get_count('items', $this->prepare_sql('`feed_id` = ?', $id));
		$public = $this->public_feed($after_row);
		$public['items_before'] = $before;
		$public['items_after'] = $after;
		$public['item_delta'] = $after - $before;
		$public['content_changed'] = ((int) $after_row['last_updated_on_time'] !== $updated_before) ? 1 : 0;
		if (!empty($this->errors['list'])) {
			$public['errors'] = array_values($this->errors['list']);
		}
		return $public;
	}

	private function update_request($input)
	{
		$jobs = array();
		if (isset($input['feeds']) && is_array($input['feeds'])) {
			$jobs = array_slice(array_values($input['feeds']), 0, self::MAX_UPDATES);
		} else {
			$jobs[] = $input;
		}

		$refresh = $this->truthy(isset($input['refresh']) ? $input['refresh'] : 0);
		$refresh_budget = $this->bounded_int(isset($input['limit']) ? $input['limit'] : 5, 1, self::MAX_REFRESH);
		$results = array();
		$changed = 0;
		$refreshed = 0;

		foreach ($jobs as $job) {
			if (!is_array($job)) {
				$results[] = array('ok' => 0, 'error' => 'Each feed must be an object');
				continue;
			}
			$result = $this->update_one($job);
			if (!empty($result['ok']) && $refresh && $refreshed < $refresh_budget && !empty($result['feed']['id'])) {
				$row = $this->get_one('feeds', $this->prepare_sql('`id` = ?', (int) $result['feed']['id']));
				if ($row) {
					$result['refresh'] = $this->refresh_feed_row($row);
					$refreshed++;
				}
			}
			if (!empty($result['ok'])) {
				$changed++;
			}
			$results[] = $result;
		}

		return array(
			'requested' => count($jobs),
			'updated' => $changed,
			'feeds' => $results,
		);
	}

	private function update_one($job)
	{
		$feed = $this->find_feed(
			isset($job['id']) ? $job['id'] : (isset($job['feed_id']) ? $job['feed_id'] : 0),
			isset($job['url']) ? $job['url'] : ''
		);
		if (!$feed) {
			return array(
				'ok' => 0,
				'error' => 'Feed not found',
				'url' => isset($job['url']) ? (string) $job['url'] : '',
				'id' => isset($job['id']) ? (int) $job['id'] : (isset($job['feed_id']) ? (int) $job['feed_id'] : 0),
			);
		}

		$row = array('id' => (int) $feed['id']);
		$previous_url = $feed['url'];

		if (isset($job['new_url'])) {
			$new_url = $this->clean_feed_url($job['new_url']);
			if ($new_url === null) {
				return array('ok' => 0, 'error' => 'new_url must be an http(s) URL of at most 255 characters', 'id' => (int) $feed['id']);
			}
			$checksum = checksum(normalize_url($new_url));
			$other = $this->get_one('feeds', $this->prepare_sql('`url_checksum` = ? AND `id` != ?', $checksum, (int) $feed['id']));
			if ($other) {
				return array(
					'ok' => 0,
					'error' => 'Another feed already uses this URL',
					'id' => (int) $feed['id'],
					'conflict_id' => (int) $other['id'],
				);
			}
			$row['url'] = $new_url;
			$row['url_checksum'] = $checksum;
			if ($new_url !== $feed['url']) {
				$row['last_refreshed_on_time'] = 0;
			}
		}

		if (array_key_exists('title', $job)) {
			$title = trim((string) $job['title']);
			if (strlen($title) > self::URL_MAX) {
				return array('ok' => 0, 'error' => 'title is longer than 255 characters', 'id' => (int) $feed['id']);
			}
			$row['title'] = $title;
		}

		if (array_key_exists('site_url', $job)) {
			$site_url = trim((string) $job['site_url']);
			if ($site_url === '') {
				$row['site_url'] = '';
				$row['domain'] = null;
			} else {
				$site_url = $this->clean_feed_url($site_url);
				if ($site_url === null) {
					return array('ok' => 0, 'error' => 'site_url must be an http(s) URL of at most 255 characters', 'id' => (int) $feed['id']);
				}
				$row['site_url'] = $site_url;
				$row['domain'] = r('#(/.*)$#', '', normalize_url($site_url));
			}
		}

		if (count($row) === 1) {
			return array('ok' => 0, 'error' => 'Nothing to change. Pass new_url, title or site_url.', 'id' => (int) $feed['id']);
		}

		$this->reset_errors();
		$this->save_one('feeds', $row);
		if (!empty($this->errors['list'])) {
			return array(
				'ok' => 0,
				'error' => 'Fever rejected the update',
				'id' => (int) $feed['id'],
				'errors' => array_values($this->errors['list']),
			);
		}

		$saved = $this->get_one('feeds', $this->prepare_sql('`id` = ?', (int) $feed['id']));
		return array(
			'ok' => 1,
			'previous_url' => $previous_url,
			'feed' => $this->public_feed($saved ? $saved : array_merge($feed, $row)),
		);
	}

	private function lookup_request($input)
	{
		if (isset($input['feeds'])) {
			return array('ok' => 0, 'error' => 'lookup accepts one feed, pass url or id');
		}
		$feed = $this->find_feed(
			isset($input['id']) ? $input['id'] : (isset($input['feed_id']) ? $input['feed_id'] : 0),
			isset($input['url']) ? $input['url'] : ''
		);
		if (!$feed) {
			return array(
				'ok' => 0,
				'error' => 'Feed not found',
				'url' => isset($input['url']) ? (string) $input['url'] : '',
			);
		}
		return array(
			'ok' => 1,
			'feed' => $this->feed_snapshot($feed),
		);
	}

	private function delete_request($input)
	{
		if (isset($input['feeds']) || isset($input['feed_ids'])) {
			return array('ok' => 0, 'error' => 'delete accepts one feed, pass url or id');
		}
		if (!$this->truthy(isset($input['confirm']) ? $input['confirm'] : 0)) {
			return array('ok' => 0, 'error' => 'delete requires confirm=1');
		}

		$feed = $this->find_feed(
			isset($input['id']) ? $input['id'] : (isset($input['feed_id']) ? $input['feed_id'] : 0),
			isset($input['url']) ? $input['url'] : ''
		);
		if (!$feed) {
			return array(
				'ok' => 0,
				'error' => 'Feed not found',
				'url' => isset($input['url']) ? (string) $input['url'] : '',
			);
		}

		$snapshot = $this->feed_snapshot($feed);
		$this->reset_errors();
		$this->delete_feed((int) $feed['id']);
		$still = $this->get_one('feeds', $this->prepare_sql('`id` = ?', (int) $feed['id']));
		$result = array(
			'ok' => $still ? 0 : 1,
			'deleted' => $snapshot,
			'still_present' => $still ? 1 : 0,
		);
		if (!empty($this->errors['list'])) {
			$result['errors'] = array_values($this->errors['list']);
		}
		return $result;
	}

	private function feed_snapshot($feed)
	{
		$id = (int) $feed['id'];
		$public = $this->public_feed($feed);
		$public['items'] = (int) $this->get_count('items', $this->prepare_sql('`feed_id` = ?', $id));
		$group_ids = $this->get_cols('group_id', 'feeds_groups', $this->prepare_sql('`feed_id` = ?', $id));
		$groups = array();
		foreach ($group_ids as $group_id) {
			$group_id = (int) $group_id;
			if ($group_id < 1) {
				continue;
			}
			$group = $this->get_one('groups', $this->prepare_sql('`id` = ?', $group_id));
			$groups[] = array(
				'id' => $group_id,
				'title' => $group ? $group['title'] : '',
			);
		}
		$public['groups'] = $groups;
		return $public;
	}

	private function find_feed($id, $url)
	{
		$id = (int) $id;
		if ($id > 0) {
			$feed = $this->get_one('feeds', $this->prepare_sql('`id` = ?', $id));
			return $feed ? $feed : false;
		}

		$url = $this->clean_feed_url($url);
		if ($url === null) {
			return false;
		}
		$checksum = checksum(normalize_url($url));
		$feed = $this->get_one('feeds', $this->prepare_sql('`url_checksum` = ?', $checksum));
		if ($feed) {
			return $feed;
		}
		$feed = $this->get_one('feeds', $this->prepare_sql('`url` = ?', $url));
		return $feed ? $feed : false;
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

	private function public_feed($feed)
	{
		return array(
			'id' => (int) $feed['id'],
			'title' => $this->title($feed),
			'url' => isset($feed['url']) ? $feed['url'] : '',
			'site_url' => isset($feed['site_url']) ? $feed['site_url'] : '',
			'is_spark' => !empty($feed['is_spark']) ? 1 : 0,
			'last_refreshed_on_time' => isset($feed['last_refreshed_on_time']) ? (int) $feed['last_refreshed_on_time'] : 0,
			'last_updated_on_time' => isset($feed['last_updated_on_time']) ? (int) $feed['last_updated_on_time'] : 0,
		);
	}

	private function id_list($value)
	{
		if (is_array($value)) {
			$parts = $value;
		} else {
			$parts = explode(',', (string) $value);
		}
		$ids = array();
		foreach ($parts as $part) {
			$id = (int) $part;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		return array_values($ids);
	}

	private function bounded_int($value, $min, $max)
	{
		$number = (int) $value;
		if ($number < $min) {
			return $min;
		}
		if ($number > $max) {
			return $max;
		}
		return $number;
	}

	private function truthy($value)
	{
		if (is_bool($value)) {
			return $value;
		}
		$value = strtolower(trim((string) $value));
		return in_array($value, array('1', 'true', 'yes', 'on'), true);
	}

	private function respond($status, $data)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($data);
		exit;
	}
}

if (!defined('FEVER_FEED_API_EMBED')) {
	$api = new FeverFeedApi();
	require FIREWALL_ROOT.'app/libs/request.php';
	$api->handle();
}
