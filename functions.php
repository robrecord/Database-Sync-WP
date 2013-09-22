<?php

/**
 * Get secret or generate one if none is found
 * @return string Secret key
 */
function dbs_getSecret() {
	$key = get_option('outlandish_sync_secret');
	if (!$key) {
		$key = '';
		$length = 16;
		while ($length--) {
			$key .= chr(mt_rand(33, 126));
		}
		update_option('outlandish_sync_secret', $key);
	}

	return $key;
}

/**
 * @return string Encoded secret+URL token
 */
function dbs_getToken() {
	return trim(base64_encode(dbs_getSecret() . ' ' . get_bloginfo('wpurl')), '=');
}

/**
 * @param $url
 * @return string $url with leading http:// stripped
 */
function dbs_stripHttp($url) {
	return preg_replace('|^https?://|', '', $url);
}

/**
 * Load a series of SQL statements.
 * @param $sql string SQL dump
 */
function dbs_loadSql( &$sql ) {

	dbs_sql_remove_comments($sql);
	if ($_REQUEST['table_prefix'])
		dbs_sql_localize_tables($sql, $_REQUEST['table_prefix']);
	// if ($_REQUEST['url']) $sql = dbs_sql_replace_urls($_REQUEST['url']);

	$queries = explode(";\n", $sql);
	foreach ($queries as $query) {
		if (!trim($query)) continue;
		if (mysql_query($query) === false) {
			return false;
		}
	}

	return true;
}



/**
 * Generate a URL for the plugin.
 * @param array $params
 * @return string
 */
function dbs_url($params = array()) {
	$params = array_merge(array('page'=>'dbs_options'), $params);
	return admin_url('tools.php?' . http_build_query($params));
}

/**
 * @param $url string Remote site wpurl base
 * @param $action string dbs_pull or dbs_push
 * @param $params array POST parameters
 * @return string The returned content
 * @throws RuntimeException
 */
function dbs_post($url, $action, $params) {
	$remote = $url . '/wp-admin/admin-ajax.php?action=' . $action . '&api_version=' . DBS_API_VERSION;
	$ch = curl_init($remote);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
	// curl_setopt($ch, CURLOPT_COOKIE, 'XDEBUG_SESSION=1');
	$result = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($code != 200) {
		throw new RuntimeException('HTTP Error', $code);
	}
	return $result;
}

/**
 * Dump the database and save
 */

function dbs_makeBackup() {

	if ($sql = dbs_get_mysql_dump()) {
		$tempdir = realpath(dirname(__FILE__)).DIRECTORY_SEPARATOR.'backups';
		if (!file_exists($tempdir)) mkdir($tempdir);
		$filename = $tempdir . DIRECTORY_SEPARATOR .'db'.date('Ymd.His').'.sql.gz';
		file_put_contents($filename, gzencode($sql));

		return $filename;
	}
}

function dbs_get_mysql_dump()
{
	try
	{
		ob_start();
		dbs_mysqldump();
		return ob_get_clean();
	}
	catch (Exception $e)
	{
	ob_end_flush();
	 throw new Exception( 'Error producing MySQL dump', 0, $e);
	 return false;
	}
}

/**
 * Dump the current MYSQL table.
 * Original code (c)2006 Huang Kai <hkai@atutility.com>
 */

function dbs_mysqldump_old() {
	$sql = "SHOW TABLES;";
	$result = mysql_query($sql);
	dbs_print_dump_message();
	while ($row = mysql_fetch_row($result)) {
		dbs_mysqldump_table($row[0]);
	}
	mysql_free_result($result);
}


function dbs_mysqldump() {
	$tables = dbs_get_wp_tables();
	// if (dbs_get_target_table_prefix()) {
	// 	array_map("dbs_remove_table_prefix",$tables);
	// }
	dbs_print_dump_message($tables);
	if (is_array($tables)) foreach ($tables as $row) {
		dbs_mysqldump_table($row);
	}
}

function dbs_mysqldump_table($row) {
	echo dbs_mysqldump_table_structure($row);
	echo dbs_mysqldump_table_data($row);
}

function dbs_print_dump_message($tables=null)
{
	echo '/* Dump of database '.DB_NAME.' on '.$_SERVER['HTTP_HOST'].' at '.date('Y-m-d H:i:s')." */\n\n";

	// if (dbs_get_target_table_prefix()) foreach ($tables as $table) {
	// 	$table .= " > " . dbs_get_target_table_prefix() . substr($table,strlen($dbs_get_table_prefix));
	// }

	// if ($tables) echo "/* Selected tables: \n".implode($tables,", \n")." */\n\n";
}

/**
 * Original code (c)2006 Huang Kai <hkai@atutility.com>
 * @param $table string Table name
 * @return string SQL
 */
function dbs_mysqldump_table_structure($table) {
	echo "/* Table structure for table `$table` */\n\n";
	echo "DROP TABLE IF EXISTS `$table`;\n\n";

	$sql = "SHOW CREATE TABLE `$table`; ";
	$result = mysql_query($sql);
	if ($result) {
		if ($row = mysql_fetch_assoc($result)) {
			echo $row['Create Table'] . ";\n\n";
		}
	}
	mysql_free_result($result);
}

/**
 * Original code (c)2006 Huang Kai <hkai@atutility.com>
 * @param $table string Table name
 * @return string SQL
 */
function dbs_mysqldump_table_data($table) {
	$sql = "SELECT * FROM `$table`;";
	$result = mysql_query($sql);

	echo '';
	if ($result) {
		$num_rows = mysql_num_rows($result);
		$num_fields = mysql_num_fields($result);
		if ($num_rows > 0) {
			echo "/* dumping data for table `$table` */\n";
			$field_type = array();
			$i = 0;
			while ($i < $num_fields) {
				$meta = mysql_fetch_field($result, $i);
				array_push($field_type, $meta->type);
				$i++;
			}
			$maxInsertSize = 100000;
			$index = 0;
			$statementSql = '';
			while ($row = mysql_fetch_row($result)) {
				if (!$statementSql) $statementSql .= "INSERT INTO `$table` VALUES\n";
				$statementSql .= "(";
				for ($i = 0; $i < $num_fields; $i++) {
					if (is_null($row[$i]))
						$statementSql .= "null";
					else {
						switch ($field_type[$i]) {
							case 'int':
								$statementSql .= $row[$i];
								break;
							case 'string':
							case 'blob' :
							default:
								$statementSql .= "'" . mysql_real_escape_string($row[$i]) . "'";

						}
					}
					if ($i < $num_fields - 1)
						$statementSql .= ",";
				}
				$statementSql .= ")";

				if (strlen($statementSql) > $maxInsertSize || $index == $num_rows - 1) {
					echo $statementSql.";\n";
					$statementSql = '';
				} else {
					$statementSql .= ",\n";
				}

				$index++;
			}
		}
	}
	mysql_free_result($result);
	echo "\n";
}

function dbs_merge_network_plugins($sql)
{
	$sitemeta_table = dbs_get_base_table_prefix().'sitemeta';
	if (!dbs_wp_table_exists($sitemeta_table)) return $sql;

	$query = "select `meta_value` FROM `$sitemeta_table` WHERE `meta_key` LIKE 'active_sitewide_plugins';";
	$result = mysql_query($query);

	if ($result) {
		while ($row = mysql_fetch_row($result)) {
			$network_plugins = unserialize($row[0]);
		}
	}
	mysql_free_result($result);


	if (!$network_plugins) return $sql;

	$options_table = dbs_get_table_prefix().'options';
	$query = "select `option_value` FROM `$options_table` WHERE `option_name` LIKE 'active_plugins';";
	$result = mysql_query($query);

	if ($result) {
		while ($row = mysql_fetch_row($result)) {
			$active_plugins = unserialize($row[0]);
		}
	}

	// var_dump($active_plugins);

	foreach ($network_plugins as $network_plugin=>$id) {
		if (!$active_plugins[$network_plugin]) {
			$active_plugins[] = $network_plugin;
		}
	}

	$active_plugins_serialized = serialize($active_plugins);

	$sql = preg_replace("/(\(\d+?,'active_plugins',')(.*?;\})(','.*?'\),?)/","$1$active_plugins_serialized$3", $sql);

	return $sql;
}

function dbs_create_insert($data) {
   $count = 0;
   $fields = '';

   foreach($data as $col => $val) {
      if ($count++ != 0) $fields .= ', ';
      $col = mysql_real_escape_string($col);
      $val = mysql_real_escape_string($val);
      $fields .= "`$col` = $val";
   }
   return $fields;
   // $query = "INSERT INTO `myTable` SET $fields;";
   return $query;
}

function dbs_get_tokens()
{
	if (@$tokens = get_option('outlandish_sync_tokens')) return $tokens;
	return array();
}

function dbs_get_table_prefix()
{
	$prefix = dbs_get_base_table_prefix();
	if (is_multisite())
		$prefix .= dbs_get_mu_blog_id() . "_";

	return $prefix;
}

function dbs_get_base_table_prefix()
{
	global $wpdb;
	return $wpdb->base_prefix;
}

function dbs_get_target_table_prefix()
{
	return dbs_get_base_table_prefix();
	// TO DO
}

function dbs_get_mu_blog_id()
{
	if (is_multisite()) {
		global $blog_id;
		return $blog_id;
	}
}

function dbs_get_wp_tables()
{
	$dbs_dbprefix_sql = str_replace('_','\_',dbs_get_table_prefix());
	$sql = "SHOW TABLES LIKE '{$dbs_dbprefix_sql}%';";
	$result = mysql_query($sql);
	while ($row = mysql_fetch_row($result))
		$tables[] = $row[0];
	return $tables;
}
function dbs_wp_table_exists($tablename)
{
	$querytable = str_replace('_','\_',$tablename);
	$sql = "SHOW TABLES LIKE '{$querytable}';";
	$result = mysql_query($sql);
	while ($row = mysql_fetch_row($result))
		$tables[] = $row[0];
	if (@$tables) return true;
}

function dbs_remove_table_prefix($table)
{
	return substr($table, strlen(dbs_get_table_prefix()));
}

function dbs_sql_localize_tables( &$sql, $foreign_table_prefix)
{
	$local_table_prefix = dbs_get_table_prefix();
	dbs_sql_switch_table_prefixes($foreign_table_prefix, $local_table_prefix, $sql);
}

function dbs_sql_unlocalize_tables( &$sql, $foreign_table_prefix)
{
	$local_table_prefix = dbs_get_table_prefix();
	dbs_sql_switch_table_prefixes($local_table_prefix, $foreign_table_prefix, $sql);
}

function dbs_sql_switch_table_prefixes($match, $replace, &$sql)
{
	$sql = preg_replace("/`{$match}/","`{$replace}", $sql);
	$sql = preg_replace("/'{$match}user_roles'/","'{$replace}user_roles'", $sql);
}

function dbs_sql_remove_comments( &$sql)
{
	$sql = preg_replace("|/\*.+\*/\n|", "", $sql);
}

function dbs_sql_replace_urls( &$sql, $foreign_url)
{
	$local_url = get_bloginfo('wpurl');
	$sql = str_replace($foreign_url, $local_url, $sql);
}

function dbs_test_for_secret($req_secret) {
	$secret = dbs_getSecret();
	if (stripslashes($req_secret) != $secret) {
		die("You don't know me");
	}
}
