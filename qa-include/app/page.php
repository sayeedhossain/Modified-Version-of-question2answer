<?php
/*
	Question2Answer by Gideon Greenspan and contributors
	http://www.question2answer.org/

	Description: Routing and utility functions for page requests


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

require_once QA_INCLUDE_DIR . 'app/routing.php';
require_once QA_INCLUDE_DIR . 'app/cookies.php';
require_once QA_INCLUDE_DIR . 'app/format.php';
require_once QA_INCLUDE_DIR . 'app/users.php';
require_once QA_INCLUDE_DIR . 'app/options.php';
require_once QA_INCLUDE_DIR . 'db/selects.php';


/**
 * Queue any pending requests which are required independent of which page will be shown
 */
function qa_page_queue_pending()
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	qa_preload_options();
	$loginuserid = qa_get_logged_in_userid();
	$dbSelect = qa_service('dbselect');

	if (isset($loginuserid)) {
		if (!QA_FINAL_EXTERNAL_USERS)
			$dbSelect->queuePending('loggedinuser', qa_db_user_account_selectspec($loginuserid, true));

		$dbSelect->queuePending('notices', qa_db_user_notices_selectspec($loginuserid));
		$dbSelect->queuePending('favoritenonqs', qa_db_user_favorite_non_qs_selectspec($loginuserid));
		$dbSelect->queuePending('userlimits', qa_db_user_limits_selectspec($loginuserid));
		$dbSelect->queuePending('userlevels', qa_db_user_levels_selectspec($loginuserid, true));
	}

	$dbSelect->queuePending('iplimits', qa_db_ip_limits_selectspec(qa_remote_ip_address()));
	$dbSelect->queuePending('navpages', qa_db_pages_selectspec(array('B', 'M', 'O', 'F')));
	$dbSelect->queuePending('widgets', qa_db_widgets_selectspec());
}


/**
 * Check the page state parameter and then remove it from the $_GET array
 */
function qa_load_state()
{
	global $qa_state;

	$qa_state = qa_get('state');
	unset($_GET['state']); // to prevent being passed through on forms
}


/**
 * If no user is logged in, call through to the login modules to see if they want to log someone in
 */
function qa_check_login_modules()
{
	if (!QA_FINAL_EXTERNAL_USERS && !qa_is_logged_in()) {
		$loginmodules = qa_load_modules_with('login', 'check_login');

		foreach ($loginmodules as $loginmodule) {
			$loginmodule->check_login();
			if (qa_is_logged_in()) // stop and reload page if it worked
				qa_redirect(qa_request(), $_GET);
		}
	}
}


/**
 * React to any of the common buttons on a page for voting, favorites and closing a notice
 * If the user has Javascript on, these should come through Ajax rather than here.
 */
function qa_check_page_clicks()
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	global $qa_page_error_html;

	if (qa_is_http_post()) {
		foreach ($_POST as $field => $value) {
			if (strpos($field, 'vote_') === 0) { // voting...
				@list($dummy, $postid, $vote, $anchor) = explode('_', $field);

				if (isset($postid) && isset($vote)) {
					if (!qa_check_form_security_code('vote', qa_post_text('code')))
						$qa_page_error_html = qa_lang_html('misc/form_security_again');

					else {
						require_once QA_INCLUDE_DIR . 'app/votes.php';
						require_once QA_INCLUDE_DIR . 'db/selects.php';

						$userid = qa_get_logged_in_userid();

						$post = qa_db_select_with_pending(qa_db_full_post_selectspec($userid, $postid));
						$qa_page_error_html = qa_vote_error_html($post, $vote, $userid, qa_request());

						if (!$qa_page_error_html) {
							qa_vote_set($post, $userid, qa_get_logged_in_handle(), qa_cookie_get(), $vote);
							qa_redirect(qa_request(), $_GET, null, null, $anchor);
						}
						break;
					}
				}

			} elseif (strpos($field, 'favorite_') === 0) { // favorites...
				@list($dummy, $entitytype, $entityid, $favorite) = explode('_', $field);

				if (isset($entitytype) && isset($entityid) && isset($favorite)) {
					if (!qa_check_form_security_code('favorite-' . $entitytype . '-' . $entityid, qa_post_text('code')))
						$qa_page_error_html = qa_lang_html('misc/form_security_again');

					else {
						require_once QA_INCLUDE_DIR . 'app/favorites.php';

						qa_user_favorite_set(qa_get_logged_in_userid(), qa_get_logged_in_handle(), qa_cookie_get(), $entitytype, $entityid, $favorite);
						qa_redirect(qa_request(), $_GET);
					}
				}

			} elseif (strpos($field, 'notice_') === 0) { // notices...
				@list($dummy, $noticeid) = explode('_', $field);

				if (isset($noticeid)) {
					if (!qa_check_form_security_code('notice-' . $noticeid, qa_post_text('code')))
						$qa_page_error_html = qa_lang_html('misc/form_security_again');

					else {
						if ($noticeid == 'visitor')
							setcookie('qa_noticed', 1, time() + 86400 * 3650, '/', QA_COOKIE_DOMAIN, (bool)ini_get('session.cookie_secure'), true);

						elseif ($noticeid == 'welcome') {
							require_once QA_INCLUDE_DIR . 'db/users.php';
							qa_db_user_set_flag(qa_get_logged_in_userid(), QA_USER_FLAGS_WELCOME_NOTICE, false);

						} else {
							require_once QA_INCLUDE_DIR . 'db/notices.php';
							qa_db_usernotice_delete(qa_get_logged_in_userid(), $noticeid);
						}

						qa_redirect(qa_request(), $_GET);
					}
				}
			}
		}
	}
}


/**
 *	Run the appropriate /qa-include/pages/*.php file for this request and return back the $qa_content it passed
 */
function qa_get_request_content()
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	$requestlower = strtolower(qa_request());
	$requestparts = qa_request_parts();
	$firstlower = strtolower($requestparts[0]);
	$qa_content = [];
	// old router
	$routing = qa_page_routing();
	// new router
	$router = qa_service('router');
	qa_controller_routing($router);

	try {
		// use new Controller system
		$route = $router->match($requestlower);
		if ($route !== null) {
			qa_set_template($route->getOption('template'));
			$controllerClass = $route->getController();
			$ctrl = new $controllerClass(qa_service('database'));

			$qa_content = $ctrl->executeAction($route->getAction(), $route->getParameters());
		}
	} catch (\Exception $e) {
		$qa_content = (new \Q2A\Exceptions\ExceptionHandler)->handle($e);
	}

	if (empty($qa_content)) {
		if (isset($routing[$requestlower])) {
			qa_set_template($firstlower);
			$qa_content = require QA_INCLUDE_DIR . $routing[$requestlower];

		} elseif (isset($routing[$firstlower . '/'])) {
			qa_set_template($firstlower);
			$qa_content = require QA_INCLUDE_DIR . $routing[$firstlower . '/'];

		} elseif (is_numeric($requestparts[0])) {
			qa_set_template('question');
			$qa_content = require QA_INCLUDE_DIR . 'pages/question.php';

		} else {
			qa_set_template(strlen($firstlower) ? $firstlower : 'qa'); // will be changed later
			$qa_content = require QA_INCLUDE_DIR . 'pages/default.php'; // handles many other pages, including custom pages and page modules
		}
	}

	if ($firstlower == 'admin') {
		$_COOKIE['qa_admin_last'] = $requestlower; // for navigation tab now...
		setcookie('qa_admin_last', $_COOKIE['qa_admin_last'], 0, '/', QA_COOKIE_DOMAIN, (bool)ini_get('session.cookie_secure'), true); // ...and in future
	}

	if (isset($qa_content))
		qa_set_form_security_key();

	return $qa_content;
}


/**
 * Output the $qa_content via the theme class after doing some pre-processing, mainly relating to Javascript
 * @param array $qa_content
 * @return mixed
 */
function qa_output_content($qa_content)
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	global $qa_template;

	$requestlower = strtolower(qa_request());

	// Set appropriate selected flags for navigation (not done in qa_content_prepare() since it also applies to sub-navigation)

	foreach ($qa_content['navigation'] as $navtype => $navigation) {
		if (!is_array($navigation) || $navtype == 'cat') {
			continue;
		}

		foreach ($navigation as $navprefix => $navlink) {
			$selected =& $qa_content['navigation'][$navtype][$navprefix]['selected'];
			if (isset($navlink['selected_on'])) {
				// match specified paths
				foreach ($navlink['selected_on'] as $path) {
					if (strpos($requestlower . '$', $path) === 0)
						$selected = true;
				}
			} elseif ($requestlower === $navprefix || $requestlower . '$' === $navprefix) {
				// exact match for array key
				$selected = true;
			}
		}
	}

	// Slide down notifications

	if (!empty($qa_content['notices'])) {
		foreach ($qa_content['notices'] as $notice) {
			$qa_content['script_onloads'][] = array(
				"qa_reveal(document.getElementById(" . qa_js($notice['id']) . "), 'notice');",
			);
		}
	}

	// Handle maintenance mode

	if (qa_opt('site_maintenance') && ($requestlower != 'login')) {
		if (qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN) {
			if (!isset($qa_content['error'])) {
				$qa_content['error'] = strtr(qa_lang_html('admin/maintenance_admin_only'), array(
					'^1' => '<a href="' . qa_path_html('admin/general') . '">',
					'^2' => '</a>',
				));
			}
		} else {
			$qa_content = qa_content_prepare();
			$qa_content['error'] = qa_lang_html('misc/site_in_maintenance');
		}
	}

	// Handle new users who must confirm their email now, or must be approved before continuing

	$userid = qa_get_logged_in_userid();
	if (isset($userid) && $requestlower != 'confirm' && $requestlower != 'account') {
		$flags = qa_get_logged_in_flags();

		if (($flags & QA_USER_FLAGS_MUST_CONFIRM) && !($flags & QA_USER_FLAGS_EMAIL_CONFIRMED) && qa_opt('confirm_user_emails')) {
			$qa_content = qa_content_prepare();
			$qa_content['title'] = qa_lang_html('users/confirm_title');
			$qa_content['error'] = strtr(qa_lang_html('users/confirm_required'), array(
				'^1' => '<a href="' . qa_path_html('confirm') . '">',
				'^2' => '</a>',
			));
		}

		// we no longer block access here for unapproved users; this is handled by the Permissions settings
	}

	// Combine various Javascript elements in $qa_content into single array for theme layer

	$script = array('<script>');

	if (isset($qa_content['script_var'])) {
		foreach ($qa_content['script_var'] as $var => $value) {
			$script[] = 'var ' . $var . ' = ' . qa_js($value) . ';';
		}
	}

	if (isset($qa_content['script_lines'])) {
		foreach ($qa_content['script_lines'] as $scriptlines) {
			$script[] = '';
			$script = array_merge($script, $scriptlines);
		}
	}

	$script[] = '</script>';

	if (isset($qa_content['script_rel'])) {
		$uniquerel = array_unique($qa_content['script_rel']); // remove any duplicates
		foreach ($uniquerel as $script_rel) {
			$script[] = '<script src="' . qa_html(qa_path_to_root() . $script_rel) . '"></script>';
		}
	}

	if (isset($qa_content['script_src'])) {
		$uniquesrc = array_unique($qa_content['script_src']); // remove any duplicates
		foreach ($uniquesrc as $script_src) {
			$script[] = '<script src="' . qa_html($script_src) . '"></script>';
		}
	}

	// JS onloads must come after jQuery is loaded

	if (isset($qa_content['focusid'])) {
		$qa_content['script_onloads'][] = array(
			'$(' . qa_js('#' . $qa_content['focusid']) . ').focus();',
		);
	}

	if (isset($qa_content['script_onloads'])) {
		$script[] = '<script>';
		$script[] = '$(window).on(\'load\', function() {';

		foreach ($qa_content['script_onloads'] as $scriptonload) {
			foreach ((array)$scriptonload as $scriptline) {
				$script[] = "\t" . $scriptline;
			}
		}

		$script[] = '});';
		$script[] = '</script>';
	}

	if (!isset($qa_content['script'])) {
		$qa_content['script'] = array();
	}

	$qa_content['script'] = array_merge($script, $qa_content['script']);

	// Load the appropriate theme class and output the page

	$tmpl = substr($qa_template, 0, 7) == 'custom-' ? 'custom' : $qa_template;
	$themeclass = qa_load_theme_class(qa_get_site_theme(), $tmpl, $qa_content, qa_request());
	$themeclass->initialize();

	header('Content-type: ' . $qa_content['content_type']);

	$themeclass->doctype();
	$themeclass->html();
	$themeclass->finish();
}


/**
 * Update any statistics required by the fields in $qa_content, and return true if something was done
 * @param array $qa_content
 * @return bool
 */
function qa_do_content_stats($qa_content)
{
	if (!isset($qa_content['inc_views_postid'])) {
		return false;
	}

	require_once QA_INCLUDE_DIR . 'db/hotness.php';

	$viewsIncremented = qa_db_increment_views($qa_content['inc_views_postid']);

	if ($viewsIncremented && qa_opt('recalc_hotness_q_view')) {
		qa_db_hotness_update($qa_content['inc_views_postid']);
	}

	return true;
}


// Other functions which might be called from anywhere

/**
 * Sets the template which should be passed to the theme class, telling it which type of page it's displaying
 * @param string $template
 */
function qa_set_template($template)
{
	global $qa_template;
	$qa_template = $template;
}


/**
 * Start preparing theme content in global $qa_content variable, with or without $voting support,
 * in the context of the categories in $categoryids (if not null)
 * @param bool $voting
 * @param array $categoryids
 * @return array
 */
function qa_content_prepare($voting = false, $categoryids = array())
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	global $qa_template, $qa_page_error_html;

	if (QA_DEBUG_PERFORMANCE) {
		global $qa_usage;
		$qa_usage->mark('control');
	}

	$request = qa_request();
	$requestlower = qa_request();
	$dbSelect = qa_service('dbselect');
	$navpages = $dbSelect->getPendingResult('navpages');
	$widgets = $dbSelect->getPendingResult('widgets');

	if (!is_array($categoryids)) {
		// accept old-style parameter
		$categoryids = array($categoryids);
	}

	$lastcategoryid = count($categoryids) > 0 ? end($categoryids) : null;
	$charset = 'utf-8';
	$language = qa_opt('site_language');
	$language = empty($language) ? 'en' : qa_html($language);

	$qa_content = array(
		'content_type' => 'text/html; charset=' . $charset,
		'charset' => $charset,

		'language' => $language,

		'direction' => qa_opt('site_text_direction'),

		'options' => array(
			'minify_html' => qa_opt('minify_html'),
		),

		'site_title' => qa_html(qa_opt('site_title')),

		'html_tags' => 'lang="' . $language . '"',

		'head_lines' => array(),

		'navigation' => array(
			'user' => array(),

			'main' => array(),

			'footer' => array(
				'feedback' => array(
					'url' => qa_path_html('feedback'),
					'label' => qa_lang_html('main/nav_feedback'),
				),
			),

		),

		'sidebar' => qa_opt('show_custom_sidebar') ? qa_opt('custom_sidebar') : null,
		'sidepanel' => qa_opt('show_custom_sidepanel') ? qa_opt('custom_sidepanel') : null,
		'widgets' => array(),
	);

	// add meta description if we're on the home page
	if ($request === '' || $request === array_search('', qa_get_request_map())) {
		$qa_content['description'] = qa_html(qa_opt('home_description'));
	}

	if (qa_opt('show_custom_in_head'))
		$qa_content['head_lines'][] = qa_opt('custom_in_head');

	if (qa_opt('show_custom_header'))
		$qa_content['body_header'] = qa_opt('custom_header');

	if (qa_opt('show_custom_footer'))
		$qa_content['body_footer'] = qa_opt('custom_footer');

	if (isset($categoryids))
		$qa_content['categoryids'] = $categoryids;

	foreach ($navpages as $page) {
		if ($page['nav'] == 'B')
			qa_navigation_add_page($qa_content['navigation']['main'], $page);
	}

	if (qa_opt('nav_home') && qa_opt('show_custom_home')) {
		$qa_content['navigation']['main']['$'] = array(
			'url' => qa_path_html(''),
			'label' => qa_lang_html('main/nav_home'),
		);
	}

	if (qa_opt('nav_activity')) {
		$qa_content['navigation']['main']['activity'] = array(
			'url' => qa_path_html('activity'),
			'label' => qa_lang_html('main/nav_activity'),
		);
	}

	$hascustomhome = qa_has_custom_home();

	if (qa_opt($hascustomhome ? 'nav_qa_not_home' : 'nav_qa_is_home')) {
		$qa_content['navigation']['main'][$hascustomhome ? 'qa' : '$'] = array(
			'url' => qa_path_html($hascustomhome ? 'qa' : ''),
			'label' => qa_lang_html('main/nav_qa'),
		);
	}

	if (qa_opt('nav_questions')) {
		$qa_content['navigation']['main']['questions'] = array(
			'url' => qa_path_html('questions'),
			'label' => qa_lang_html('main/nav_qs'),
		);
	}

	if (qa_opt('nav_hot')) {
		$qa_content['navigation']['main']['hot'] = array(
			'url' => qa_path_html('hot'),
			'label' => qa_lang_html('main/nav_hot'),
		);
	}

	if (qa_opt('nav_unanswered')) {
		$qa_content['navigation']['main']['unanswered'] = array(
			'url' => qa_path_html('unanswered'),
			'label' => qa_lang_html('main/nav_unanswered'),
		);
	}

	if (qa_using_tags() && qa_opt('nav_tags')) {
		$qa_content['navigation']['main']['tag'] = array(
			'url' => qa_path_html('tags'),
			'label' => qa_lang_html('main/nav_tags'),
			'selected_on' => array('tags$', 'tag/'),
		);
	}

	if (qa_using_categories() && qa_opt('nav_categories')) {
		$qa_content['navigation']['main']['categories'] = array(
			'url' => qa_path_html('categories'),
			'label' => qa_lang_html('main/nav_categories'),
			'selected_on' => array('categories$', 'categories/'),
		);
	}

	if (qa_opt('nav_users')) {
		$qa_content['navigation']['main']['user'] = array(
			'url' => qa_path_html('users'),
			'label' => qa_lang_html('main/nav_users'),
			'selected_on' => array('users$', 'users/', 'user/'),
		);
	}

	// Only the 'level' permission error prevents the menu option being shown - others reported on /qa-include/pages/ask.php

	if (qa_opt('nav_ask') && qa_user_maximum_permit_error('permit_post_q') != 'level') {
		$qa_content['navigation']['main']['ask'] = array(
			'url' => qa_path_html('ask', (qa_using_categories() && strlen($lastcategoryid)) ? array('cat' => $lastcategoryid) : null),
			'label' => qa_lang_html('main/nav_ask'),
		);
	}


	if (qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN || !qa_user_maximum_permit_error('permit_moderate') ||
		!qa_user_maximum_permit_error('permit_hide_show') || !qa_user_maximum_permit_error('permit_delete_hidden')
	) {
		$qa_content['navigation']['main']['admin'] = array(
			'url' => qa_path_html('admin'),
			'label' => qa_lang_html('main/nav_admin'),
			'selected_on' => array('admin/'),
		);
	}

	$qa_content['search'] = array(
		'form_tags' => 'method="get" action="' . qa_path_html('search') . '"',
		'form_extra' => qa_path_form_html('search'),
		'title' => qa_lang_html('main/search_title'),
		'field_tags' => 'name="q"',
		'button_label' => qa_lang_html('main/search_button'),
	);

	if (!qa_opt('feedback_enabled'))
		unset($qa_content['navigation']['footer']['feedback']);

	foreach ($navpages as $page) {
		if ($page['nav'] == 'M' || $page['nav'] == 'O' || $page['nav'] == 'F') {
			$loc = ($page['nav'] == 'F') ? 'footer' : 'main';
			qa_navigation_add_page($qa_content['navigation'][$loc], $page);
		}
	}

	$regioncodes = array(
		'F' => 'full',
		'M' => 'main',
		'S' => 'side',
	);

	$placecodes = array(
		'T' => 'top',
		'H' => 'high',
		'L' => 'low',
		'B' => 'bottom',
	);

	foreach ($widgets as $widget) {
		$tagstring = ',' . $widget['tags'] . ',';
		$showOnTmpl = strpos($tagstring, ",$qa_template,") !== false || strpos($tagstring, ',all,') !== false;
		// special case for user pages
		$showOnUser = strpos($tagstring, ',user,') !== false && preg_match('/^user(-.+)?$/', $qa_template) === 1;

		if ($showOnTmpl || $showOnUser) {
			// widget has been selected for display on this template
			$region = @$regioncodes[substr($widget['place'], 0, 1)];
			$place = @$placecodes[substr($widget['place'], 1, 2)];

			if (isset($region) && isset($place)) {
				// region/place codes recognized
				$module = qa_load_module('widget', $widget['title']);
				$allowTmpl = (substr($qa_template, 0, 7) == 'custom-') ? 'custom' : $qa_template;

				if (isset($module) &&
					method_exists($module, 'allow_template') && $module->allow_template($allowTmpl) &&
					method_exists($module, 'allow_region') && $module->allow_region($region) &&
					method_exists($module, 'output_widget')
				) {
					// if module loaded and happy to be displayed here, tell theme about it
					$qa_content['widgets'][$region][$place][] = $module;
				}
			}
		}
	}

	$logoshow = qa_opt('logo_show');
	$logourl = qa_opt('logo_url');
	$logowidth = qa_opt('logo_width');
	$logoheight = qa_opt('logo_height');

	if ($logoshow) {
		$qa_content['logo'] = '<a href="' . qa_path_html('') . '" class="qa-logo-link" title="' . qa_html(qa_opt('site_title')) . '">' .
			'<img src="' . qa_html(is_numeric(strpos($logourl, '://')) ? $logourl : qa_path_to_root() . $logourl) . '"' .
			($logowidth ? (' width="' . $logowidth . '"') : '') . ($logoheight ? (' height="' . $logoheight . '"') : '') .
			' alt="' . qa_html(qa_opt('site_title')) . '"/></a>';
	} else {
		$qa_content['logo'] = '<a href="' . qa_path_html('') . '" class="qa-logo-link">' . qa_html(qa_opt('site_title')) . '</a>';
	}

	$topath = qa_get('to'); // lets user switch between login and register without losing destination page

	$userlinks = qa_get_login_links(qa_path_to_root(), isset($topath) ? $topath : qa_path($request, $_GET, ''));

	$qa_content['navigation']['user'] = array();

	if (qa_is_logged_in()) {
		$qa_content['loggedin'] = qa_lang_html_sub_split('main/logged_in_x', QA_FINAL_EXTERNAL_USERS
			? qa_get_logged_in_user_html(qa_get_logged_in_user_cache(), qa_path_to_root(), false)
			: qa_get_one_user_html(qa_get_logged_in_handle(), false)
		);

		$qa_content['navigation']['user']['updates'] = array(
			'url' => qa_path_html('updates'),
			'label' => qa_lang_html('main/nav_updates'),
		);

		if (!empty($userlinks['logout'])) {
			$qa_content['navigation']['user']['logout'] = array(
				'url' => qa_html(@$userlinks['logout']),
				'label' => qa_lang_html('main/nav_logout'),
			);
		}

		if (!QA_FINAL_EXTERNAL_USERS) {
			$source = qa_get_logged_in_source();

			if (strlen($source)) {
				$loginmodules = qa_load_modules_with('login', 'match_source');

				foreach ($loginmodules as $module) {
					if ($module->match_source($source) && method_exists($module, 'logout_html')) {
						ob_start();
						$module->logout_html(qa_path('logout', array(), qa_opt('site_url')));
						$qa_content['navigation']['user']['logout'] = array('label' => ob_get_clean());
					}
				}
			}
		}

		$notices = $dbSelect->getPendingResult('notices');
		foreach ($notices as $notice)
			$qa_content['notices'][] = qa_notice_form($notice['noticeid'], qa_viewer_html($notice['content'], $notice['format']), $notice);

	} else {
		require_once QA_INCLUDE_DIR . 'util/string.php';

		if (!QA_FINAL_EXTERNAL_USERS) {
			$loginmodules = qa_load_modules_with('login', 'login_html');

			foreach ($loginmodules as $tryname => $module) {
				ob_start();
				$module->login_html(isset($topath) ? (qa_opt('site_url') . $topath) : qa_path($request, $_GET, qa_opt('site_url')), 'menu');
				$label = ob_get_clean();

				if (strlen($label))
					$qa_content['navigation']['user'][implode('-', qa_string_to_words($tryname))] = array('label' => $label);
			}
		}

		if (!empty($userlinks['login'])) {
			$qa_content['navigation']['user']['login'] = array(
				'url' => qa_html(@$userlinks['login']),
				'label' => qa_lang_html('main/nav_login'),
			);
		}

		if (!empty($userlinks['register'])) {
			$qa_content['navigation']['user']['register'] = array(
				'url' => qa_html(@$userlinks['register']),
				'label' => qa_lang_html('main/nav_register'),
			);
		}
	}

	if (QA_FINAL_EXTERNAL_USERS || !qa_is_logged_in()) {
		if (qa_opt('show_notice_visitor') && (!isset($topath)) && (!isset($_COOKIE['qa_noticed'])))
			$qa_content['notices'][] = qa_notice_form('visitor', qa_opt('notice_visitor'));

	} else {
		setcookie('qa_noticed', 1, time() + 86400 * 3650, '/', QA_COOKIE_DOMAIN, (bool)ini_get('session.cookie_secure'), true); // don't show first-time notice if a user has logged in

		if (qa_opt('show_notice_welcome') && (qa_get_logged_in_flags() & QA_USER_FLAGS_WELCOME_NOTICE)) {
			if ($requestlower != 'confirm' && $requestlower != 'account') // let people finish registering in peace
				$qa_content['notices'][] = qa_notice_form('welcome', qa_opt('notice_welcome'));
		}
	}

	$qa_content['script_rel'] = array('qa-content/jquery-3.5.1.min.js');
	$qa_content['script_rel'][] = 'qa-content/qa-global.js?' . QA_VERSION;

	if ($voting)
		$qa_content['error'] = @$qa_page_error_html;

	$qa_content['script_var'] = array(
		'qa_root' => qa_path_to_root(),
		'qa_request' => $request,
	);

	return $qa_content;
}


/**
 * Get the start parameter which should be used, as constrained by the setting in qa-config.php
 * @return int
 */
function qa_get_start()
{
	return min(max(0, (int)qa_get('start')), QA_MAX_LIMIT_START);
}


/**
 * Get the state parameter which should be used, as set earlier in qa_load_state()
 * @return string
 */
function qa_get_state()
{
	global $qa_state;
	return $qa_state;
}


/**
 * Generate a canonical URL for the current request. Preserves certain GET parameters.
 * @return string The full canonical URL.
 */
function qa_get_canonical()
{
	$params = array();

	// variable assignment intentional here
	if (($start = qa_get_start()) > 0) {
		$params['start'] = $start;
	}
	if ($sort = qa_get('sort')) {
		$params['sort'] = $sort;
	}
	if ($by = qa_get('by')) {
		$params['by'] = $by;
	}

	return qa_path_html(qa_request(), $params, qa_opt('site_url'));
}
