<?php
/** 
*
* @package search
* @version $Id$
* @copyright (c) 2005 phpBB Group 
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* @ignore
*/
include_once($phpbb_root_path . 'includes/search/search.' . $phpEx);

/**
* fulltext_native_improved
* phpBB's own db driven fulltext search, version 2
* @package search
*/
class fulltext_native_improved extends search_backend
{
	var $stats;
	var $word_length = array();
	var $common_words = array();
	var $must_contain_ids = array();
	var $must_not_contain_ids = array();
	var $must_exclude_one_ids = array();

	/**
	* Initialises the fulltext_native_improved search backend with min/max word length and makes sure the UTF-8 normalizer is loaded.
	*
	* @param	boolean|string	$error	is passed by reference and should either be set to false on success or an error message on failure.
	*
	* @access	public
	*/
	function fulltext_native_improved(&$error)
	{
		global $phpbb_root_path, $phpEx, $config;

		$this->word_length = array('min' => $config['fulltext_native_min_chars'], 'max' => $config['fulltext_native_max_chars']);

		/**
		* Load the UTF tools
		*/
		if (!class_exists('utf_normalizer'))
		{
			include($phpbb_root_path . 'includes/utf/utf_normalizer.' . $phpEx);
		}
		if (!function_exists('utf8_strlen'))
		{
			include($phpbb_root_path . 'includes/utf/utf_tools.' . $phpEx);
		}


		$error = false;
	}

	/**
	* This function fills $this->split_words with the cleaned user search query.
	*
	* If $terms is 'any' then the words will be extracted from the search query
	* and combined with | inside brackets. They will afterwards be treated like
	* an standard search query.
	*
	* Then it analyses the query and fills the internal arrays $must_not_contain_ids,
	* $must_contain_ids and $must_exclude_one_ids which are later used by keyword_search().
	*
	* @param	string	$keywords	contains the search query string as entered by the user
	* @param	string	$terms		is either 'all' (use search query as entered, default words to 'must be contained in post')
	* 	or 'any' (find all posts containing at least one of the given words)
	* @return	boolean				false if no valid keywords were found and otherwise true
	*
	* @access	public
	*/
	function split_keywords($keywords, $terms)
	{
		global $db, $config, $user;

		// Clean up the query search
		$match = array(
			// Replace multiple spaces with a single space
			'#  +#',

			// Strip spaces after: +-|(
			'#([+\\-|(]) #',

			// Strip spaces before: |*(
			'# ([|*)])#'
		);

		$replace = array(
			' ',
			'$1',
			'$1'
		);

		$keywords = preg_replace($match, $replace, $this->cleanup($keywords, '+-|()*', $user->lang['ENCODING']));

		// $keywords input format: each word seperated by a space, words in a bracket are not seperated

		// the user wants to search for any word, convert the search query
		if ($terms == 'any')
		{
			$words = array();

			preg_match_all('#([^\\s+\\-|()]+)(?:$|[\\s+\\-|()])#', $keywords, $words);
			if (sizeof($words[1]))
			{
				$keywords = '(' . implode('|', $words[1]) . ')';
			}
		}

		// generate the split_words array shown to the user
		$this->split_words = explode(' ', $keywords);

		$exact_words = array();
		preg_match_all('#([^\\s+\\-|*()]+)(?:$|[\\s+\\-|()])#', $keywords, $exact_words);
		$exact_words = $exact_words[1];

		if (sizeof($exact_words))
		{
			// we can match exact words with one IN
			foreach ($exact_words as $i => $word)
			{
				$exact_words[$i] = '\'' . $db->sql_escape($word) . '\'';
			}

			$sql = 'SELECT word_id, word_text, word_common
				FROM ' . SEARCH_WORDLIST_TABLE . '
				WHERE word_text ' . ((sizeof($exact_words) > 1) ? 'IN (' . implode(', ', $exact_words) . ')' : '= ' . $exact_words[0]);
			$result = $db->sql_query($sql);
	
			// store an array of words and ids, remove common words
			while ($row = $db->sql_fetchrow($result))
			{
				if ($row['word_common'])
				{
					$this->ignore_words[] = $row['wort_text'];
					continue;
				}

				$words[$row['word_text']] = (int) $row['word_id'];
			}
			$db->sql_freeresult($result);
		}
		unset($exact_words);

		// now analyse the search query, first split it using the spaces
		$query = explode(' ', $keywords);

		$this->must_contain_ids = array();
		$this->must_not_contain_ids = array();
		$this->must_exclude_one_ids = array();

		$mode = '';
		$ignore_no_id = true;

		foreach ($query as $word)
		{
			if (empty($word))
			{
				continue;
			}

			// words which should not be included
			if ($word[0] == '-')
			{
				$word = substr($word, 1);

				// a group of which at least one may not be in the resulting posts
				if ($word[0] == '(')
				{
					$word = explode('|', substr($word, 1, -1));
					$mode = 'must_exclude_one';
				}
				// one word which should not be in the resulting posts
				else
				{
					$mode = 'must_not_contain';
				}
				$ignore_no_id = true;
			}
			// words which have to be included
			else
			{
				// no prefix is the same as a +prefix
				if ($word[0] == '+')
				{
					$word = substr($word, 1);
				}

				// a group of words of which at least one word should be in every resulting post
				if ($word[0] == '(')
				{
					$word = explode('|', substr($word, 1, -1));
				}
				$ignore_no_id = false;
				$mode = 'must_contain';
			}

			// if this is an array of words then retrieve an id for each
			if (is_array($word))
			{
				$id_words = array();
				foreach ($word as $i => $word_part)
				{
					if (strpos($word_part, '*') !== false)
					{
						$id_words[] = '\'' . $db->sql_escape(str_replace('*', '%', $word_part)) . '\'';
					}
					if (isset($words[$word_part]))
					{
						$id_words[] = $words[$word_part];
					}
				}
				if (sizeof($id_words))
				{
					sort($id_words);
					if (sizeof($id_words) > 1)
					{
						$this->{$mode . '_ids'}[] = $id_words;
					}
					else
					{
						$mode = ($mode == 'must_exclude_one') ? 'must_not_contain' : $mode;
						$this->{$mode . '_ids'}[] = $id_words[0];
					}
				}
				// throw an error if we shall not ignore unexistant words
				else if (!$ignore_no_id)
				{
					trigger_error(sprintf($user->lang['WORDS_IN_NO_POST'], implode(', ', $word)));
				}
			}
			// else we only need one id
			else if (($wildcard = strpos($word, '*') !== false) || isset($words[$word]))
			{
				if ($wildcard)
				{
					$this->{$mode . '_ids'}[] = '\'' . $db->sql_escape(str_replace('*', '%', $word)) . '\'';
				}
				else
				{
					$this->{$mode . '_ids'}[] = $words[$word];
				}
			}
			// throw an error if we shall not ignore unexistant words
			else if (!$ignore_no_id)
			{
				trigger_error(sprintf($user->lang['WORD_IN_NO_POST'], $word));
			}
		}

		// we can't search for negatives only
		if (!sizeof($this->must_contain_ids))
		{
			return false;
		}

		sort($this->must_contain_ids);
		sort($this->must_not_contain_ids);
		sort($this->must_exclude_one_ids);

		if (sizeof($this->split_words))
		{
			$this->split_words = array_values($this->split_words);
			return true;
		}
		return false;
	}

	/**
	* Performs a search on keywords depending on display specific params. You have to run split_keywords() first.
	*
	* @param	string		$type				contains either posts or topics depending on what should be searched for
	* @param	string		$fields				contains either titleonly (topic titles should be searched), msgonly (only message bodies should be searched), firstpost (only subject and body of the first post should be searched) or all (all post bodies and subjects should be searched)
	* @param	string		$terms				is either 'all' (use query as entered, words without prefix should default to "have to be in field") or 'any' (ignore search query parts and just return all posts that contain any of the specified words)
	* @param	array		$sort_by_sql		contains SQL code for the ORDER BY part of a query
	* @param	string		$sort_key			is the key of $sort_by_sql for the selected sorting
	* @param	string		$sort_dir			is either a or d representing ASC and DESC
	* @param	string		$sort_days			specifies the maximum amount of days a post may be old
	* @param	array		$ex_fid_ary			specifies an array of forum ids which should not be searched
	* @param	array		$m_approve_fid_ary	specifies an array of forum ids in which the searcher is allowed to view unapproved posts
	* @param	int			$topic_id			is set to 0 or a topic id, if it is not 0 then only posts in this topic should be searched
	* @param	array		$author_ary			an array of author ids if the author should be ignored during the search the array is empty
	* @param	array		$id_ary				passed by reference, to be filled with ids for the page specified by $start and $per_page, should be ordered
	* @param	int			$start				indicates the first index of the page
	* @param	int			$per_page			number of ids each page is supposed to contain
	* @return	boolean|int						total number of results
	*
	* @access	public
	*/
	function keyword_search($type, &$fields, &$terms, &$sort_by_sql, &$sort_key, &$sort_dir, &$sort_days, &$ex_fid_ary, &$m_approve_fid_ary, &$topic_id, &$author_ary, &$id_ary, $start, $per_page)
	{
		global $config, $db;

		// No keywords? No posts.
			if (!sizeof($this->split_words))
		{
			return false;
		}

		// generate a search_key from all the options to identify the results
		$search_key = md5(implode('#', array(
			serialize($this->must_contain_ids),
			serialize($this->must_not_contain_ids),
			serialize($this->must_exclude_one_ids),
			$type,
			$fields,
			$terms,
			$sort_days,
			$sort_key,
			$topic_id,
			implode(',', $ex_fid_ary),
			implode(',', $m_approve_fid_ary),
			implode(',', $author_ary)
		)));

		// try reading the results from cache
		$total_results = 0;
		if ($this->obtain_ids($search_key, $total_results, $id_ary, $start, $per_page, $sort_dir) == SEARCH_RESULT_IN_CACHE)
		{
			return $total_results;
		}

		$id_ary = array();

		$sql_where = array();
		$group_by = false;
		$m_num = 0;
		$w_num = 0;

		$sql_array = array(
			'SELECT'	=> ($type == 'posts') ? 'p.post_id' : 'p.topic_id',
			'FROM'		=> array(
				SEARCH_WORDMATCH_TABLE	=> array(),
				SEARCH_WORDLIST_TABLE	=> array(),
				POSTS_TABLE				=> 'p'
			),
			'LEFT_JOIN'	=> array()
		);
		$sql_where[] = 'm0.post_id = p.post_id';

		if ($type == 'topics')
		{
			$sql_array['FROM'][TOPICS_TABLE] = 't';
			$group_by = true;
		}

		$title_match = '';
		// Build some display specific sql strings
		switch ($fields)
		{
			case 'titleonly':
				$title_match = 'title_match = 1';
			// no break
			case 'firstpost':
				$sql_array['FROM'][TOPICS_TABLE] = 't';
				$sql_where[] = 'p.post_id = t.topic_first_post_id';
			break;

			case 'msgonly':
				$title_match = 'title_match = 0';
			break;
		}

		/**
		* @todo Add a query optimizer (handle stuff like "+(4|3) +4")
		*/

		foreach ($this->must_contain_ids as $subquery)
		{
			if (is_array($subquery))
			{
				$group_by = true;

				$word_id_sql = array();
				$word_ids = array();
				foreach ($subquery as $id)
				{
					if (is_string($id))
					{
						$sql_array['LEFT_JOIN'][] = array(
							'FROM'	=> array(SEARCH_WORDLIST_TABLE => 'w' . $w_num),
							'ON'	=> "w$w_num.word_text LIKE $id"
						);
						$word_ids[] = "w$w_num.word_id";
		
						$w_num++;
					}
					else
					{
						$word_ids[] = $id;
					}
				}

				$sql_where[] = (sizeof($word_ids) > 1) ? "m$m_num.word_id IN (" . implode(', ', $word_ids) . ')' : "m$m_num.word_id = {$word_ids[0]}";

				unset($word_id_sql);
				unset($word_ids);
			}
			else if (is_string($subquery))
			{
				$sql_array['FROM'][SEARCH_WORDLIST_TABLE][] = 'w' . $w_num;

				$sql_where[] = "w$w_num.word_text LIKE $subquery";
				$sql_where[] = "m$m_num.word_id = w$w_num.word_id";

				$group_by = true;
				$w_num++;
			}
			else
			{
				$sql_where[] = "m$m_num.word_id = $subquery";
			}
	
			$sql_array['FROM'][SEARCH_WORDMATCH_TABLE][] = 'm' . $m_num;

			if ($title_match)
			{
				$sql_where[] = "m$m_num.$title_match";
			}

			if ($m_num != 0)
			{
				$sql_where[] = "m$m_num.post_id = m0.post_id";
			}
			$m_num++;
		}

		foreach ($this->must_not_contain_ids as $key => $subquery)
		{
			if (is_string($subquery))
			{
				$sql_array['LEFT_JOIN'][] = array(
					'FROM'	=> array(SEARCH_WORDLIST_TABLE => 'w' . $w_num),
					'ON'	=> "w$w_num.word_text LIKE $subquery"
				);

				$this->must_not_contain_ids[$key] = "w$w_num.word_id";

				$group_by = true;
				$w_num++;
			}
		}

		if (sizeof($this->must_not_contain_ids))
		{
			$sql_array['LEFT_JOIN'][] = array(
				'FROM'	=> array(SEARCH_WORDMATCH_TABLE => 'm' . $m_num),
				'ON'	=> ((sizeof($this->must_not_contain_ids) > 1) ? "m$m_num.word_id IN (" . implode(', ', $this->must_not_contain_ids) . ')' : "m$m_num.word_id = " . $this->must_not_contain_ids[0]) . (($title_match) ? "m$m_num.$title_match" : '') . " AND m$m_num.post_id = m0.post_id"
			);

			$sql_where[] = "m$m_num.word_id IS NULL";
			$m_num++;
		}

		foreach ($this->must_exclude_one_ids as $ids)
		{
			$is_null_joins = array();
			foreach ($ids as $id)
			{
				if (is_string($id))
				{
					$sql_array['LEFT_JOIN'][] = array(
						'FROM'	=> array(SEARCH_WORDLIST_TABLE => 'w' . $w_num),
						'ON'	=> "w$w_num.word_text LIKE $id"
					);
					$id = "w$w_num.word_id";

					$group_by = true;
					$w_num++;
				}

				$sql_array['LEFT_JOIN'][] = array(
					'FROM'	=> array(SEARCH_WORDMATCH_TABLE => 'm' . $m_num),
					'ON'	=> "m$m_num.word_id = $id AND m$m_num.post_id = m0.post_id" . (($title_match) ? "m$m_num.$title_match" : '')
				);
				$is_null_joins[] = "m$m_num.word_id IS NULL";

				$m_num++;
			}
			$sql_where[] = '(' . implode(' OR ', $is_null_joins) . ')';
		}

		if (!sizeof($m_approve_fid_ary))
		{
			$sql_where[] = 'p.post_approved = 1';
		}
		else if ($m_approve_fid_ary !== array(-1))
		{
			$sql_where[] = '(p.post_approved = 1 OR p.forum_id ' . ((sizeof($m_approve_fid_ary) == 1) ? '= ' . $m_approve_fid_ary[0] : 'NOT IN (' . implode(', ', $m_approve_fid_ary) . ')' ) . ')';
		}

		if ($topic_id)
		{
			$sql_where[] = 'p.topic_id = ' . $topic_id;
		}

		if (sizeof($author_ary))
		{
			$sql_where[] = 'p.poster_id ' . ((sizeof($author_ary) == 1) ? ' = ' . $author_ary[0] : 'IN (' . implode(',', $author_ary) . ')');
		}

		if (sizeof($ex_fid_ary))
		{
			$sql_where[] = 'p.forum_id ' . ((sizeof($ex_fid_ary) == 1) ? '<> ' . $ex_fid_ary[0] : 'NOT IN (' . implode(',', $ex_fid_ary) . ')');
		}

		if ($sort_days)
		{
			$sql_where[] = 'p.post_time >= ' . (time() - ($sort_days * 86400));
		}

		$sql_array['WHERE'] = implode(' AND ', $sql_where);

		$is_mysql = false;
		// if the total result count is not cached yet, retrieve it from the db
		if (!$total_results)
		{
			$sql = '';
			$sql_array_count = $sql_array;

			switch (SQL_LAYER)
			{
				case 'mysql':
				case 'mysql4':
				case 'mysqli':
					$sql_array['SELECT'] = 'SQL_CALC_FOUND_ROWS ' . $sql_array['SELECT'];
					$is_mysql = true;
				break;

				case 'sqlite':
					$sql_array_count['SELECT'] = ($type == 'posts') ? 'DISTINCT p.post_id' : 'DISTINCT p.topic_id';
					$sql = 'SELECT COUNT(' . (($type == 'posts') ? 'post_id' : 'topic_id') . ') as total_results
							FROM (' . $db->sql_build_query('SELECT', $sql_array_count) . ')';
				// no break
				default:
					$sql_array_count['SELECT'] = ($type == 'posts') ? 'COUNT(DISTINCT p.post_id) AS total_results' : 'COUNT(DISTINCT p.topic_id) AS total_results';
					$sql = (!$sql) ? $db->sql_build_query('SELECT', $sql_array_count) : $sql;
		
					$result = $db->sql_query($sql);
					$total_results = (int) $db->sql_fetchfield('total_results');
					$db->sql_freeresult($result);
		
					if (!$total_results)
					{
						return false;
					}
				break;
			}

			unset($sql_array_count, $sql);
		}

		// Build sql strings for sorting
		$sql_sort = $sort_by_sql[$sort_key] . (($sort_dir == 'a') ? ' ASC' : ' DESC');

		switch ($sql_sort[0])
		{
			case 'u':
				$sql_array['FROM'][USERS_TABLE] = 'u';
				$sql_where[] = 'u.user_id = p.poster_id ';
			break;

			case 't':
				if (!isset($sql_array['FROM'][TOPICS_TABLE]))
				{
					$sql_array['FROM'][TOPICS_TABLE] = 't';
					$sql_where[] = 'p.topic_id = t.topic_id';
				}
			break;

			case 'f':
				$sql_array['FROM'][FORUMS_TABLE] = 'f';
				$sql_where[] = 'f.forum_id = p.forum_id';
			break;
		}

		$sql_array['WHERE'] = implode(' AND ', $sql_where);
		$sql_array['GROUP_BY'] = ($group_by) ? (($type == 'posts') ? 'p.post_id' : 'p.topic_id') . ', ' . $sort_by_sql[$sort_key] : '';
		$sql_array['ORDER_BY'] = $sql_sort;

		unset($sql_where, $sql_sort, $group_by);

		$sql = $db->sql_build_query('SELECT', $sql_array);
		$result = $db->sql_query_limit($sql, $config['search_block_size'], $start);

		while ($row = $db->sql_fetchrow($result))
		{
			$id_ary[] = $row[(($type == 'posts') ? 'post_id' : 'topic_id')];
		}
		$db->sql_freeresult($result);

		if (!sizeof($id_ary))
		{
			return false;
		}

		// if we use mysql and the total result count is not cached yet, retrieve it from the db
		if (!$total_results && $is_mysql)
		{
			$sql = 'SELECT FOUND_ROWS() as total_results';
			$result = $db->sql_query($sql);
			$total_results = (int) $db->sql_fetchfield('total_results');
			$db->sql_freeresult($result);

			if (!$total_results)
			{
				return false;
			}
		}

		// store the ids, from start on then delete anything that isn't on the current page because we only need ids for one page
		$this->save_ids($search_key, implode(' ', $this->split_words), $author_ary, $total_results, $id_ary, $start, $sort_dir);
		$id_ary = array_slice($id_ary, 0, (int) $per_page);

		return $total_results;
	}

	/**
	* Performs a search on an author's posts without caring about message contents. Depends on display specific params
	*
	* @param	string		$type				contains either posts or topics depending on what should be searched for
	* @param	array		$sort_by_sql		contains SQL code for the ORDER BY part of a query
	* @param	string		$sort_key			is the key of $sort_by_sql for the selected sorting
	* @param	string		$sort_dir			is either a or d representing ASC and DESC
	* @param	string		$sort_days			specifies the maximum amount of days a post may be old
	* @param	array		$ex_fid_ary			specifies an array of forum ids which should not be searched
	* @param	array		$m_approve_fid_ary	specifies an array of forum ids in which the searcher is allowed to view unapproved posts
	* @param	int			$topic_id			is set to 0 or a topic id, if it is not 0 then only posts in this topic should be searched
	* @param	array		$author_ary			an array of author ids
	* @param	array		$id_ary				passed by reference, to be filled with ids for the page specified by $start and $per_page, should be ordered
	* @param	int			$start				indicates the first index of the page
	* @param	int			$per_page			number of ids each page is supposed to contain
	* @return	boolean|int						total number of results
	*
	* @access	public
	*/
	function author_search($type, &$sort_by_sql, &$sort_key, &$sort_dir, &$sort_days, &$ex_fid_ary, &$m_approve_fid_ary, &$topic_id, &$author_ary, &$id_ary, $start, $per_page)
	{
		global $config, $db;

		// No author? No posts.
		if (!sizeof($author_ary))
		{
			return 0;
		}

		// generate a search_key from all the options to identify the results
		$search_key = md5(implode('#', array(
			'',
			$type,
			'',
			'',
			$sort_days,
			$sort_key,
			$topic_id,
			implode(',', $ex_fid_ary),
			implode(',', $m_approve_fid_ary),
			implode(',', $author_ary)
		)));

		// try reading the results from cache
		$total_results = 0;
		if ($this->obtain_ids($search_key, $total_results, $id_ary, $start, $per_page, $sort_dir) == SEARCH_RESULT_IN_CACHE)
		{
			return $total_results;
		}

		$id_ary = array();

		// Create some display specific sql strings
		$sql_author		= 'p.poster_id ' . ((sizeof($author_ary) > 1) ? 'IN (' . implode(',', $author_ary) . ')' : '= ' . $author_ary[0]);
		$sql_fora		= (sizeof($ex_fid_ary)) ? ' AND p.forum_id ' . ((sizeof($ex_fid_ary) == 1) ? '<> ' . $ex_fid_ary[0] : 'NOT IN (' . implode(',', $ex_fid_ary) . ')') : '';
		$sql_time		= ($sort_days) ? ' AND p.post_time >= ' . (time() - ($sort_days * 86400)) : '';
		$sql_topic_id	= ($topic_id) ? ' AND p.topic_id = ' . (int) $topic_id : '';

		// Build sql strings for sorting
		$sql_sort = $sort_by_sql[$sort_key] . (($sort_dir == 'a') ? ' ASC' : ' DESC');
		$sql_sort_table = $sql_sort_join = '';
		switch ($sql_sort[0])
		{
			case 'u':
				$sql_sort_table	= USERS_TABLE . ' u, ';
				$sql_sort_join	= ' AND u.user_id = p.poster_id ';
			break;

			case 't':
				$sql_sort_table	= ($type == 'posts') ? TOPICS_TABLE . ' t, ' : '';
				$sql_sort_join	= ($type == 'posts') ? ' AND t.topic_id = p.topic_id ' : '';
			break;

			case 'f':
				$sql_sort_table	= FORUMS_TABLE . ' f, ';
				$sql_sort_join	= ' AND f.forum_id = p.forum_id ';
			break;
		}

		if (!sizeof($m_approve_fid_ary))
		{
			$m_approve_fid_sql = ' AND p.post_approved = 1';
		}
		else if ($m_approve_fid_ary == array(-1))
		{
			$m_approve_fid_sql = '';
		}
		else
		{
			$m_approve_fid_sql = ' AND (p.post_approved = 1 OR p.forum_id ' . ((sizeof($m_approve_fid_ary) == 1) ? '= ' . $m_approve_fid_ary[0] : 'IN (' . implode($m_approve_fid_ary) . ')' ) . ')';
		}

		$select = ($type == 'posts') ? 'p.post_id' : 't.topic_id';
		$is_mysql = false;

		// If the cache was completely empty count the results
		if (!$total_results)
		{
			switch (SQL_LAYER)
			{
				case 'mysql':
				case 'mysql4':
				case 'mysqli':
					$select = 'SQL_CALC_FOUND_ROWS ' . $select;
					$is_mysql = true;
				break;

				default:
					if ($type == 'posts')
					{
						$sql = 'SELECT COUNT(p.post_id) as total_results
							FROM ' . POSTS_TABLE . " p
							WHERE $sql_author
								$sql_topic_id
								$m_approve_fid_sql
								$sql_fora
								$sql_time";
					}
					else
					{
						if (SQL_LAYER == 'sqlite')
						{
							$sql = 'SELECT COUNT(topic_id) as total_results
								FROM (SELECT DISTINCT t.topic_id';
						}
						else
						{
							$sql = 'SELECT COUNT(DISTINCT t.topic_id) as total_results';
						}

						$sql .= 'FROM ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . " p
							WHERE $sql_author
								$sql_topic_id
								$m_approve_fid_sql
								$sql_fora
								AND t.topic_id = p.topic_id
								$sql_time" . ((SQL_LAYER == 'sqlite') ? ')' : '');
					}
					$result = $db->sql_query($sql);
		
					$total_results = (int) $db->sql_fetchfield('total_results');
					$db->sql_freeresult($result);
		
					if (!$total_results)
					{
						return false;
					}
				break;
			}
		}

		// Build the query for really selecting the post_ids
		if ($type == 'posts')
		{
			$sql = "SELECT $select
				FROM " . $sql_sort_table . POSTS_TABLE . ' p' . (($topic_id) ? ', ' . TOPICS_TABLE . ' t' : '') . "
				WHERE $sql_author
					$sql_topic_id
					$m_approve_fid_sql
					$sql_fora
					$sql_sort_join
					$sql_time
				ORDER BY $sql_sort";
			$field = 'post_id';
		}
		else
		{
			$sql = "SELECT $select
				FROM " . $sql_sort_table . TOPICS_TABLE . ' t, ' . POSTS_TABLE . " p
				WHERE $sql_author
					$sql_topic_id
					$m_approve_fid_sql
					$sql_fora
					AND t.topic_id = p.topic_id
					$sql_sort_join
					$sql_time
				GROUP BY t.topic_id, " . $sort_by_sql[$sort_key] . '
				ORDER BY ' . $sql_sort;
			$field = 'topic_id';
		}

		// Only read one block of posts from the db and then cache it
		$result = $db->sql_query_limit($sql, $config['search_block_size'], $start);

		while ($row = $db->sql_fetchrow($result))
		{
			$id_ary[] = $row[$field];
		}
		$db->sql_freeresult($result);

		if (!$total_results && $is_mysql)
		{
			$sql = 'SELECT FOUND_ROWS() as total_results';
			$result = $db->sql_query($sql);
			$total_results = (int) $db->sql_fetchfield('total_results');
			$db->sql_freeresult($result);

			if (!$total_results)
			{
				return false;
			}
		}

		if (sizeof($id_ary))
		{
			$this->save_ids($search_key, '', $author_ary, $total_results, $id_ary, $start, $sort_dir);
			$id_ary = array_slice($id_ary, 0, $per_page);

			return $total_results;
		}
		return false;
	}

	/**
	* Split a text into words of a given length
	*
	* The text is converted to UTF-8, cleaned up, and split. Then, words that
	* conform to the defined length range are returned in an array.
	*
	* NOTE: duplicates are NOT removed from the return array
	*
	* @param	string	$text	Text to split, encoded in user's encoding
	* @return	array			Array of UTF-8 words
	*
	* @access	private
	*/
	function split_message($text)
	{
		global $phpbb_root_path, $phpEx;
		global $config, $user;

		$match = $words = array();

		/**
		* Taken from the original code
		*/
		// Do not index code
		$match[] = '#\[code(?:=.*?)?(\:?[0-9a-z]{5,})\].*?\[\/code(\:?[0-9a-z]{5,})\]#is';
		// BBcode
		$match[] = '#\[\/?[a-z\*\+\-]+(?:=.*?)?(\:?[0-9a-z]{5,})\]#';

		$min = $config['fulltext_native_min_chars'];
		$max = $config['fulltext_native_max_chars'];

		$isset_min = $min - 1;

		/**
		* Clean up the string, remove HTML tags, remove BBCodes
		*/
		$word = strtok($this->cleanup(preg_replace($match, ' ', strip_tags($text)), '', $user->lang['ENCODING']), ' ');

		while (isset($word[0]))
		{
			if (isset($word[252])
			 || !isset($word[$isset_min]))
			{
				/**
				* Words longer than 252 bytes are ignored. This will have to be
				* changed whenever we change the length of search_wordlist.word_text
				*
				* Words shorter than $isset_min bytes are ignored, too
				*/
				$word = strtok(' ');
				continue;
			}

			$len = utf8_strlen($word);

			/**
			* Test whether the word is too short to be indexed.
			*
			* Note that this limit does NOT apply to CJK and Hangul
			*/
			if ($len < $min)
			{
				/**
				* Note: this could be optimized. If the codepoint is lower than Hangul's range
				* we know that it will also be lower than CJK ranges
				*/
				if ((strncmp($word, UTF8_HANGUL_FIRST, 3) < 0 || strncmp($word, UTF8_HANGUL_LAST, 3) > 0)
				 && (strncmp($word, UTF8_CJK_FIRST, 3) < 0 || strncmp($word, UTF8_CJK_LAST, 3) > 0)
				 && (strncmp($word, UTF8_CJK_B_FIRST, 4) < 0 || strncmp($word, UTF8_CJK_B_LAST, 4) > 0))
				{
					$word = strtok(' ');
					continue;
				}
			}

			$words[] = $word;
			$word = strtok(' ');
		}

		return $words;
	}

	/**
	* Updates wordlist and wordmatch tables when a message is posted or changed
	*
	* @param string $mode contains the post mode: edit, post, reply, quote ...
	*/
	function index($mode, $post_id, &$message, &$subject, $encoding, $poster_id, $forum_id)
	{
		global $config, $db, $user;

		if (!$config['fulltext_native_load_upd'])
		{
			/**
			* The search indexer is disabled, return
			*/
			return;
		}

		// Split old and new post/subject to obtain array of 'words'
		$split_text = $this->split_message($message);
		$split_title = $this->split_message($subject);

		$cur_words = array('post' => array(), 'title' => array());

		$words = array();
		if ($mode == 'edit')
		{
			$words['add']['post'] = array();
			$words['add']['title'] = array();
			$words['del']['post'] = array();
			$words['del']['title'] = array();

			$sql = 'SELECT w.word_id, w.word_text, m.title_match
				FROM ' . SEARCH_WORDLIST_TABLE . ' w, ' . SEARCH_WORDMATCH_TABLE . " m
				WHERE m.post_id = $post_id
					AND w.word_id = m.word_id";
			$result = $db->sql_query($sql);

			while ($row = $db->sql_fetchrow($result))
			{
				$which = ($row['title_match']) ? 'title' : 'post';
				$cur_words[$which][$row['word_text']] = $row['word_id'];
			}
			$db->sql_freeresult($result);

			$words['add']['post'] = array_diff($split_text, array_keys($cur_words['post']));
			$words['add']['title'] = array_diff($split_title, array_keys($cur_words['title']));
			$words['del']['post'] = array_diff(array_keys($cur_words['post']), $split_text);
			$words['del']['title'] = array_diff(array_keys($cur_words['title']), $split_title);
		}
		else
		{
			$words['add']['post'] = $split_text;
			$words['add']['title'] = $split_title;
			$words['del']['post'] = array();
			$words['del']['title'] = array();
		}
		unset($split_text);
		unset($split_title);

		// Get unique words from the above arrays
		$unique_add_words = array_unique(array_merge($words['add']['post'], $words['add']['title']));

		// We now have unique arrays of all words to be added and removed and
		// individual arrays of added and removed words for text and title. What
		// we need to do now is add the new words (if they don't already exist)
		// and then add (or remove) matches between the words and this post
		if (sizeof($unique_add_words))
		{
			$sql = 'SELECT word_id, word_text
				FROM ' . SEARCH_WORDLIST_TABLE . "
				WHERE word_text IN ('" . implode("','", array_map(array(&$db, 'sql_escape'), $unique_add_words)) . "')";
			$result = $db->sql_query($sql);

			$word_ids = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$word_ids[$row['word_text']] = $row['word_id'];
			}
			$db->sql_freeresult($result);

			$new_words = array_map(array(&$db, 'sql_escape'), array_diff($unique_add_words, array_keys($word_ids)));

			if (sizeof($new_words))
			{
				switch (SQL_LAYER)
				{
					case 'mysql':
					case 'mysql4':
					case 'mysqli':
						$sql = 'INSERT INTO ' . SEARCH_WORDLIST_TABLE . " (word_text)
							VALUES ('" . implode("'),('", array_map(array(&$db, 'sql_escape'), $new_words)) . "')";
						$db->sql_query($sql);
					break;

					default:
						foreach ($new_words as $word)
						{
							$sql = 'INSERT INTO ' . SEARCH_WORDLIST_TABLE . " (word_text)
								VALUES ('" . $db->sql_escape($word) . "')";
							$db->sql_query($sql);
						}
				}
			}
			unset($new_words);
		}

		// now update the search match table, remove links to removed words and add links to new words
		foreach ($words['del'] as $word_in => $word_ary)
		{
			$title_match = ($word_in == 'title') ? 1 : 0;

			if (sizeof($word_ary))
			{
				$sql_in = array();
				foreach ($word_ary as $word)
				{
					$sql_in[] = $cur_words[$word_in][$word];
				}

				$sql = 'DELETE FROM ' . SEARCH_WORDMATCH_TABLE . '
					WHERE word_id IN (' . implode(', ', $sql_in) . ')
						AND post_id = ' . intval($post_id) . "
						AND title_match = $title_match";
				$db->sql_query($sql);
				unset($sql_in);
			}
		}

		foreach ($words['add'] as $word_in => $word_ary)
		{
			$title_match = ($word_in == 'title') ? 1 : 0;

			if (sizeof($word_ary))
			{
				$sql = 'INSERT INTO ' . SEARCH_WORDMATCH_TABLE . " (post_id, word_id, title_match)
					SELECT $post_id, word_id, $title_match
					FROM " . SEARCH_WORDLIST_TABLE . "
					WHERE word_text IN ('" . implode("','", array_map(array(&$db, 'sql_escape'), $word_ary)) . "')";
				$db->sql_query($sql);
			}
		}

		// destroy cached search results containing any of the words removed or added
		$this->destroy_cache(array_unique(array_merge($words['add']['post'], $words['add']['title'], $words['del']['post'], $words['del']['post'])), array($poster_id));

		unset($unique_add_words);
		unset($words);
		unset($cur_words);
	}

	/**
	* Used by index() to sort strings by string length, longest first
	*/
	function strlencmp($a, $b)
	{
		$len_a = strlen($a);
		$len_b = strlen($b);

		if ($len_a == $len_b)
		{
			return 0;
		}

		return ($len_a > $len_b) ? -1 : 1;
	}

	/**
	* Removes entries from the wordmatch table for the specified post_ids
	*/
	function index_remove($post_ids, $author_ids, $forum_ids)
	{
		global $db;

		$sql = 'DELETE FROM ' . SEARCH_WORDMATCH_TABLE . '
			WHERE post_id IN (' . implode(', ', $post_ids) . ')';
		$db->sql_query($sql);

		// SEARCH_WORDLIST_TABLE will be updated by tidy()

		$this->destroy_cache(array(), $author_ids);
	}

	/**
	* Tidy up indexes: Tag 'common words' and remove
	* words no longer referenced in the match table
	*/
	function tidy()
	{
		global $db, $config;

		// Is the fulltext indexer disabled? If yes then we need not
		// carry on ... it's okay ... I know when I'm not wanted boo hoo
		if (!$config['fulltext_native_load_upd'])
		{
			set_config('search_last_gc', time(), true);
			return;
		}

		$destroy_cache_words = array();

		// Remove common (> 60% of posts ) words
		if ($config['num_posts'] >= 100)
		{
			// First, get the IDs of common words
			$sql = 'SELECT word_id
				FROM ' . SEARCH_WORDMATCH_TABLE . '
				GROUP BY word_id
				HAVING COUNT(word_id) > ' . floor($config['num_posts'] * 0.6);
			$result = $db->sql_query($sql);

			if ($row = $db->sql_fetchrow($result))
			{
				$sql_in = array();
				do
				{
					$sql_in[] = $row['word_id'];
				}
				while ($row = $db->sql_fetchrow($result));

				$sql_in = implode(', ', $sql_in);

				// Get the text of those new common words
				$sql = 'SELECT word_text
					FROM ' . SEARCH_WORDLIST_TABLE . "
					WHERE word_id IN ($sql_in)";
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$destroy_cache_words[] = $row['word_text'];
				}

				// Flag the words
				$sql = 'UPDATE ' . SEARCH_WORDLIST_TABLE . "
					SET word_common = 1
					WHERE word_id IN ($sql_in)";
				$db->sql_query($sql);

				// Delete the matches
				$sql = 'DELETE FROM ' . SEARCH_WORDMATCH_TABLE . "
					WHERE word_id IN ($sql_in)";
				$db->sql_query($sql);

				unset($sql_in);
			}
			$db->sql_freeresult($result);
		}

		// destroy cached search results containing any of the words that are now common or were removed
		$this->destroy_cache(array_unique($destroy_cache_words));

		set_config('search_last_gc', time(), true);
	}

	/**
	* Deletes all words from the index
	*/
	function delete_index($acp_module, $u_action)
	{
		global $db;

		$db->sql_query(((SQL_LAYER != 'sqlite') ? 'TRUNCATE TABLE ' : 'DELETE FROM ') . SEARCH_WORDLIST_TABLE);
		$db->sql_query(((SQL_LAYER != 'sqlite') ? 'TRUNCATE TABLE ' : 'DELETE FROM ') . SEARCH_WORDMATCH_TABLE);
		$db->sql_query(((SQL_LAYER != 'sqlite') ? 'TRUNCATE TABLE ' : 'DELETE FROM ') . SEARCH_RESULTS_TABLE);
	}

	/**
	* Returns true if both FULLTEXT indexes exist
	*/
	function index_created()
	{
		if (!is_array($this->stats))
		{
			$this->get_stats();
		}

		return ($this->stats['total_words'] && $this->stats['total_matches']) ? true : false;
	}

	/**
	* Returns an associative array containing information about the indexes
	*/
	function index_stats()
	{
		global $user;

		if (!is_array($this->stats))
		{
			$this->get_stats();
		}

		return array(
			$user->lang['TOTAL_WORDS']		=> $this->stats['total_words'],
			$user->lang['TOTAL_MATCHES']	=> $this->stats['total_matches']);
	}

	function get_stats()
	{
		global $db;

		$sql = 'SELECT COUNT(*) as total_words
			FROM ' . SEARCH_WORDLIST_TABLE;
		$result = $db->sql_query($sql);
		$this->stats['total_words'] = (int) $db->sql_fetchfield('total_words');
		$db->sql_freeresult($result);

		$sql = 'SELECT COUNT(*) as total_matches
			FROM ' . SEARCH_WORDMATCH_TABLE;
		$result = $db->sql_query($sql);
		$this->stats['total_matches'] = (int) $db->sql_fetchfield('total_matches');
		$db->sql_freeresult($result);
	}

	/**
	* Clean up a text to remove non-alphanumeric characters
	*
	* This method receives a UTF-8 string, normalizes and validates it, replaces all
	* non-alphanumeric characters with strings then returns the result.
	*
	* Any number of "allowed chars" can be passed as a UTF-8 string in NFC.
	*
	* @param	string	$text			Text to split, in UTF-8 (not normalized or sanitized)
	* @param	string	$allowed_chars	String of special chars to allow
	* @param	string	$encoding		Text encoding
	* @return	string					Cleaned up text, only alphanumeric chars are left
	*/
	function cleanup($text, $allowed_chars = null, $encoding = 'iso-8859-1')
	{
		global $phpbb_root_path, $phpEx;
		static $conv = array(), $conv_loaded = array();
		$words = $allow = array();

		/**
		* Convert the text to UTF-8
		*/
		$encoding = strtolower($encoding);
		if ($encoding != 'utf-8')
		{
			$text = utf8_recode($text, $encoding);
		}

		$utf_len_mask = array(
			"\xC0"	=>	2,
			"\xD0"	=>	2,
			"\xE0"	=>	3,
			"\xF0"	=>	4
		);

		/**
		* Replace HTML entities and NCRs
		*/
		$text = html_entity_decode(utf8_decode_ncr($text), ENT_QUOTES);

		/**
		* Load the UTF-8 normalizer
		*
		* If we use it more widely, an instance of that class should be held in a
		* a global variable instead
		*/
		$text = utf_normalizer::nfc($text);

		/**
		* The first thing we do is:
		*
		* - convert ASCII-7 letters to lowercase
		* - remove the ASCII-7 non-alpha characters
		* - remove the bytes that should not appear in a valid UTF-8 string: 0xC0,
		*   0xC1 and 0xF5-0xFF
		*
		* @todo in theory, the third one is already taken care of during normalization and those chars should have been replaced by Unicode replacement chars
		*/
		$sb_match	= "ISTCPAMELRDOJBNHFGVWUQKYXZ\r\n\t!\"#$%&'()*+,-./:;<=>?@[\\]^_`{|}~\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\xC0\xC1\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";
		$sb_replace	= 'istcpamelrdojbnhfgvwuqkyxz                                                                              ';

		/**
		* This is the list of legal ASCII chars, it is automatically extended
		* with ASCII chars from $allowed_chars
		*/
		$legal_ascii = ' eaisntroludcpmghbfvq10xy2j9kw354867z';

		/**
		* Prepare an array containing the extra chars to allow
		*/
		if (isset($allowed_chars[0]))
		{
			$pos = 0;
			$len = strlen($allowed_chars);
			do
			{
				$c = $allowed_chars[$pos];

				if ($c < "\x80")
				{
					/**
					* ASCII char
					*/
					$sb_pos = strpos($sb_match, $c);
					if (is_int($sb_pos))
					{
						/**
						* Remove the char from $sb_match and its corresponding
						* replacement in $sb_replace
						*/
						$sb_match = substr($sb_match, 0, $sb_pos) . substr($sb_match, $sb_pos + 1);
						$sb_replace = substr($sb_replace, 0, $sb_pos) . substr($sb_replace, $sb_pos + 1);
						$legal_ascii .= $c;
					}

					++$pos;
				}
				else
				{
					/**
					* UTF-8 char
					*/
					$utf_len = $utf_len_mask[$c & "\xF0"];
					$allow[substr($allowed_chars, $pos, $utf_len)] = 1;
					$pos += $utf_len;
				}
			}
			while ($pos < $len);
		}

		$text = strtr($text, $sb_match, $sb_replace);
		$ret = '';

		$pos = 0;
		$len = strlen($text);

		do
		{
			/**
			* Do all consecutive ASCII chars at once
			*/
			if ($spn = strspn($text, $legal_ascii, $pos))
			{
				$ret .= substr($text, $pos, $spn);
				$pos += $spn;
			}

			if ($pos >= $len)
			{
				return $ret;
			}

			/**
			* Capture the UTF char
			*/
			$utf_len = $utf_len_mask[$text[$pos] & "\xF0"];
			$utf_char = substr($text, $pos, $utf_len);
			$pos += $utf_len;

			if (($utf_char >= UTF8_HANGUL_FIRST && $utf_char <= UTF8_HANGUL_LAST)
			 || ($utf_char >= UTF8_CJK_FIRST && $utf_char <= UTF8_CJK_LAST)
			 || ($utf_char >= UTF8_CJK_B_FIRST && $utf_char <= UTF8_CJK_B_LAST))
			{
				/**
				* All characters within these ranges are valid
				*
				* We separate them with a space in order to index each character
				* individually
				*/
				$ret .= ' ' . $utf_char . ' ';
				continue;
			}

			if (isset($allow[$utf_char]))
			{
				/**
				* The char is explicitly allowed
				*/
				$ret .= $utf_char;
				continue;
			}

			if (isset($conv[$utf_char]))
			{
				/**
				* The char is mapped to something, maybe to itself actually
				*/
				$ret .= $conv[$utf_char];
				continue;
			}

			/**
			* The char isn't mapped, but did we load its conversion table?
			*
			* The search indexer table is split into blocks. The block number of
			* each char is equal to its codepoint right-shifted for 11 bits. It
			* means that out of the 11, 16 or 21 meaningful bits of a 2-, 3- or
			* 4- byte sequence we only keep the leftmost 0, 5 or 10 bits. Thus,
			* all UTF chars encoded in 2 bytes are in the same first block.
			*/
			if (isset($utf_char[2]))
			{
				if (isset($utf_char[3]))
				{
					/**
					* 1111 0nnn 10nn nnnn 10nx xxxx 10xx xxxx
					* 0000 0111 0011 1111 0010 0000
					*/
					$idx = ((ord($utf_char[0]) & 0x07) << 7) | ((ord($utf_char[1]) & 0x3F) << 1) | ((ord($utf_char[2]) & 0x20) >> 5);
				}
				else
				{
					/**
					* 1110 nnnn 10nx xxxx 10xx xxxx
					* 0000 0111 0010 0000
					*/
					$idx = ((ord($utf_char[0]) & 0x07) << 1) | ((ord($utf_char[1]) & 0x20) >> 5);
				}
			}
			else
			{
				/**
				* 110x xxxx 10xx xxxx
				* 0000 0000 0000 0000
				*/
				$idx = 0;
			}

			/**
			* Check if the required conv table has been loaded already
			*/
			if (!isset($conv_loaded[$idx]))
			{
				$conv_loaded[$idx] = 1;
				$file = $phpbb_root_path . 'includes/utf/data/search_indexer_' . $idx . '.' . $phpEx;

				if (file_exists($file))
				{
					$conv += include($file);
				}
			}

			if (isset($conv[$utf_char]))
			{
				$ret .= $conv[$utf_char];
			}
			else
			{
				/**
				* We add an entry to the conversion table so that we
				* don't have to convert to codepoint and perform the checks
				* that are above this block
				*/
				$conv[$utf_char] = ' ';
				$ret .= ' ';
			}
		}
		while (1);

		return $ret;
	}

	/**
	* Returns a list of options for the ACP to display
	*/
	function acp()
	{
		global $user, $config;


		/**
		* if we need any options, copied from fulltext_native for now, will have to be adjusted or removed
		*/

		$tpl = '
		<dl>
			<dt><label for="fulltext_native_load_upd">' . $user->lang['YES_SEARCH_UPDATE'] . ':</label><br /><span>' . $user->lang['YES_SEARCH_UPDATE_EXPLAIN'] . '</span></dt>
			<dd><input type="radio" id="fulltext_native_load_upd" name="config[fulltext_native_load_upd]" value="1"' . (($config['fulltext_native_load_upd']) ? ' checked="checked"' : '') . ' class="radio" />&nbsp;' . $user->lang['YES'] . '&nbsp;&nbsp;<input type="radio" name="config[fulltext_native_load_upd]" value="0"' . ((!$config['fulltext_native_load_upd']) ? ' checked="checked"' : '') . ' class="radio" />&nbsp;' . $user->lang['NO'] . '</dd>
		</dl>
		<dl>
			<dt><label for="fulltext_native_min_chars">' . $user->lang['MIN_SEARCH_CHARS'] . ':</label><br /><span>' . $user->lang['MIN_SEARCH_CHARS_EXPLAIN'] . '</span></dt>
			<dd><input id="fulltext_native_min_chars" type="text" size="3" maxlength="3" name="config[fulltext_native_min_chars]" value="' . (int) $config['fulltext_native_min_chars'] . '" /></dd>
		</dl>
		<dl>
			<dt><label for="fulltext_native_max_chars">' . $user->lang['MAX_SEARCH_CHARS'] . ':</label><br /><span>' . $user->lang['MAX_SEARCH_CHARS_EXPLAIN'] . '</span></dt>
			<dd><input id="fulltext_native_max_chars" type="text" size="3" maxlength="3" name="config[fulltext_native_max_chars]" value="' . (int) $config['fulltext_native_max_chars'] . '" /></dd>
		</dl>
		';

		// These are fields required in the config table
		return array(
			'tpl'		=> $tpl,
			'config'	=> array('fulltext_native_load_upd' => 'bool', 'fulltext_native_min_chars' => 'integer:0:252', 'fulltext_native_max_chars' => 'integer:0:252')
		);
	}
}

?>