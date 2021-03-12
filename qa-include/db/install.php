<?php
/*
	Question2Answer by Gideon Greenspan and contributors
	http://www.question2answer.org/

	Description: Database-level functions for installation and upgrading


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

define('QA_DB_VERSION_CURRENT', 68);


/**
 * Return the column type for user ids after verifying it is one of the legal options
 * @return string
 */
function qa_db_user_column_type_verify()
{
	$coltype = strtoupper(qa_get_mysql_user_column_type());

	switch ($coltype) {
		case 'SMALLINT':
		case 'MEDIUMINT':
		case 'INT':
		case 'BIGINT':
		case 'SMALLINT UNSIGNED':
		case 'MEDIUMINT UNSIGNED':
		case 'INT UNSIGNED':
		case 'BIGINT UNSIGNED':
			// these are all OK
			break;

		default:
			if (!preg_match('/VARCHAR\([0-9]+\)/', $coltype))
				qa_fatal_error('Specified user column type is not one of allowed values - please read documentation');
			break;
	}

	return $coltype;
}


/**
 * Return an array of table definitions. For each element of the array, the key is the table name (without prefix)
 * and the value is an array of column definitions, [column name] => [definition]. The column name is omitted for indexes.
 * @return array
 */
function qa_db_table_definitions()
{
	if (qa_to_override(__FUNCTION__)) { $args=func_get_args(); return qa_call_override(__FUNCTION__, $args); }

	require_once QA_INCLUDE_DIR . 'db/maxima.php';
	require_once QA_INCLUDE_DIR . 'app/users.php';

	/*
		Important note on character encoding in database and PHP connection to MySQL

		[this note is no longer relevant since we *do* explicitly set the connection character set since Q2A 1.5 - see qa-db.php
	*/

	/*
		Other notes on the definitions below

		* In MySQL versions prior to 5.0.3, VARCHAR(x) columns will be silently converted to TEXT where x>255

		* See box at top of /qa-include/Q2A/Recalc/RecalcMain.php for a list of redundant (non-normal) information in the database

		* Starting in version 1.2, we explicitly name keys and foreign key constraints, instead of allowing MySQL
		  to name these by default. Our chosen names match the default names that MySQL would have assigned, and
		  indeed *did* assign for people who installed an earlier version of Q2A. By naming them explicitly, we're
		  on more solid ground for possible future changes to indexes and foreign keys in the schema.

		* There are other foreign key constraints that it would be valid to add, but that would not serve much
		  purpose in terms of preventing inconsistent data being retrieved, and would just slow down some queries.

		* We name some columns here in a not entirely intuitive way. The reason is to match the names of columns in
		  other tables which are of a similar nature. This will save time and space when combining several SELECT
		  queries together via a UNION in qa_db_multi_select() - see comments in qa-db.php for more information.
	*/

	$useridcoltype = qa_db_user_column_type_verify();

	$tables = array(
		'users' => array(
			'userid' => $useridcoltype . ' NOT NULL AUTO_INCREMENT',
			'created' => 'DATETIME NOT NULL',
			'createip' => 'VARBINARY(16) NOT NULL', // INET6_ATON of IP address when created
			'email' => 'VARCHAR(' . QA_DB_MAX_EMAIL_LENGTH . ') NOT NULL',
			'handle' => 'VARCHAR(' . QA_DB_MAX_HANDLE_LENGTH . ') NOT NULL', // username
			'avatarblobid' => 'BIGINT UNSIGNED', // blobid of stored avatar
			'avatarwidth' => 'SMALLINT UNSIGNED', // pixel width of stored avatar
			'avatarheight' => 'SMALLINT UNSIGNED', // pixel height of stored avatar
			'passsalt' => 'BINARY(16)', // salt used to calculate passcheck - null if no password set for direct login
			'passcheck' => 'BINARY(20)', // checksum from password and passsalt - null if no password set for direct login
			'passhash' => 'VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL', // password_hash
			'level' => 'TINYINT UNSIGNED NOT NULL', // basic, editor, admin, etc...
			'loggedin' => 'DATETIME NOT NULL', // time of last login
			'loginip' => 'VARBINARY(16) NOT NULL', // INET6_ATON of IP address of last login
			'written' => 'DATETIME', // time of last write action done by user
			'writeip' => 'VARBINARY(16)', // INET6_ATON of IP address of last write action done by user
			'emailcode' => 'CHAR(8) CHARACTER SET ascii NOT NULL DEFAULT \'\'', // for email confirmation or password reset
			'sessioncode' => 'CHAR(8) CHARACTER SET ascii NOT NULL DEFAULT \'\'', // for comparing against session cookie in browser
			'sessionsource' => 'VARCHAR (16) CHARACTER SET ascii DEFAULT \'\'', // e.g. facebook, openid, etc...
			'flags' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', // see constants at top of /qa-include/app/users.php
			'wallposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // cached count of wall posts
			'PRIMARY KEY (userid)',
			'KEY email (email)',
			'KEY handle (handle)',
			'KEY level (level)',
			'KEY created (created, level, flags)',
		),

		'userlogins' => array(
			'userid' => $useridcoltype . ' NOT NULL',
			'source' => 'VARCHAR (16) CHARACTER SET ascii NOT NULL', // e.g. facebook, openid, etc...
			'identifier' => 'VARBINARY (1024) NOT NULL', // depends on source, e.g. Facebook uid or OpenID url
			'identifiermd5' => 'BINARY (16) NOT NULL', // used to reduce size of index on identifier
			'KEY source (source, identifiermd5)',
			'KEY userid (userid)',
		),

		'userlevels' => array(
			'userid' => $useridcoltype . ' NOT NULL', // the user who has this level
			'entitytype' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // see /qa-include/app/updates.php
			'entityid' => 'INT UNSIGNED NOT NULL', // relevant postid / userid / tag wordid / categoryid
			'level' => 'TINYINT UNSIGNED', // if not NULL, special permission level for that user and that entity
			'UNIQUE userid (userid, entitytype, entityid)',
			'KEY entitytype (entitytype, entityid)',
		),

		'userprofile' => array(
			'userid' => $useridcoltype . ' NOT NULL',
			'title' => 'VARCHAR(' . QA_DB_MAX_PROFILE_TITLE_LENGTH . ') NOT NULL', // profile field name
			'content' => 'VARCHAR(' . QA_DB_MAX_PROFILE_CONTENT_LENGTH . ') NOT NULL', // profile field value
			'UNIQUE userid (userid,title)',
		),

		'userfields' => array(
			'fieldid' => 'SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT',
			'title' => 'VARCHAR(' . QA_DB_MAX_PROFILE_TITLE_LENGTH . ') NOT NULL', // to match title column in userprofile table
			'content' => 'VARCHAR(' . QA_DB_MAX_PROFILE_TITLE_LENGTH . ')', // label for display on user profile pages - NULL means use default
			'position' => 'SMALLINT UNSIGNED NOT NULL',
			'flags' => 'TINYINT UNSIGNED NOT NULL', // QA_FIELD_FLAGS_* at top of /qa-include/app/users.php
			'permit' => 'TINYINT UNSIGNED', // minimum user level required to view (uses QA_PERMIT_* constants), null means no restriction
			'PRIMARY KEY (fieldid)',
		),

		'messages' => array(
			'messageid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'type' => "ENUM('PUBLIC', 'PRIVATE') NOT NULL DEFAULT 'PRIVATE'",
			'fromuserid' => $useridcoltype,
			'touserid' => $useridcoltype,
			'fromhidden' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
			'tohidden' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
			'content' => 'VARCHAR(' . QA_DB_MAX_CONTENT_LENGTH . ') NOT NULL',
			'format' => 'VARCHAR(' . QA_DB_MAX_FORMAT_LENGTH . ') CHARACTER SET ascii NOT NULL',
			'created' => 'DATETIME NOT NULL',
			'PRIMARY KEY (messageid)',
			'KEY type (type, fromuserid, touserid, created)',
			'KEY touserid (touserid, type, created)',
			'KEY fromhidden (fromhidden)',
			'KEY tohidden (tohidden)',
		),

		'userfavorites' => array(
			'userid' => $useridcoltype . ' NOT NULL', // the user who favorited the entity
			'entitytype' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // see /qa-include/app/updates.php
			'entityid' => 'INT UNSIGNED NOT NULL', // favorited postid / userid / tag wordid / categoryid
			'nouserevents' => 'TINYINT UNSIGNED NOT NULL', // do we skip writing events to the user stream?
			'PRIMARY KEY (userid, entitytype, entityid)',
			'KEY userid (userid, nouserevents)',
			'KEY entitytype (entitytype, entityid, nouserevents)',
		),

		'usernotices' => array(
			'noticeid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'userid' => $useridcoltype . ' NOT NULL', // the user to whom the notice is directed
			'content' => 'VARCHAR(' . QA_DB_MAX_CONTENT_LENGTH . ') NOT NULL',
			'format' => 'VARCHAR(' . QA_DB_MAX_FORMAT_LENGTH . ') CHARACTER SET ascii NOT NULL',
			'tags' => 'VARCHAR(' . QA_DB_MAX_CAT_PAGE_TAGS_LENGTH . ')', // any additional information for a plugin to access
			'created' => 'DATETIME NOT NULL',
			'PRIMARY KEY (noticeid)',
			'KEY userid (userid, created)',
		),

		'userevents' => array(
			'userid' => $useridcoltype . ' NOT NULL', // the user to be informed about this event in their updates
			'entitytype' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // see /qa-include/app/updates.php
			'entityid' => 'INT UNSIGNED NOT NULL', // favorited source of event - see userfavorites table - 0 means not from a favorite
			'questionid' => 'INT UNSIGNED NOT NULL', // the affected question
			'lastpostid' => 'INT UNSIGNED NOT NULL', // what part of question was affected
			'updatetype' => 'CHAR(1) CHARACTER SET ascii', // what was done to this part - see /qa-include/app/updates.php
			'lastuserid' => $useridcoltype, // which user (if any) did this action
			'updated' => 'DATETIME NOT NULL', // when the event happened
			'KEY userid (userid, updated)', // for truncation
			'KEY questionid (questionid, userid)', // to limit number of events per question per stream
		),

		'sharedevents' => array(
			'entitytype' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // see /qa-include/app/updates.php
			'entityid' => 'INT UNSIGNED NOT NULL', // see userfavorites table
			'questionid' => 'INT UNSIGNED NOT NULL', // see userevents table
			'lastpostid' => 'INT UNSIGNED NOT NULL', // see userevents table
			'updatetype' => 'CHAR(1) CHARACTER SET ascii', // see userevents table
			'lastuserid' => $useridcoltype, // see userevents table
			'updated' => 'DATETIME NOT NULL', // see userevents table
			'KEY entitytype (entitytype, entityid, updated)', // for truncation
			'KEY questionid (questionid, entitytype, entityid)', // to limit number of events per question per stream
		),

		'cookies' => array(
			'cookieid' => 'BIGINT UNSIGNED NOT NULL',
			'created' => 'DATETIME NOT NULL',
			'createip' => 'VARBINARY(16) NOT NULL', // INET6_ATON of IP address when cookie created
			'written' => 'DATETIME', // time of last write action done by anon user with cookie
			'writeip' => 'VARBINARY(16)', // INET6_ATON of IP address of last write action done by anon user with cookie
			'PRIMARY KEY (cookieid)',
		),

		'categories' => array(
			'categoryid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'parentid' => 'INT UNSIGNED',
			'title' => 'VARCHAR(' . QA_DB_MAX_CAT_PAGE_TITLE_LENGTH . ') NOT NULL', // category name
			'tags' => 'VARCHAR(' . QA_DB_MAX_CAT_PAGE_TAGS_LENGTH . ') NOT NULL', // slug (url fragment) used to identify category
			'content' => 'VARCHAR(' . QA_DB_MAX_CAT_CONTENT_LENGTH . ') NOT NULL DEFAULT \'\'', // description of category
			'qcount' => 'INT UNSIGNED NOT NULL DEFAULT 0',
			'position' => 'SMALLINT UNSIGNED NOT NULL',
			// full slug path for category, with forward slash separators, in reverse order to make index from effective
			'backpath' => 'VARCHAR(' . (QA_CATEGORY_DEPTH * (QA_DB_MAX_CAT_PAGE_TAGS_LENGTH + 1)) . ') NOT NULL DEFAULT \'\'',
			'PRIMARY KEY (categoryid)',
			'UNIQUE parentid (parentid, tags)',
			'UNIQUE parentid_2 (parentid, position)',
			'KEY backpath (backpath(' . QA_DB_MAX_CAT_PAGE_TAGS_LENGTH . '))',
		),

		'pages' => array(
			'pageid' => 'SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT',
			'title' => 'VARCHAR(' . QA_DB_MAX_CAT_PAGE_TITLE_LENGTH . ') NOT NULL', // title for navigation
			'nav' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // which navigation does it go in (M=main, F=footer, B=before main, O=opposite main, other=none)
			'position' => 'SMALLINT UNSIGNED NOT NULL', // global ordering, which allows links to be ordered within each nav area
			'flags' => 'TINYINT UNSIGNED NOT NULL', // local or external, open in new window?
			'permit' => 'TINYINT UNSIGNED', // is there a minimum user level required for it (uses QA_PERMIT_* constants), null means no restriction
			'tags' => 'VARCHAR(' . QA_DB_MAX_CAT_PAGE_TAGS_LENGTH . ') NOT NULL', // slug (url fragment) for page, or url for external pages
			'heading' => 'VARCHAR(' . QA_DB_MAX_TITLE_LENGTH . ')', // for display within <h1> tags
			'content' => 'MEDIUMTEXT', // remainder of page HTML
			'PRIMARY KEY (pageid)',
			'KEY tags (tags)',
			'UNIQUE `position` (position)',
		),

		'widgets' => array(
			'widgetid' => 'SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT',
			// full region: FT=very top of page, FH=below nav area, FL=above footer, FB = very bottom of page
			// side region: ST=top of side, SH=below sidebar, SL=below categories, SB=very bottom of side
			// main region: MT=top of main, MH=below page title, ML=above links, MB=very bottom of main region
			'place' => 'CHAR(2) CHARACTER SET ascii NOT NULL',
			'position' => 'SMALLINT UNSIGNED NOT NULL', // global ordering, which allows widgets to be ordered within each place
			'tags' => 'VARCHAR(' . QA_DB_MAX_WIDGET_TAGS_LENGTH . ') CHARACTER SET ascii NOT NULL', // comma-separated list of templates to display on
			'title' => 'VARCHAR(' . QA_DB_MAX_WIDGET_TITLE_LENGTH . ') NOT NULL', // name of widget module that should be displayed
			'PRIMARY KEY (widgetid)',
			'UNIQUE `position` (position)',
		),

		'posts' => array(
			'postid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'type' => "ENUM('Q', 'A', 'C', 'Q_HIDDEN', 'A_HIDDEN', 'C_HIDDEN', 'Q_QUEUED', 'A_QUEUED', 'C_QUEUED', 'NOTE') NOT NULL",
			'parentid' => 'INT UNSIGNED', // for follow on questions, all answers and comments
			'categoryid' => 'INT UNSIGNED', // this is the canonical final category id
			'catidpath1' => 'INT UNSIGNED', // the catidpath* columns are calculated from categoryid, for the full hierarchy of that category
			'catidpath2' => 'INT UNSIGNED', // note that QA_CATEGORY_DEPTH=4
			'catidpath3' => 'INT UNSIGNED',
			'acount' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', // number of answers (for questions)
			'amaxvote' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', // highest netvotes of child answers (for questions)
			'selchildid' => 'INT UNSIGNED', // selected answer (for questions)
			// if closed due to being a duplicate, this is the postid of that other question
			// if closed for another reason, that reason should be added as a comment on the question, and this field is the comment's id
			'closedbyid' => 'INT UNSIGNED', // not null means question is closed
			'userid' => $useridcoltype, // which user wrote it
			'cookieid' => 'BIGINT UNSIGNED', // which cookie wrote it, if an anonymous post
			'createip' => 'VARBINARY(16)', // INET6_ATON of IP address used to create the post
			'lastuserid' => $useridcoltype, // which user last modified it
			'lastip' => 'VARBINARY(16)', // INET6_ATON of IP address which last modified the post
			'upvotes' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
			'downvotes' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
			'netvotes' => 'SMALLINT NOT NULL DEFAULT 0',
			'lastviewip' => 'VARBINARY(16)', // INET6_ATON of IP address which last viewed the post
			'views' => 'INT UNSIGNED NOT NULL DEFAULT 0',
			'hotness' => 'FLOAT',
			'flagcount' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
			'format' => 'VARCHAR(' . QA_DB_MAX_FORMAT_LENGTH . ') CHARACTER SET ascii NOT NULL DEFAULT \'\'', // format of content, e.g. 'html'
			'created' => 'DATETIME NOT NULL',
			'updated' => 'DATETIME', // time of last update
			'updatetype' => 'CHAR(1) CHARACTER SET ascii', // see /qa-include/app/updates.php
			'title' => 'VARCHAR(' . QA_DB_MAX_TITLE_LENGTH . ')',
			'content' => 'VARCHAR(' . QA_DB_MAX_CONTENT_LENGTH . ')',
			'tags' => 'VARCHAR(' . QA_DB_MAX_TAGS_LENGTH . ')', // string of tags separated by commas
			'name' => 'VARCHAR(' . QA_DB_MAX_NAME_LENGTH . ')', // name of author if post anonymonus
			'notify' => 'VARCHAR(' . QA_DB_MAX_EMAIL_LENGTH . ')', // email address, or @ to get from user, or NULL for none
			'PRIMARY KEY (postid)',
			'KEY type (type, created)', // for getting recent questions, answers, comments
			'KEY type_2 (type, acount, created)', // for getting unanswered questions
			'KEY type_4 (type, netvotes, created)', // for getting posts with the most votes
			'KEY type_5 (type, views, created)', // for getting questions with the most views
			'KEY type_6 (type, hotness)', // for getting 'hot' questions
			'KEY type_7 (type, amaxvote, created)', // for getting questions with no upvoted answers
			'KEY parentid (parentid, type)', // for getting a question's answers, any post's comments and follow-on questions
			'KEY userid (userid, type, created)', // for recent questions, answers or comments by a user
			'KEY selchildid (selchildid, type, created)', // for counting how many of a user's answers have been selected, unselected qs
			'KEY closedbyid (closedbyid)', // for the foreign key constraint
			'KEY catidpath1 (catidpath1, type, created)', // for getting question, answers or comments in a specific level category
			'KEY catidpath2 (catidpath2, type, created)', // note that QA_CATEGORY_DEPTH=4
			'KEY catidpath3 (catidpath3, type, created)',
			'KEY categoryid (categoryid, type, created)', // this can also be used for searching the equivalent of catidpath4
			'KEY createip (createip, created)', // for getting posts created by a specific IP address
			'KEY updated (updated, type)', // for getting recent edits across all categories
			'KEY flagcount (flagcount, created, type)', // for getting posts with the most flags
			'KEY catidpath1_2 (catidpath1, updated, type)', // for getting recent edits in a specific level category
			'KEY catidpath2_2 (catidpath2, updated, type)', // note that QA_CATEGORY_DEPTH=4
			'KEY catidpath3_2 (catidpath3, updated, type)',
			'KEY categoryid_2 (categoryid, updated, type)',
			'KEY lastuserid (lastuserid, updated, type)', // for getting posts edited by a specific user
			'KEY lastip (lastip, updated, type)', // for getting posts edited by a specific IP address
			'CONSTRAINT ^posts_ibfk_2 FOREIGN KEY (parentid) REFERENCES ^posts(postid)', // ^posts_ibfk_1 is set later on userid
			'CONSTRAINT ^posts_ibfk_3 FOREIGN KEY (categoryid) REFERENCES ^categories(categoryid) ON DELETE SET NULL',
			'CONSTRAINT ^posts_ibfk_4 FOREIGN KEY (closedbyid) REFERENCES ^posts(postid)',
		),

		'blobs' => array(
			'blobid' => 'BIGINT UNSIGNED NOT NULL',
			'format' => 'VARCHAR(' . QA_DB_MAX_FORMAT_LENGTH . ') CHARACTER SET ascii NOT NULL', // format e.g. 'jpeg', 'gif', 'png'
			'content' => 'MEDIUMBLOB', // null means it's stored on disk in QA_BLOBS_DIRECTORY
			'filename' => 'VARCHAR(' . QA_DB_MAX_BLOB_FILE_NAME_LENGTH . ')', // name of source file (if appropriate)
			'userid' => $useridcoltype, // which user created it
			'cookieid' => 'BIGINT UNSIGNED', // which cookie created it
			'createip' => 'VARBINARY(16)', // INET6_ATON of IP address that created it
			'created' => 'DATETIME', // when it was created
			'PRIMARY KEY (blobid)',
		),

		'words' => array(
			'wordid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'word' => 'VARCHAR(' . QA_DB_MAX_WORD_LENGTH . ') NOT NULL',
			'titlecount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // only counts one per post
			'contentcount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // only counts one per post
			'tagwordcount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // for words in tags - only counts one per post
			'tagcount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // for tags as a whole - only counts one per post (though no duplicate tags anyway)
			'PRIMARY KEY (wordid)',
			'KEY word (word)',
			'KEY tagcount (tagcount)', // for sorting by most popular tags
		),

		'titlewords' => array(
			'postid' => 'INT UNSIGNED NOT NULL',
			'wordid' => 'INT UNSIGNED NOT NULL',
			'KEY postid (postid)',
			'KEY wordid (wordid)',
			'CONSTRAINT ^titlewords_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
			'CONSTRAINT ^titlewords_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
		),

		'contentwords' => array(
			'postid' => 'INT UNSIGNED NOT NULL',
			'wordid' => 'INT UNSIGNED NOT NULL',
			'count' => 'TINYINT UNSIGNED NOT NULL', // how many times word appears in the post - anything over 255 can be ignored
			'type' => "ENUM('Q', 'A', 'C', 'NOTE') NOT NULL", // the post's type (copied here for quick searching)
			'questionid' => 'INT UNSIGNED NOT NULL', // the id of the post's antecedent parent (here for quick searching)
			'KEY postid (postid)',
			'KEY wordid (wordid)',
			'CONSTRAINT ^contentwords_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
			'CONSTRAINT ^contentwords_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
		),

		'tagwords' => array(
			'postid' => 'INT UNSIGNED NOT NULL',
			'wordid' => 'INT UNSIGNED NOT NULL',
			'KEY postid (postid)',
			'KEY wordid (wordid)',
			'CONSTRAINT ^tagwords_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
			'CONSTRAINT ^tagwords_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
		),

		'posttags' => array(
			'postid' => 'INT UNSIGNED NOT NULL',
			'wordid' => 'INT UNSIGNED NOT NULL',
			'postcreated' => 'DATETIME NOT NULL', // created time of post (copied here for tag page's list of recent questions)
			'KEY postid (postid)',
			'KEY wordid (wordid, postcreated)',
			'CONSTRAINT ^posttags_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
			'CONSTRAINT ^posttags_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
		),

		'uservotes' => array(
			'postid' => 'INT UNSIGNED NOT NULL',
			'userid' => $useridcoltype . ' NOT NULL',
			'vote' => 'TINYINT NOT NULL', // -1, 0 or 1
			'flag' => 'TINYINT NOT NULL', // 0 or 1
			'votecreated' => 'DATETIME', // time of first vote
			'voteupdated' => 'DATETIME', // time of last vote change
			'UNIQUE userid (userid, postid)',
			'KEY postid (postid)',
			'KEY voted (votecreated, voteupdated)',
			'CONSTRAINT ^uservotes_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
		),

		// many userpoints columns could be unsigned but MySQL appears to mess up points calculations that go negative as a result

		'userpoints' => array(
			'userid' => $useridcoltype . ' NOT NULL',
			'points' => 'INT NOT NULL DEFAULT 0', // user's points as displayed, after final multiple
			'qposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions by user (excluding hidden/queued)
			'aposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers by user (excluding hidden/queued)
			'cposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of comments by user (excluding hidden/queued)
			'aselects' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions by user where they've selected an answer
			'aselecteds' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers by user that have been selected as the best
			'qupvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions the user has voted up
			'qdownvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions the user has voted down
			'aupvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers the user has voted up
			'adownvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers the user has voted down
			'cupvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of comments the user has voted up
			'cdownvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of comments the user has voted down
			'qvoteds' => 'INT NOT NULL DEFAULT 0', // points from votes on this user's questions (applying per-question limits), before final multiple
			'avoteds' => 'INT NOT NULL DEFAULT 0', // points from votes on this user's answers (applying per-answer limits), before final multiple
			'cvoteds' => 'INT NOT NULL DEFAULT 0', // points from votes on this user's comments (applying per-comment limits), before final multiple
			'upvoteds' => 'INT NOT NULL DEFAULT 0', // number of up votes received on this user's questions or answers
			'downvoteds' => 'INT NOT NULL DEFAULT 0', // number of down votes received on this user's questions or answers
			'bonus' => 'INT NOT NULL DEFAULT 0', // bonus assigned by administrator to a user
			'PRIMARY KEY (userid)',
			'KEY points (points)',
		),

		'userlimits' => array(
			'userid' => $useridcoltype . ' NOT NULL',
			'action' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // see constants at top of /qa-include/app/limits.php
			'period' => 'INT UNSIGNED NOT NULL', // integer representing hour of last action
			'count' => 'SMALLINT UNSIGNED NOT NULL', // how many of this action has been performed within that hour
			'UNIQUE userid (userid, action)',
		),

		// most columns in iplimits have the same meaning as those in userlimits

		'iplimits' => array(
			'ip' => 'VARBINARY(16) NOT NULL', // INET6_ATON of IP address
			'action' => 'CHAR(1) CHARACTER SET ascii NOT NULL',
			'period' => 'INT UNSIGNED NOT NULL',
			'count' => 'SMALLINT UNSIGNED NOT NULL',
			'UNIQUE ip (ip, action)',
		),

		'options' => array(
			'title' => 'VARCHAR(' . QA_DB_MAX_OPTION_TITLE_LENGTH . ') NOT NULL', // name of option
			'content' => 'VARCHAR(' . QA_DB_MAX_CONTENT_LENGTH . ') NOT NULL', // value of option
			'PRIMARY KEY (title)',
		),

		'cache' => array(
			'type' => 'CHAR(8) CHARACTER SET ascii NOT NULL', // e.g. 'avXXX' for avatar sized to XXX pixels square
			'cacheid' => 'BIGINT UNSIGNED DEFAULT 0', // optional further identifier, e.g. blobid on which cache entry is based
			'content' => 'MEDIUMBLOB NOT NULL',
			'created' => 'DATETIME NOT NULL',
			'lastread' => 'DATETIME NOT NULL',
			'PRIMARY KEY (type, cacheid)',
			'KEY (lastread)',
		),

		'usermetas' => array(
			'userid' => $useridcoltype . ' NOT NULL',
			'title' => 'VARCHAR(' . QA_DB_MAX_META_TITLE_LENGTH . ') NOT NULL',
			'content' => 'VARCHAR(' . QA_DB_MAX_META_CONTENT_LENGTH . ') NOT NULL',
			'PRIMARY KEY (userid, title)',
		),

		'postmetas' => array(
			'postid' => 'INT UNSIGNED NOT NULL',
			'title' => 'VARCHAR(' . QA_DB_MAX_META_TITLE_LENGTH . ') NOT NULL',
			'content' => 'VARCHAR(' . QA_DB_MAX_META_CONTENT_LENGTH . ') NOT NULL',
			'PRIMARY KEY (postid, title)',
			'CONSTRAINT ^postmetas_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
		),

		'categorymetas' => array(
			'categoryid' => 'INT UNSIGNED NOT NULL',
			'title' => 'VARCHAR(' . QA_DB_MAX_META_TITLE_LENGTH . ') NOT NULL',
			'content' => 'VARCHAR(' . QA_DB_MAX_META_CONTENT_LENGTH . ') NOT NULL',
			'PRIMARY KEY (categoryid, title)',
			'CONSTRAINT ^categorymetas_ibfk_1 FOREIGN KEY (categoryid) REFERENCES ^categories(categoryid) ON DELETE CASCADE',
		),

		'tagmetas' => array(
			'tag' => 'VARCHAR(' . QA_DB_MAX_WORD_LENGTH . ') NOT NULL',
			'title' => 'VARCHAR(' . QA_DB_MAX_META_TITLE_LENGTH . ') NOT NULL',
			'content' => 'VARCHAR(' . QA_DB_MAX_META_CONTENT_LENGTH . ') NOT NULL',
			'PRIMARY KEY (tag, title)',
		),

	);

	if (QA_FINAL_EXTERNAL_USERS) {
		unset($tables['users']);
		unset($tables['userlogins']);
		unset($tables['userprofile']);
		unset($tables['userfields']);
		unset($tables['messages']);

	} else {
		$userforeignkey = 'FOREIGN KEY (userid) REFERENCES ^users(userid)';

		$tables['userlogins'][] = 'CONSTRAINT ^userlogins_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['userprofile'][] = 'CONSTRAINT ^userprofile_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['posts'][] = 'CONSTRAINT ^posts_ibfk_1 ' . $userforeignkey . ' ON DELETE SET NULL';
		$tables['uservotes'][] = 'CONSTRAINT ^uservotes_ibfk_2 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['userlimits'][] = 'CONSTRAINT ^userlimits_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['userfavorites'][] = 'CONSTRAINT ^userfavorites_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['usernotices'][] = 'CONSTRAINT ^usernotices_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['userevents'][] = 'CONSTRAINT ^userevents_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['userlevels'][] = 'CONSTRAINT ^userlevels_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['usermetas'][] = 'CONSTRAINT ^usermetas_ibfk_1 ' . $userforeignkey . ' ON DELETE CASCADE';
		$tables['messages'][] = 'CONSTRAINT ^messages_ibfk_1 FOREIGN KEY (fromuserid) REFERENCES ^users(userid) ON DELETE SET NULL';
		$tables['messages'][] = 'CONSTRAINT ^messages_ibfk_2 FOREIGN KEY (touserid) REFERENCES ^users(userid) ON DELETE SET NULL';
	}

	return $tables;
}


/**
 * Return array with all values from $array as keys
 * @param array $array
 * @return array
 */
function qa_array_to_keys($array)
{
	return empty($array) ? array() : array_combine($array, array_fill(0, count($array), true));
}


/**
 * Return a list of tables missing from the database, [table name] => [column/index definitions]
 * @param array $definitions
 * @return array
 */
function qa_db_missing_tables($definitions)
{
	$keydbtables = qa_array_to_keys(qa_db_list_tables(true));

	$missing = array();

	foreach ($definitions as $rawname => $definition)
		if (!isset($keydbtables[qa_db_add_table_prefix($rawname)]))
			$missing[$rawname] = $definition;

	return $missing;
}


/**
 * Return a list of columns missing from $table in the database, given the full definition set in $definition
 * @param string $table
 * @param array $definition
 * @return array
 */
function qa_db_missing_columns($table, $definition)
{
	$keycolumns = qa_array_to_keys(qa_db_read_all_values(qa_db_query_sub('SHOW COLUMNS FROM ^' . $table)));

	$missing = array();

	foreach ($definition as $colname => $coldefn)
		if (!is_int($colname) && !isset($keycolumns[$colname]))
			$missing[$colname] = $coldefn;

	return $missing;
}


/**
 * Return the current version of the Q2A database, to determine need for DB upgrades
 * @return int|null
 */
function qa_db_get_db_version()
{
	$definitions = qa_db_table_definitions();

	if (count(qa_db_missing_columns('options', $definitions['options'])) == 0) {
		$version = (int)qa_db_read_one_value(qa_db_query_sub("SELECT content FROM ^options WHERE title='db_version'"), true);

		if ($version > 0)
			return $version;
	}

	return null;
}


/**
 * Set the current version in the database
 * @param int $version
 */
function qa_db_set_db_version($version)
{
	require_once QA_INCLUDE_DIR . 'db/options.php';

	qa_db_set_option('db_version', $version);
}


/**
 * Return a string describing what is wrong with the database, or false if everything is just fine
 * @return false|string
 */
function qa_db_check_tables()
{
	qa_db_query_raw('UNLOCK TABLES'); // we could be inside a lock tables block

	$version = qa_db_read_one_value(qa_db_query_raw('SELECT VERSION()'));

	if (((float)$version) < 4.1)
		qa_fatal_error('MySQL version 4.1 or later is required - you appear to be running MySQL ' . $version);

	$definitions = qa_db_table_definitions();
	$missing = qa_db_missing_tables($definitions);

	if (count($missing) == count($definitions))
		return 'none';

	else {
		if (!isset($missing['options'])) {
			$version = qa_db_get_db_version();

			if (isset($version) && ($version < QA_DB_VERSION_CURRENT))
				return 'old-version';
		}

		if (count($missing)) {
			if (defined('QA_MYSQL_USERS_PREFIX')) { // special case if two installations sharing users
				$datacount = 0;
				$datamissing = 0;

				foreach ($definitions as $rawname => $definition) {
					if (qa_db_add_table_prefix($rawname) == (QA_MYSQL_TABLE_PREFIX . $rawname)) {
						$datacount++;

						if (isset($missing[$rawname]))
							$datamissing++;
					}
				}

				if ($datacount == $datamissing && $datamissing == count($missing))
					return 'non-users-missing';
			}

			return 'table-missing';

		} else
			foreach ($definitions as $table => $definition)
				if (count(qa_db_missing_columns($table, $definition)))
					return 'column-missing';
	}

	return false;
}


/**
 * Install any missing database tables and/or columns and automatically set version as latest.
 * This is not suitable for use if the database needs upgrading.
 */
function qa_db_install_tables()
{
	$definitions = qa_db_table_definitions();

	$missingtables = qa_db_missing_tables($definitions);

	foreach ($missingtables as $rawname => $definition) {
		qa_db_query_sub(qa_db_create_table_sql($rawname, $definition));

		if ($rawname == 'userfields')
			qa_db_query_sub(qa_db_default_userfields_sql());
	}

	foreach ($definitions as $table => $definition) {
		$missingcolumns = qa_db_missing_columns($table, $definition);

		foreach ($missingcolumns as $colname => $coldefn)
			qa_db_query_sub('ALTER TABLE ^' . $table . ' ADD COLUMN ' . $colname . ' ' . $coldefn);
	}

	qa_db_set_db_version(QA_DB_VERSION_CURRENT);
}


/**
 * Return the SQL command to create a table with $rawname and $definition obtained from qa_db_table_definitions()
 * @param string $rawname
 * @param array $definition
 * @return string
 */
function qa_db_create_table_sql($rawname, $definition)
{
	$querycols = '';
	foreach ($definition as $colname => $coldef)
		if (isset($coldef))
			$querycols .= (strlen($querycols) ? ', ' : '') . (is_int($colname) ? $coldef : ($colname . ' ' . $coldef));

	return 'CREATE TABLE ^' . $rawname . ' (' . $querycols . ') ENGINE=InnoDB CHARSET=utf8';
}


/**
 * Return the SQL to create the default entries in the userfields table (before 1.3 these were hard-coded in PHP)
 * @return string
 */
function qa_db_default_userfields_sql()
{
	require_once QA_INCLUDE_DIR . 'app/options.php';

	$profileFields = array(
		array(
			'title' => 'name',
			'position' => 1,
			'flags' => 0,
			'permit' => QA_PERMIT_ALL,
		),
		array(
			'title' => 'location',
			'position' => 2,
			'flags' => 0,
			'permit' => QA_PERMIT_ALL,
		),
		array(
			'title' => 'website',
			'position' => 3,
			'flags' => QA_FIELD_FLAGS_LINK_URL,
			'permit' => QA_PERMIT_ALL,
		),
		array(
			'title' => 'about',
			'position' => 4,
			'flags' => QA_FIELD_FLAGS_MULTI_LINE,
			'permit' => QA_PERMIT_ALL,
		),
	);

	$sql = 'INSERT INTO ^userfields (title, position, flags, permit) VALUES'; // content column will be NULL, meaning use default from lang files

	foreach ($profileFields as $field) {
		$sql .= sprintf('("%s", %d, %d, %d), ', $field['title'], $field['position'], $field['flags'], $field['permit']);
	}

	$sql = substr($sql, 0, -2);

	return $sql;
}


/**
 * Upgrade the database schema to the latest version, outputting progress to the browser
 */
function qa_db_upgrade_tables()
{
	$definitions = qa_db_table_definitions();
	$keyrecalc = array();

	// Write-lock all Q2A tables before we start so no one can read or write anything

	$keydbtables = qa_array_to_keys(qa_db_list_tables(true));

	foreach ($definitions as $rawname => $definition)
		if (isset($keydbtables[qa_db_add_table_prefix($rawname)]))
			$locks[] = '^' . $rawname . ' WRITE';

	$locktablesquery = 'LOCK TABLES ' . implode(', ', $locks);

	qa_db_upgrade_query($locktablesquery);

	// Upgrade it step-by-step until it's up to date (do LOCK TABLES after ALTER TABLE because the lock can sometimes be lost)

	// message (used in sprintf) for skipping shared user tables
	$skipMessage = 'Skipping upgrading %s table since it was already upgraded by another Q2A site sharing it.';

	while (1) {
		$version = qa_db_get_db_version();

		if ($version >= QA_DB_VERSION_CURRENT)
			break;

		$newversion = $version + 1;

		qa_db_upgrade_progress(QA_DB_VERSION_CURRENT - $version . ' upgrade step/s remaining...');

		switch ($newversion) {
			// Up to here: Version 1.5.x

			case 48:
				if (!QA_FINAL_EXTERNAL_USERS) {
					$keycolumns = qa_array_to_keys(qa_db_read_all_values(qa_db_query_sub('SHOW COLUMNS FROM ^messages')));
					// might be using messages table shared with another installation, so check if we need to upgrade

					if (isset($keycolumns['type']))
						qa_db_upgrade_progress('Skipping upgrading messages table since it was already upgraded by another Q2A site sharing it.');

					else {
						qa_db_upgrade_query('ALTER TABLE ^messages ADD COLUMN type ' . $definitions['messages']['type'] . ' AFTER messageid, DROP KEY fromuserid, ADD key type (type, fromuserid, touserid, created), ADD KEY touserid (touserid, type, created)');
						qa_db_upgrade_query($locktablesquery);
					}
				}
				break;

			case 49:
				if (!QA_FINAL_EXTERNAL_USERS) {
					qa_db_upgrade_query('ALTER TABLE ^users CHANGE COLUMN flags flags ' . $definitions['users']['flags']);
					qa_db_upgrade_query($locktablesquery);
				}
				break;

			case 50:
				qa_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN name ' . $definitions['posts']['name'] . ' AFTER tags');
				qa_db_upgrade_query($locktablesquery);
				break;

			case 51:
				if (!QA_FINAL_EXTERNAL_USERS) {
					// might be using userfields table shared with another installation, so check if we need to upgrade
					$keycolumns = qa_array_to_keys(qa_db_read_all_values(qa_db_query_sub('SHOW COLUMNS FROM ^userfields')));

					if (isset($keycolumns['permit']))
						qa_db_upgrade_progress('Skipping upgrading userfields table since it was already upgraded by another Q2A site sharing it.');

					else {
						qa_db_upgrade_query('ALTER TABLE ^userfields ADD COLUMN permit ' . $definitions['userfields']['permit'] . ' AFTER flags');
						qa_db_upgrade_query($locktablesquery);
					}
				}
				break;

			case 52:
				if (!QA_FINAL_EXTERNAL_USERS) {
					$keyindexes = qa_array_to_keys(qa_db_read_all_assoc(qa_db_query_sub('SHOW INDEX FROM ^users'), null, 'Key_name'));

					if (isset($keyindexes['created']))
						qa_db_upgrade_progress('Skipping upgrading users table since it was already upgraded by another Q2A site sharing it.');

					else {
						qa_db_upgrade_query('ALTER TABLE ^users ADD KEY created (created, level, flags)');
						qa_db_upgrade_query($locktablesquery);
					}
				}
				break;

			case 53:
				qa_db_upgrade_query('ALTER TABLE ^blobs CHANGE COLUMN content content ' . $definitions['blobs']['content']);
				qa_db_upgrade_query($locktablesquery);
				break;

			case 54:
				qa_db_upgrade_query('UNLOCK TABLES');

				qa_db_upgrade_query('SET FOREIGN_KEY_CHECKS=0'); // in case InnoDB not available

				qa_db_upgrade_query(qa_db_create_table_sql('userlevels', array(
					'userid' => $definitions['userlevels']['userid'],
					'entitytype' => $definitions['userlevels']['entitytype'],
					'entityid' => $definitions['userlevels']['entityid'],
					'level' => $definitions['userlevels']['level'],
					'UNIQUE userid (userid, entitytype, entityid)',
					'KEY entitytype (entitytype, entityid)',
					QA_FINAL_EXTERNAL_USERS ? null : 'CONSTRAINT ^userlevels_ibfk_1 FOREIGN KEY (userid) REFERENCES ^users(userid) ON DELETE CASCADE',
				)));

				$locktablesquery .= ', ^userlevels WRITE';
				qa_db_upgrade_query($locktablesquery);
				break;

			// Up to here: Version 1.6 beta 1

			case 55:
				if (!QA_FINAL_EXTERNAL_USERS) {
					// might be using users table shared with another installation, so check if we need to upgrade
					$keycolumns = qa_array_to_keys(qa_db_read_all_values(qa_db_query_sub('SHOW COLUMNS FROM ^users')));

					if (isset($keycolumns['wallposts']))
						qa_db_upgrade_progress('Skipping upgrading users table since it was already upgraded by another Q2A site sharing it.');

					else {
						qa_db_upgrade_query('ALTER TABLE ^users ADD COLUMN wallposts ' . $definitions['users']['wallposts'] . ' AFTER flags');
						qa_db_upgrade_query($locktablesquery);
					}
				}
				break;

			// Up to here: Version 1.6 beta 2

			case 56:
				qa_db_upgrade_query('ALTER TABLE ^pages DROP INDEX tags, ADD KEY tags (tags)');
				qa_db_upgrade_query($locktablesquery);
				break;

			// Up to here: Version 1.6 (release)

			case 57:
				if (!QA_FINAL_EXTERNAL_USERS) {
					// might be using messages table shared with another installation, so check if we need to upgrade
					$keycolumns = qa_array_to_keys(qa_db_read_all_values(qa_db_query_sub('SHOW COLUMNS FROM ^messages')));

					if (isset($keycolumns['fromhidden']))
						qa_db_upgrade_progress('Skipping upgrading messages table since it was already upgraded by another Q2A site sharing it.');
					else {
						qa_db_upgrade_query('ALTER TABLE ^messages ADD COLUMN fromhidden ' . $definitions['messages']['fromhidden'] . ' AFTER touserid');
						qa_db_upgrade_query('ALTER TABLE ^messages ADD COLUMN tohidden ' . $definitions['messages']['tohidden'] . ' AFTER fromhidden');
						qa_db_upgrade_query('ALTER TABLE ^messages ADD KEY fromhidden (fromhidden), ADD KEY tohidden (tohidden)');

						qa_db_upgrade_query($locktablesquery);
					}
				}
				break;

			case 58:
				// note: need to use full table names here as aliases trigger error "Table 'x' was not locked with LOCK TABLES"
				qa_db_upgrade_query('DELETE FROM ^userfavorites WHERE entitytype="U" AND userid=entityid');
				qa_db_upgrade_query('DELETE ^uservotes FROM ^uservotes JOIN ^posts ON ^uservotes.postid=^posts.postid AND ^uservotes.userid=^posts.userid');
				qa_db_upgrade_query($locktablesquery);

				$keyrecalc['dorecountposts'] = true;
				$keyrecalc['dorecalcpoints'] = true;
				break;

			// Up to here: Version 1.7

			case 59:
				// upgrade from alpha version removed
				break;

			// Up to here: Version 1.7.1

			case 60:
				// add new category widget - note title must match that from qa_register_core_modules()
				if (qa_using_categories()) {
					$widgetid = qa_db_widget_create('Categories', 'all');
					qa_db_widget_move($widgetid, 'SL', 1);
				}
				break;

			case 61:
				// upgrade length of qa_posts.content field to 12000
				$newlength = QA_DB_MAX_CONTENT_LENGTH;
				$query = 'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE table_schema=$ AND table_name=$ AND column_name="content"';
				$tablename = qa_db_add_table_prefix('posts');
				$oldlength = qa_db_read_one_value(qa_db_query_sub($query, QA_FINAL_MYSQL_DATABASE, $tablename));

				if ($oldlength < $newlength) {
					qa_db_upgrade_query('ALTER TABLE ^posts MODIFY content ' . $definitions['posts']['content']);
				}

				break;

			case 62:
				if (!QA_FINAL_EXTERNAL_USERS) {
					// might be using users table shared with another installation, so check if we need to upgrade
					$keycolumns = qa_array_to_keys(qa_db_read_all_values(qa_db_query_sub('SHOW COLUMNS FROM ^users')));

					if (isset($keycolumns['passhash']))
						qa_db_upgrade_progress(sprintf($skipMessage, 'users'));
					else {
						// add column to qa_users to handle new bcrypt passwords
						qa_db_upgrade_query('ALTER TABLE ^users ADD COLUMN passhash ' . $definitions['users']['passhash'] . ' AFTER passcheck');
						qa_db_upgrade_query($locktablesquery);
					}
				}
				break;

			case 63:
				// check for shared cookies table
				$fieldDef = qa_db_read_one_assoc(qa_db_query_sub('SHOW COLUMNS FROM ^cookies WHERE Field="createip"'));
				if (strtolower($fieldDef['Type']) === 'varbinary(16)')
					qa_db_upgrade_progress(sprintf($skipMessage, 'cookies'));
				else {
					qa_db_upgrade_query('ALTER TABLE ^cookies MODIFY writeip ' . $definitions['cookies']['writeip'] . ', MODIFY createip ' . $definitions['cookies']['createip']);
					qa_db_upgrade_query('UPDATE ^cookies SET writeip = UNHEX(HEX(CAST(writeip AS UNSIGNED))), createip = UNHEX(HEX(CAST(createip AS UNSIGNED)))');
				}

				qa_db_upgrade_query('ALTER TABLE ^iplimits MODIFY ip ' . $definitions['iplimits']['ip']);
				qa_db_upgrade_query('UPDATE ^iplimits SET ip = UNHEX(HEX(CAST(ip AS UNSIGNED)))');

				// check for shared blobs table
				$fieldDef = qa_db_read_one_assoc(qa_db_query_sub('SHOW COLUMNS FROM ^blobs WHERE Field="createip"'));
				if (strtolower($fieldDef['Type']) === 'varbinary(16)')
					qa_db_upgrade_progress(sprintf($skipMessage, 'blobs'));
				else {
					qa_db_upgrade_query('ALTER TABLE ^blobs MODIFY createip ' . $definitions['blobs']['createip']);
					qa_db_upgrade_query('UPDATE ^blobs SET createip = UNHEX(HEX(CAST(createip AS UNSIGNED)))');
				}

				qa_db_upgrade_query('ALTER TABLE ^posts MODIFY lastviewip ' . $definitions['posts']['lastviewip'] . ', MODIFY lastip ' . $definitions['posts']['lastip'] . ', MODIFY createip ' . $definitions['posts']['createip']);
				qa_db_upgrade_query('UPDATE ^posts SET lastviewip = UNHEX(HEX(CAST(lastviewip AS UNSIGNED))), lastip = UNHEX(HEX(CAST(lastip AS UNSIGNED))), createip = UNHEX(HEX(CAST(createip AS UNSIGNED)))');

				if (!QA_FINAL_EXTERNAL_USERS) {
					// check for shared users table
					$fieldDef = qa_db_read_one_assoc(qa_db_query_sub('SHOW COLUMNS FROM ^users WHERE Field="createip"'));
					if (strtolower($fieldDef['Type']) === 'varbinary(16)')
						qa_db_upgrade_progress(sprintf($skipMessage, 'users'));
					else {
						qa_db_upgrade_query('ALTER TABLE ^users MODIFY createip ' . $definitions['users']['createip'] . ', MODIFY loginip ' . $definitions['users']['loginip'] . ', MODIFY writeip ' . $definitions['users']['writeip']);
						qa_db_upgrade_query('UPDATE ^users SET createip = UNHEX(HEX(CAST(createip AS UNSIGNED))), loginip = UNHEX(HEX(CAST(loginip AS UNSIGNED))), writeip = UNHEX(HEX(CAST(writeip AS UNSIGNED)))');
					}
				}

				qa_db_upgrade_query($locktablesquery);
				break;

			case 64:
				$pluginManager = new \Q2A\Plugin\PluginManager();
				$allPlugins = $pluginManager->getFilesystemPlugins();
				$pluginManager->setEnabledPlugins($allPlugins);
				break;

			case 65:
				qa_db_upgrade_query('ALTER TABLE ^uservotes ADD COLUMN votecreated ' . $definitions['uservotes']['votecreated'] . ' AFTER flag');
				qa_db_upgrade_query('ALTER TABLE ^uservotes ADD COLUMN voteupdated ' . $definitions['uservotes']['voteupdated'] . ' AFTER votecreated');
				qa_db_upgrade_query('ALTER TABLE ^uservotes ADD KEY voted (votecreated, voteupdated)');
				qa_db_upgrade_query($locktablesquery);

				// for old votes, set a default date of when that post was made
				qa_db_upgrade_query('UPDATE ^uservotes, ^posts SET ^uservotes.votecreated=^posts.created WHERE ^uservotes.postid=^posts.postid AND (^uservotes.vote != 0 OR ^uservotes.flag=0)');
				break;

			case 66:
				$newColumns = array(
					'ADD COLUMN cupvotes ' . $definitions['userpoints']['cupvotes'] . ' AFTER adownvotes',
					'ADD COLUMN cdownvotes ' . $definitions['userpoints']['cdownvotes'] . ' AFTER cupvotes',
					'ADD COLUMN cvoteds ' . $definitions['userpoints']['cvoteds'] . ' AFTER avoteds',
				);
				qa_db_upgrade_query('ALTER TABLE ^userpoints ' . implode(', ', $newColumns));
				qa_db_upgrade_query($locktablesquery);
				break;

			case 67:
				if (!QA_FINAL_EXTERNAL_USERS) {
					// ensure we don't have old userids lying around
					qa_db_upgrade_query('ALTER TABLE ^messages MODIFY fromuserid ' . $definitions['messages']['fromuserid']);
					qa_db_upgrade_query('ALTER TABLE ^messages MODIFY touserid ' . $definitions['messages']['touserid']);
					qa_db_upgrade_query('UPDATE ^messages SET fromuserid=NULL WHERE fromuserid NOT IN (SELECT userid FROM ^users)');
					qa_db_upgrade_query('UPDATE ^messages SET touserid=NULL WHERE touserid NOT IN (SELECT userid FROM ^users)');
					// set up foreign key on messages table
					qa_db_upgrade_query('ALTER TABLE ^messages ADD CONSTRAINT ^messages_ibfk_1 FOREIGN KEY (fromuserid) REFERENCES ^users(userid) ON DELETE SET NULL');
					qa_db_upgrade_query('ALTER TABLE ^messages ADD CONSTRAINT ^messages_ibfk_2 FOREIGN KEY (touserid) REFERENCES ^users(userid) ON DELETE SET NULL');
				}

				qa_db_upgrade_query($locktablesquery);
				break;

			// Up to here: Version 1.8

			case 68:
				// remove favorites of deleted users
				qa_db_upgrade_query('DELETE FROM ^userfavorites WHERE entitytype="U" AND entityid NOT IN (SELECT userid FROM ^users)');
				break;

			// Up to here: Version 1.9
		}

		qa_db_set_db_version($newversion);

		if (qa_db_get_db_version() != $newversion)
			qa_fatal_error('Could not increment database version');
	}

	qa_db_upgrade_query('UNLOCK TABLES');

	// Perform any necessary recalculations, as determined by upgrade steps

	foreach (array_keys($keyrecalc) as $state) {
		$recalc = new \Q2A\Recalc\RecalcMain($state);
		while ($recalc->getState()) {
			set_time_limit(60);

			$stoptime = time() + 2;

			while ($recalc->performStep() && (time() < $stoptime))
				;

			qa_db_upgrade_progress($recalc->getMessage());
		}
	}
}


/**
 * Reset the definitions of $columns in $table according to the $definitions array
 * @param array $definitions
 * @param string $table
 * @param array $columns
 */
function qa_db_upgrade_table_columns($definitions, $table, $columns)
{
	$sqlchanges = array();

	foreach ($columns as $column)
		$sqlchanges[] = 'CHANGE COLUMN ' . $column . ' ' . $column . ' ' . $definitions[$table][$column];

	qa_db_upgrade_query('ALTER TABLE ^' . $table . ' ' . implode(', ', $sqlchanges));
}


/**
 * Perform upgrade $query and output progress to the browser
 * @param string $query
 */
function qa_db_upgrade_query($query)
{
	qa_db_upgrade_progress('Running query: ' . qa_db_apply_sub($query, array()) . ' ...');
	qa_db_query_sub($query);
}


/**
 * Output $text to the browser (after converting to HTML) and do all we can to get it displayed
 * @param string $text
 */
function qa_db_upgrade_progress($text)
{
	echo qa_html($text) . str_repeat('    ', 1024) . "<br><br>\n";
	flush();
}
