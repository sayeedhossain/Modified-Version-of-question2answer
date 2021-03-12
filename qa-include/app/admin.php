<?php
/*
	Question2Answer by Gideon Greenspan and contributors
	http://www.question2answer.org/

	Description: Functions used in the admin center pages


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	More about this license: http://www.question2answer.org/license.php
*/

if (!defined('QA_VERSION')) { // don't allow this page to be requested directly from browser
	header('Location: ../../');
	exit;
}


/**
 * Return true if user is logged in with admin privileges. If not, return false
 * and set up $qa_content with the appropriate title and error message
 * @param array $qa_content
 * @return bool
 */
function qa_admin_check_privileges(&$qa_content)
{
	if (!qa_is_logged_in()) {
		require_once QA_INCLUDE_DIR . 'app/format.php';

		$qa_content = qa_content_prepare();

		$qa_content['title'] = qa_lang_html('admin/admin_title');
		$qa_content['error'] = qa_insert_login_links(qa_lang_html('admin/not_logged_in'), qa_request());

		return false;

	} elseif (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
		$qa_content = qa_content_prepare();

		$qa_content['title'] = qa_lang_html('admin/admin_title');
		$qa_content['error'] = qa_lang_html('admin/no_privileges');

		return false;
	}

	return true;
}


/**
 * Return a sorted array of available languages, [short code] => [long name]
 * @return array
 */
function qa_admin_language_options()
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	/**
	 * @deprecated The hardcoded language ids will be removed in favor of language metadata files.
	 * See qa-lang/en-GB directory for a clear example of how to use them.
	 */
	$codetolanguage = array( // 2-letter language codes as per ISO 639-1
		'ar' => 'Arabic - العربية',
		'az' => 'Azerbaijani - Azərbaycanca',
		'bg' => 'Bulgarian - Български',
		'bn' => 'Bengali - বাংলা',
		'ca' => 'Catalan - Català',
		'cs' => 'Czech - Čeština',
		'cy' => 'Welsh - Cymraeg',
		'da' => 'Danish - Dansk',
		'de' => 'German - Deutsch',
		'el' => 'Greek - Ελληνικά',
		'en-GB' => 'English (UK)',
		'es' => 'Spanish - Español',
		'et' => 'Estonian - Eesti',
		'fa' => 'Persian - فارسی',
		'fi' => 'Finnish - Suomi',
		'fr' => 'French - Français',
		'he' => 'Hebrew - עברית',
		'hr' => 'Croatian - Hrvatski',
		'hu' => 'Hungarian - Magyar',
		'id' => 'Indonesian - Bahasa Indonesia',
		'is' => 'Icelandic - Íslenska',
		'it' => 'Italian - Italiano',
		'ja' => 'Japanese - 日本語',
		'ka' => 'Georgian - ქართული ენა',
		'kh' => 'Khmer - ភាសាខ្មែរ',
		'ko' => 'Korean - 한국어',
		'ku-CKB' => 'Kurdish Central - کورد',
		'lt' => 'Lithuanian - Lietuvių',
		'lv' => 'Latvian - Latviešu',
		'nl' => 'Dutch - Nederlands',
		'no' => 'Norwegian - Norsk',
		'pl' => 'Polish - Polski',
		'pt' => 'Portuguese - Português',
		'ro' => 'Romanian - Română',
		'ru' => 'Russian - Русский',
		'sk' => 'Slovak - Slovenčina',
		'sl' => 'Slovenian - Slovenščina',
		'sq' => 'Albanian - Shqip',
		'sr' => 'Serbian - Српски',
		'sv' => 'Swedish - Svenska',
		'th' => 'Thai - ไทย',
		'tr' => 'Turkish - Türkçe',
		'ug' => 'Uyghur - ئۇيغۇرچە',
		'uk' => 'Ukrainian - Українська',
		'uz' => 'Uzbek - ўзбек',
		'vi' => 'Vietnamese - Tiếng Việt',
		'zh-TW' => 'Chinese Traditional - 繁體中文',
		'zh' => 'Chinese Simplified - 简体中文',
	);

	$options = array('' => 'English (US)');

	// find all language folders
	$metadataUtil = new \Q2A\Util\Metadata();
	foreach (glob(QA_LANG_DIR . '*', GLOB_ONLYDIR) as $directory) {
		$code = basename($directory);
		$metadata = $metadataUtil->fetchFromAddonPath($directory);
		if (isset($metadata['name'])) {
			$options[$code] = $metadata['name'];
		} elseif (isset($codetolanguage[$code])) {
			// otherwise use an entry from above
			$options[$code] = $codetolanguage[$code];
		}
	}

	asort($options, SORT_STRING);
	return $options;
}


/**
 * Return a sorted array of available themes, [theme name] => [theme name]
 * @return array
 */
function qa_admin_theme_options()
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	$metadataUtil = new \Q2A\Util\Metadata();
	foreach (glob(QA_THEME_DIR . '*', GLOB_ONLYDIR) as $directory) {
		$theme = basename($directory);
		$metadata = $metadataUtil->fetchFromAddonPath($directory);
		if (empty($metadata)) {
			// limit theme parsing to first 8kB
			$contents = @file_get_contents($directory . '/qa-styles.css', false, null, 0, 8192);
			$metadata = qa_addon_metadata($contents, 'Theme');
		}
		$options[$theme] = isset($metadata['name']) ? $metadata['name'] : $theme;
	}

	asort($options, SORT_STRING);
	return $options;
}


/**
 * Return an array of widget placement options, with keys matching the database value
 * @return array
 */
function qa_admin_place_options()
{
	return array(
		'FT' => qa_lang_html('options/place_full_top'),
		'FH' => qa_lang_html('options/place_full_below_nav'),
		'FL' => qa_lang_html('options/place_full_below_content'),
		'FB' => qa_lang_html('options/place_full_below_footer'),
		'MT' => qa_lang_html('options/place_main_top'),
		'MH' => qa_lang_html('options/place_main_below_title'),
		'ML' => qa_lang_html('options/place_main_below_lists'),
		'MB' => qa_lang_html('options/place_main_bottom'),
		'ST' => qa_lang_html('options/place_side_top'),
		'SH' => qa_lang_html('options/place_side_below_sidebar'),
		'SL' => qa_lang_html('options/place_side_low'),
		'SB' => qa_lang_html('options/place_side_last'),
	);
}


/**
 * Return an array of page size options up to $maximum, [page size] => [page size]
 * @param int $maximum
 * @return array
 */
function qa_admin_page_size_options($maximum)
{
	$rawoptions = array(5, 10, 15, 20, 25, 30, 40, 50, 60, 80, 100, 120, 150, 200, 250, 300, 400, 500, 600, 800, 1000);

	$options = array();
	foreach ($rawoptions as $rawoption) {
		if ($rawoption > $maximum)
			break;

		$options[$rawoption] = $rawoption;
	}

	return $options;
}


/**
 * Return an array of options representing matching precision, [value] => [label]
 * @return array
 */
function qa_admin_match_options()
{
	return array(
		5 => qa_lang_html('options/match_5'),
		4 => qa_lang_html('options/match_4'),
		3 => qa_lang_html('options/match_3'),
		2 => qa_lang_html('options/match_2'),
		1 => qa_lang_html('options/match_1'),
	);
}


/**
 * Return an array of options representing permission restrictions, [value] => [label]
 * ranging from $widest to $narrowest. Set $doconfirms to whether email confirmations are on
 * @param int $widest
 * @param int $narrowest
 * @param bool $doconfirms
 * @param bool $dopoints
 * @return array
 */
function qa_admin_permit_options($widest, $narrowest, $doconfirms = true, $dopoints = true)
{
	require_once QA_INCLUDE_DIR . 'app/options.php';

	$options = array(
		QA_PERMIT_ALL => qa_lang_html('options/permit_all'),
		QA_PERMIT_USERS => qa_lang_html('options/permit_users'),
		QA_PERMIT_CONFIRMED => qa_lang_html('options/permit_confirmed'),
		QA_PERMIT_POINTS => qa_lang_html('options/permit_points'),
		QA_PERMIT_POINTS_CONFIRMED => qa_lang_html('options/permit_points_confirmed'),
		QA_PERMIT_APPROVED => qa_lang_html('options/permit_approved'),
		QA_PERMIT_APPROVED_POINTS => qa_lang_html('options/permit_approved_points'),
		QA_PERMIT_EXPERTS => qa_lang_html('options/permit_experts'),
		QA_PERMIT_EDITORS => qa_lang_html('options/permit_editors'),
		QA_PERMIT_MODERATORS => qa_lang_html('options/permit_moderators'),
		QA_PERMIT_ADMINS => qa_lang_html('options/permit_admins'),
		QA_PERMIT_SUPERS => qa_lang_html('options/permit_supers'),
	);

	foreach ($options as $key => $label) {
		if ($key < $narrowest || $key > $widest)
			unset($options[$key]);
	}

	if (!$doconfirms) {
		unset($options[QA_PERMIT_CONFIRMED]);
		unset($options[QA_PERMIT_POINTS_CONFIRMED]);
	}

	if (!$dopoints) {
		unset($options[QA_PERMIT_POINTS]);
		unset($options[QA_PERMIT_POINTS_CONFIRMED]);
		unset($options[QA_PERMIT_APPROVED_POINTS]);
	}

	if (QA_FINAL_EXTERNAL_USERS || !qa_opt('moderate_users')) {
		unset($options[QA_PERMIT_APPROVED]);
		unset($options[QA_PERMIT_APPROVED_POINTS]);
	}

	return $options;
}


/**
 * Return the sub navigation structure common to admin pages
 * @return array
 */
function qa_admin_sub_navigation()
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	$navigation = array();
	$level = qa_get_logged_in_level();

	if ($level >= QA_USER_LEVEL_ADMIN) {
		$navigation['admin/general'] = array(
			'label' => qa_lang_html('admin/general_title'),
			'url' => qa_path_html('admin/general'),
		);

		$navigation['admin/emails'] = array(
			'label' => qa_lang_html('admin/emails_title'),
			'url' => qa_path_html('admin/emails'),
		);

		$navigation['admin/users'] = array(
			'label' => qa_lang_html('admin/users_title'),
			'url' => qa_path_html('admin/users'),
			'selected_on' => array('admin/users$', 'admin/userfields$', 'admin/usertitles$'),
		);

		$navigation['admin/layout'] = array(
			'label' => qa_lang_html('admin/layout_title'),
			'url' => qa_path_html('admin/layout'),
		);

		$navigation['admin/posting'] = array(
			'label' => qa_lang_html('admin/posting_title'),
			'url' => qa_path_html('admin/posting'),
		);

		$navigation['admin/viewing'] = array(
			'label' => qa_lang_html('admin/viewing_title'),
			'url' => qa_path_html('admin/viewing'),
		);

		$navigation['admin/lists'] = array(
			'label' => qa_lang_html('admin/lists_title'),
			'url' => qa_path_html('admin/lists'),
		);

		if (qa_using_categories())
			$navigation['admin/categories'] = array(
				'label' => qa_lang_html('admin/categories_title'),
				'url' => qa_path_html('admin/categories'),
			);

		$navigation['admin/permissions'] = array(
			'label' => qa_lang_html('admin/permissions_title'),
			'url' => qa_path_html('admin/permissions'),
		);

		$navigation['admin/pages'] = array(
			'label' => qa_lang_html('admin/pages_title'),
			'url' => qa_path_html('admin/pages'),
		);

		$navigation['admin/feeds'] = array(
			'label' => qa_lang_html('admin/feeds_title'),
			'url' => qa_path_html('admin/feeds'),
		);

		$navigation['admin/points'] = array(
			'label' => qa_lang_html('admin/points_title'),
			'url' => qa_path_html('admin/points'),
		);

		$navigation['admin/spam'] = array(
			'label' => qa_lang_html('admin/spam_title'),
			'url' => qa_path_html('admin/spam'),
		);

		$navigation['admin/caching'] = array(
			'label' => qa_lang_html('admin/caching_title'),
			'url' => qa_path_html('admin/caching'),
		);

		$navigation['admin/stats'] = array(
			'label' => qa_lang_html('admin/stats_title'),
			'url' => qa_path_html('admin/stats'),
		);

		if (!QA_FINAL_EXTERNAL_USERS)
			$navigation['admin/mailing'] = array(
				'label' => qa_lang_html('admin/mailing_title'),
				'url' => qa_path_html('admin/mailing'),
			);

		$navigation['admin/plugins'] = array(
			'label' => qa_lang_html('admin/plugins_title'),
			'url' => qa_path_html('admin/plugins'),
		);
	}

	if (!qa_user_maximum_permit_error('permit_moderate')) {
		$count = qa_user_permit_error('permit_moderate') ? 0 : (int)qa_opt('cache_queuedcount'); // if only in some categories don't show cached count

		$navigation['admin/moderate'] = array(
			'label' => qa_lang_html_sub('admin/moderate_title', '<span class="qa-nav-sub-counter-moderate">' . qa_html(qa_format_number($count)) . '</span>'),
			'url' => qa_path_html('admin/moderate'),
		);
	}

	if (qa_opt('flagging_of_posts') && !qa_user_maximum_permit_error('permit_hide_show')) {
		$count = qa_user_permit_error('permit_hide_show') ? 0 : (int)qa_opt('cache_flaggedcount'); // if only in some categories don't show cached count

		$navigation['admin/flagged'] = array(
			'label' => qa_lang_html_sub('admin/flagged_title', '<span class="qa-nav-sub-counter-flagged">' . qa_html(qa_format_number($count)) . '</span>'),
			'url' => qa_path_html('admin/flagged'),
		);
	}

	if (!qa_user_maximum_permit_error('permit_hide_show') || !qa_user_maximum_permit_error('permit_delete_hidden')) {
		$count = qa_user_permit_error('permit_hide_show') ? 0 : (int)qa_opt('cache_hiddencount');

		$navigation['admin/hidden'] = array(
			'label' => qa_lang_html_sub('admin/hidden_title', '<span class="qa-nav-sub-counter-hidden">' . qa_html(qa_format_number($count)) . '</span>'),
			'url' => qa_path_html('admin/hidden'),
		);
	}

	if (!QA_FINAL_EXTERNAL_USERS && qa_opt('moderate_users') && $level >= QA_USER_LEVEL_MODERATOR) {
		$count = (int)qa_opt('cache_uapprovecount');

		$navigation['admin/approve'] = array(
			'label' => qa_lang_html_sub('admin/approve_users_title', '<span class="qa-nav-sub-counter-approve">' . qa_html(qa_format_number($count)) . '</span>'),
			'url' => qa_path_html('admin/approve'),
		);
	}

	return $navigation;
}


/**
 * Return the error that needs to displayed on all admin pages, or null if none
 * @return string|null
 */
function qa_admin_page_error()
{
	if (file_exists(QA_INCLUDE_DIR . 'db/install.php')) // file can be removed for extra security
		include_once QA_INCLUDE_DIR . 'db/install.php';

	if (defined('QA_DB_VERSION_CURRENT') && qa_opt('db_version') < QA_DB_VERSION_CURRENT && qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN) {
		return strtr(
			qa_lang_html('admin/upgrade_db'),

			array(
				'^1' => '<a href="' . qa_path_html('install') . '">',
				'^2' => '</a>',
			)
		);

	} elseif (defined('QA_BLOBS_DIRECTORY') && !is_writable(QA_BLOBS_DIRECTORY)) {
		return qa_lang_html_sub('admin/blobs_directory_error', qa_html(QA_BLOBS_DIRECTORY));
	}

	return null;
}


/**
 * Return an HTML fragment to display for a URL test which has passed
 * @return string
 */
function qa_admin_url_test_html()
{
	return '; font-size:9px; color:#060; font-weight:bold; font-family:arial,sans-serif; border-color:#060;">OK<';
}


/**
 * Returns whether a URL path beginning with $requestpart is reserved by the engine or a plugin page module
 * @param string $requestpart
 * @return bool
 */
function qa_admin_is_slug_reserved($requestpart)
{
	$requestpart = trim(strtolower($requestpart));
	$routing = qa_page_routing();

	if (isset($routing[$requestpart]) || isset($routing[$requestpart . '/']) || is_numeric($requestpart))
		return true;

	$pathmap = qa_get_request_map();

	foreach ($pathmap as $mappedrequest) {
		if (trim(strtolower($mappedrequest)) == $requestpart)
			return true;
	}

	switch ($requestpart) {
		case '':
		case 'qa':
		case 'feed':
		case 'install':
		case 'url':
		case 'image':
		case 'ajax':
			return true;
	}

	$pagemodules = qa_load_modules_with('page', 'match_request');
	foreach ($pagemodules as $pagemodule) {
		if ($pagemodule->match_request($requestpart))
			return true;
	}

	return false;
}


/**
 * Returns true if admin (hidden/flagged/approve/moderate) page $action performed on $entityid is permitted by the
 * logged in user and was processed successfully
 * @param int $entityid
 * @param string $action
 * @return bool
 */
function qa_admin_single_click($entityid, $action)
{
	$userid = qa_get_logged_in_userid();

	if (!QA_FINAL_EXTERNAL_USERS && ($action == 'userapprove' || $action == 'userblock')) { // approve/block moderated users
		require_once QA_INCLUDE_DIR . 'db/selects.php';

		$useraccount = qa_db_select_with_pending(qa_db_user_account_selectspec($entityid, true));

		if (isset($useraccount) && qa_get_logged_in_level() >= QA_USER_LEVEL_MODERATOR) {
			switch ($action) {
				case 'userapprove':
					if ($useraccount['level'] <= QA_USER_LEVEL_APPROVED) { // don't demote higher level users
						require_once QA_INCLUDE_DIR . 'app/users-edit.php';
						qa_set_user_level($useraccount['userid'], $useraccount['handle'], QA_USER_LEVEL_APPROVED, $useraccount['level']);
						return true;
					}
					break;

				case 'userblock':
					require_once QA_INCLUDE_DIR . 'app/users-edit.php';
					qa_set_user_blocked($useraccount['userid'], $useraccount['handle'], true);
					return true;
					break;
			}
		}

	} else { // something to do with a post
		require_once QA_INCLUDE_DIR . 'app/posts.php';

		$post = qa_post_get_full($entityid);

		if (isset($post)) {
			$queued = (substr($post['type'], 1) == '_QUEUED');

			switch ($action) {
				case 'approve':
					if ($queued && !qa_user_post_permit_error('permit_moderate', $post)) {
						qa_post_set_status($entityid, QA_POST_STATUS_NORMAL, $userid);
						return true;
					}
					break;

				case 'reject':
					if ($queued && !qa_user_post_permit_error('permit_moderate', $post)) {
						qa_post_set_status($entityid, QA_POST_STATUS_HIDDEN, $userid);
						return true;
					}
					break;

				case 'hide':
					if (!$queued && !qa_user_post_permit_error('permit_hide_show', $post)) {
						qa_post_set_status($entityid, QA_POST_STATUS_HIDDEN, $userid);
						return true;
					}
					break;

				case 'reshow':
					if ($post['hidden'] && !qa_user_post_permit_error('permit_hide_show', $post)) {
						qa_post_set_status($entityid, QA_POST_STATUS_NORMAL, $userid);
						return true;
					}
					break;

				case 'delete':
					if ($post['hidden'] && !qa_user_post_permit_error('permit_delete_hidden', $post)) {
						qa_post_delete($entityid);
						return true;
					}
					break;

				case 'clearflags':
					require_once QA_INCLUDE_DIR . 'app/votes.php';

					if (!qa_user_post_permit_error('permit_hide_show', $post)) {
						qa_flags_clear_all($post, $userid, qa_get_logged_in_handle(), null);
						return true;
					}
					break;
			}
		}
	}

	return false;
}


/**
 * Returns true if admin (hidden/flagged/approve/moderate) page $action performed on $entityid is permitted by the
 * logged in user and was processed successfully
 * @param int $entityid
 * @param string $action
 * @return array
 */
function qa_admin_single_click_array($entityid, $action)
{
	$userid = qa_get_logged_in_userid();

	$response = array();

	if (!QA_FINAL_EXTERNAL_USERS && ($action === 'userapprove' || $action === 'userblock')) { // approve/block moderated users
		require_once QA_INCLUDE_DIR . 'db/selects.php';

		$useraccount = qa_db_select_with_pending(qa_db_user_account_selectspec($entityid, true));

		if (isset($useraccount) && qa_get_logged_in_level() >= QA_USER_LEVEL_MODERATOR) {
			switch ($action) {
				case 'userapprove':
					if ($useraccount['level'] >= QA_USER_LEVEL_APPROVED) { // don't demote higher level users
						$response['result'] = 'error';
						$response['error']['type'] = 'user-already-approved';
						$response['error']['message'] = qa_lang_html('main/general_error');
					} else {
						require_once QA_INCLUDE_DIR . 'app/users-edit.php';
						qa_set_user_level($useraccount['userid'], $useraccount['handle'], QA_USER_LEVEL_APPROVED, $useraccount['level']);

						$response['result'] = 'success';
						$response['domUpdates'] = array(
							array(
								'selector' => '.qa-nav-sub-counter-approve',
								'html' => max((int)qa_opt('cache_uapprovecount') - 1, 0),
							),
							array(
								'selector' => '#p' . $entityid,
								'action' => 'conceal',
							),
						);
					}
					break;

				case 'userblock':
					require_once QA_INCLUDE_DIR . 'app/users-edit.php';
					qa_set_user_blocked($useraccount['userid'], $useraccount['handle'], true);

					$response['result'] = 'success';
					$response['domUpdates'] = array(
						array(
							'selector' => '.qa-nav-sub-counter-approve',
							'html' => max((int)qa_opt('cache_uapprovecount') - 1, 0),
						),
						array(
							'selector' => '#p' . $entityid,
							'action' => 'conceal',
						),
					);
					break;

				default:
			}
		}
	} else { // something to do with a post
		require_once QA_INCLUDE_DIR . 'app/posts.php';

		$post = qa_db_single_select(qa_db_full_post_selectspec(null, $entityid));

		// Handle non-existent posts
		if ($post === null) {
			switch ($action) {
				case 'approve':
				case 'reject':
					$entityCount = (int)qa_opt('cache_queuedcount');
					$selector = '.qa-nav-sub-counter-moderate';
					break;
				case 'reshow':
				case 'delete':
					$entityCount = (int)qa_opt('cache_hiddencount');
					$selector = '.qa-nav-sub-counter-hidden';
					break;
				case 'hide':
				case 'clearflags':
					$entityCount = (int)qa_opt('cache_flaggedcount');
					$selector = '.qa-nav-sub-counter-flagged';
					break;
				default:
					$selector = '';
					$entityCount = 0;
			}

			return array(
				'result' => 'error',
				'error' => array(
					'type' => 'post-not-found',
					'message' => qa_lang_html('main/general_error'),
				),
				'domUpdates' => array(
					array(
						'selector' => $selector,
						'html' => $entityCount,
					),
					array(
						'selector' => '#p' . $entityid,
						'action' => 'conceal',
					),
				),
			);
		}

		$queued = (substr($post['type'], 1) == '_QUEUED');

		switch ($action) {
			case 'approve':
			case 'reject':
				$entityCount = (int)qa_opt('cache_queuedcount');
				$hiddenCount = (int)qa_opt('cache_hiddencount');
				if (!$queued) {
					$response['result'] = 'error';
					$response['error']['type'] = 'post-not-queued';
					$response['error']['message'] = qa_lang_html('main/general_error');
					$response['domUpdates'] = array(
						array(
							'selector' => '.qa-nav-sub-counter-moderate',
							'html' => $entityCount,
						),
						array(
							'selector' => '#p' . $entityid,
							'action' => 'conceal',
						),
					);
				} elseif (qa_user_post_permit_error('permit_moderate', $post) !== false) {
					$response['result'] = 'error';
					$response['error']['type'] = 'no-permission';
					$response['error']['message'] = qa_lang_html('users/no_permission');
					$response['error']['severity'] = 'fatal';
				} else {
					if ($action === 'approve') {
						qa_post_set_status($entityid, QA_POST_STATUS_NORMAL, $userid);
					} else { // 'reject'
						qa_post_set_status($entityid, QA_POST_STATUS_HIDDEN, $userid);
						$hiddenCount++;
					}

					$response['result'] = 'success';
					$response['domUpdates'] = array(
						array(
							'selector' => '.qa-nav-sub-counter-moderate',
							'html' => max($entityCount - 1, 0),
						),
						array(
							'selector' => '.qa-nav-sub-counter-hidden',
							'html' => $hiddenCount,
						),
						array(
							'selector' => '#p' . $entityid,
							'action' => 'conceal',
						),
					);
				}
				break;

			case 'reshow':
			case 'delete':
				$entityCount = (int)qa_opt('cache_hiddencount');
				if (!$post['hidden']) {
					$response['result'] = 'error';
					$response['error']['type'] = 'post-not-hidden';
					$response['error']['message'] = qa_lang_html('main/general_error');
					$response['domUpdates'] = array(
						array(
							'selector' => '.qa-nav-sub-counter-hidden',
							'html' => $entityCount,
						),
						array(
							'selector' => '#p' . $entityid,
							'action' => 'conceal',
						),
					);
				} elseif (qa_user_post_permit_error('permit_hide_show', $post) !== false) {
					$response['result'] = 'error';
					$response['error']['type'] = 'no-permission';
					$response['error']['message'] = qa_lang_html('users/no_permission');
					$response['error']['severity'] = 'fatal';
				} else {
					if ($action === 'reshow') {
						qa_post_set_status($entityid, QA_POST_STATUS_NORMAL, $userid);
					} else { // 'delete'
						qa_post_delete($entityid);
					}

					$response['result'] = 'success';
					$response['domUpdates'] = array(
						array(
							'selector' => '.qa-nav-sub-counter-hidden',
							'html' => max($entityCount - 1, 0),
						),
						array(
							'selector' => '#p' . $entityid,
							'action' => 'conceal',
						),
					);
				}
				break;

			case 'hide':
			case 'clearflags':
				$entityCount = (int)qa_opt('cache_flaggedcount');
				$hiddenCount = (int)qa_opt('cache_hiddencount');
				if ($action === 'hide' && $queued) {
					$response['result'] = 'error';
					$response['error']['type'] = 'post-queued';
					$response['error']['message'] = qa_lang_html('main/general_error');
					$response['domUpdates'] = array(
						array(
							'selector' => '.qa-nav-sub-counter-flagged',
							'html' => $entityCount,
						),
						array(
							'selector' => '#p' . $entityid,
							'action' => 'conceal',
						),
					);
				} elseif (qa_user_post_permit_error('permit_hide_show', $post) !== false) {
					$response['result'] = 'error';
					$response['error']['type'] = 'no-permission';
					$response['error']['message'] = qa_lang_html('users/no_permission');
					$response['error']['severity'] = 'fatal';
				} else {
					if ($action === 'hide') {
						qa_post_set_status($entityid, QA_POST_STATUS_HIDDEN, $userid);
						$hiddenCount++;
					} else { // 'clearflags'
						require_once QA_INCLUDE_DIR . 'app/votes.php';
						qa_flags_clear_all($post, $userid, qa_get_logged_in_handle(), null);
					}

					$response['result'] = 'success';
					$response['domUpdates'] = array(
						array(
							'selector' => '.qa-nav-sub-counter-flagged',
							'html' => max($entityCount - 1, 0),
						),
						array(
							'selector' => '.qa-nav-sub-counter-hidden',
							'html' => $hiddenCount,
						),
						array(
							'selector' => '#p' . $entityid,
							'action' => 'conceal',
						),
					);
				}
				break;

			default:
		}
	}

	return $response;
}


/**
 * Checks for a POSTed click on an admin (hidden/flagged/approve/moderate) page, and refresh the page if processed successfully (non Ajax)
 * @return string|null
 */
function qa_admin_check_clicks()
{
	if (!qa_is_http_post()) {
		return null;
	}

	foreach ($_POST as $field => $value) {
		if (strpos($field, 'admin_') !== 0) {
			continue;
		}

		@list($dummy, $entityid, $action) = explode('_', $field);

		if (strlen($entityid) && strlen($action)) {
			if (!qa_check_form_security_code('admin/click', qa_post_text('code')))
				return qa_lang_html('misc/form_security_again');
			elseif (qa_admin_single_click($entityid, $action))
				qa_redirect(qa_request());
		}
	}

	return null;
}


/**
 * Retrieve metadata information from the $contents of a qa-theme.php or qa-plugin.php file, mapping via $fields.
 *
 * @deprecated Deprecated from 1.7; use `qa_addon_metadata($contents, $type)` instead.
 * @param string $contents
 * @param array $fields
 * @return array
 */
function qa_admin_addon_metadata($contents, $fields)
{
	$metadata = array();

	foreach ($fields as $key => $field) {
		if (preg_match('/' . str_replace(' ', '[ \t]*', preg_quote($field, '/')) . ':[ \t]*([^\n\f]*)[\n\f]/i', $contents, $matches))
			$metadata[$key] = trim($matches[1]);
	}

	return $metadata;
}


/**
 * Return the hash code for the plugin in $directory (without trailing slash), used for in-page navigation on admin/plugins page
 * @param string $directory
 * @return mixed
 */
function qa_admin_plugin_directory_hash($directory)
{
	$pluginManager = new \Q2A\Plugin\PluginManager();
	$hashes = $pluginManager->getHashesForPlugins(array($directory));

	return reset($hashes);
}


/**
 * Return the URL (relative to the current page) to navigate to the options panel for the plugin in $directory (without trailing slash)
 * @param string $directory
 * @return mixed|string
 */
function qa_admin_plugin_options_path($directory)
{
	$hash = qa_admin_plugin_directory_hash($directory);
	return qa_path_html('admin/plugins', array('show' => $hash), null, null, $hash);
}


/**
 * Return the URL (relative to the current page) to navigate to the options panel for plugin module $name of $type
 * @param string $type
 * @param string $name
 * @return mixed|string
 */
function qa_admin_module_options_path($type, $name)
{
	$info = qa_get_module_info($type, $name);
	$dir = basename($info['directory']);

	return qa_admin_plugin_options_path($dir);
}
