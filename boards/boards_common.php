<?php
$size_list = array('s', 'm', 'l');
$orb_list = array('R', 'B', 'G', 'L', 'D', 'H', 'J', 'X', 'P', 'M');
function get_board($pattern, $size = 'm'){
	global $orb_list;
	$out = '<div class="board ' . $size . '">';
	foreach(str_split($pattern) as $o){
		if(in_array($o, $orb_list)){
			$out = $out . '<div class="orb ' . $o . '"></div>';
		}
	}
	$out = $out . '</div>';
	return $out;
}
function count_orbs($pattern){
	global $orb_list;
	$counts = array();
	foreach($orb_list as $orb){
		$counts[$orb] = 0;
	}
	foreach(str_split($pattern) as $o){
		$counts[$o] += 1;
	}
	return $counts;
}
function normalize($entry){
	global $orb_list;
	$entry['pattern'] = strtoupper($entry['pattern']);
	$counts = count_orbs($entry['pattern']);
	arsort($counts);
	$i = 0;
	foreach($counts as $orb => $count){
		$entry['pattern'] = str_replace($orb, $i, $entry['pattern']);
		if($entry['style'] != 'MAXCOMBO' && $entry['styleAtt'] == $orb){
			$entry['styleAtt'] = $i;
		}
		$i++;
	}
	foreach($orb_list as $idx => $orb){
		$entry['pattern'] = str_replace($idx, $orb, $entry['pattern']);
		if($entry['style'] != 'MAXCOMBO' && $entry['styleAtt'] == $idx){
			$entry['styleAtt'] = $orb;
		}
	}
	$entry = array_merge($entry, $counts);
	return $entry;
}
function connect_sql($host, $user, $pass, $schema){
	// Create connection
	$conn = new mysqli($host, $user, $pass);
	// Check connection
	if ($conn->connect_error) {
		trigger_error('Connection failed: ' . $conn->connect_error);
		header( 'HTTP/1.0 403 Forbidden', TRUE, 403 );
		die('you cannot');
	}
	$conn->set_charset('utf8');
	$conn->select_db($schema);
	return $conn;
}
function execute_select_stmt($stmt){
	if(!$stmt->execute()){
		trigger_error($conn->error . '[select]');
		return false;
	}
	$stmt->store_result();
	if($stmt->num_rows == 0){
		$stmt->free_result();
		return array();
	}
	$fields = array();
	$row = array();
	$meta = $stmt->result_metadata(); 
	while($f = $meta->fetch_field()){
		$fields[] = & $row[$f->name];
	}
	call_user_func_array(array($stmt, 'bind_result'), $fields);
	$res = array();
	while ($stmt->fetch()) { 
		foreach($row as $key => $val){
			$c[$key] = $val; 
		} 
		$res[] = $c; 
	}
	return $res;
}
function insert_board($conn, $combo, $style, $pattern, $styleCount = 0, $size = 'm', $description = ''){
	global $size_list;
	global $orb_list;
	if(!in_array($size, $size_list)){
		return false;
	}
	$pattern = strtoupper($pattern);
	foreach(str_split($pattern) as $o){
		if(!in_array($o, $orb_list)){
			return false;
		}
	}
	if($styleCount == ''){
		$styleCount = 0;
	}
	$sql = 'INSERT INTO `optimal_boards`.`boards` (`size`,`pattern`,`combo`,`style`,`styleCount`,`description`) VALUES (?,?,?,?,?,?);';
	$stmt = $conn->prepare($sql);
	$stmt->bind_param('ssisis', $size, $pattern, $combo, $style, $styleCount, $description);
	if(!$stmt->execute()){
		trigger_error('Insert failed: ' . $conn->error);
		$stmt->close();
		return false;
	}
	$stmt->close();
	return true;
}
function load_boards_from_google_sheets($conn){
	global $orb_list;
	$url = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQkDdwvr-R6t4SbqlLddS302UtKWvMx-rGIRDKD8_6AszcvNNv_N56SOoffaw1eRZbP0cUmM3eges1G/pub?gid=0&single=true&output=csv';
	$data = array();
	$fieldnames = array();
	if ($fh = fopen($url, 'r')) {
		if(!feof($fh)){
			$fieldnames = explode(',',trim(fgets($fh)));
		}
		while (!feof($fh)) {
			$tmp = explode(',',trim(fgets($fh)));
			$entry = array();
			for($i = 0; $i < sizeof($fieldnames); $i++){
				$entry[$fieldnames[$i]] = $tmp[$i] == '' ? null : $tmp[$i];
			}
			$data[] = normalize($entry);
		}
		fclose($fh);
	}else{
		trigger_error('Failed to open google sheet.');
		return false;
	}
	$fieldnames = array_merge($fieldnames, $orb_list);
	$tablename = 'boards';
	$sql = 'TRUNCATE TABLE ' . $tablename;
	if(!$conn->query($sql)){
		trigger_error('Truncate ' . $tablename . ' failed: ' . $conn->error);
		return false;
	}
	$insert_size = 1;
	$sql = 'INSERT INTO ' . $tablename . ' (';
	$paramtype = '';
	foreach($fieldnames as $field){
		$sql = $sql . $field . ',';
		if(ctype_digit($data[0][$field])){
			$paramtype = $paramtype . 'i';
		}else{
			$paramtype = $paramtype . 's';
		}
	}
	$valueGroup = ' (' . substr(str_repeat('?,', sizeof($fieldnames)), 0, -1) . '),';
	$sql = substr($sql, 0, -1) . ') VALUES ';
	$sql_m = $sql . substr(str_repeat($valueGroup, $insert_size), 0, -1) . ';';
	$paramtype_m = str_repeat($paramtype, $insert_size);
	$stmt = $conn->prepare($sql_m);
	$count = 0;
	$value_arr = array();
	foreach($data as $entry){
		foreach($fieldnames as $field){
			if(ctype_digit($data[0][$field]) && $entry[$field] == ''){
				$value_arr[] = '0';
			}else{
				$value_arr[] = $entry[$field];
			}
		}
		if(sizeof($value_arr) == strlen($paramtype_m)){
			$stmt->bind_param($paramtype_m, ...$value_arr);
			if(!$stmt->execute()){
				trigger_error('Insert failed: ' . $conn->error);
			}else{
				$count += $insert_size;
			}
			$value_arr = array();
		}
	}
	$stmt->close();
	if(sizeof($value_arr) > 0){
		$remaining = sizeof($value_arr) / sizeof($fieldnames);
		$sql = $sql . substr(str_repeat($valueGroup, $remaining), 0, -1) . ';';
		$stmt = $conn->prepare($sql);
		$stmt->bind_param(str_repeat($paramtype, $remaining), ...$value_arr);
		if(!$stmt->execute()){
			trigger_error('Insert failed: ' . $conn->error);
		}else{
			$count += $remaining;
		}
		$value_arr = array();
		$stmt->close();
	}
	echo 'Imported ' . $count . ' records out of ' . sizeof($data) . ' to ' . $tablename . PHP_EOL;
}
function select_all_boards($conn){
	$sql = 'SELECT `boards`.`bID`,`boards`.`size`,`boards`.`pattern`,`boards`.`combo`,`boards`.`style`,`boards`.`styleAtt`,`boards`.`styleCount`,`boards`.`R`,`boards`.`B`,`boards`.`G`,`boards`.`L`,`boards`.`D`,`boards`.`H`,`boards`.`J`,`boards`.`X`,`boards`.`P`,`boards`.`M`,`boards`.`description` FROM `optimal_boards`.`boards`;';
	$stmt = $conn->prepare($sql);
	$res = execute_select_stmt($stmt);
	$stmt->close();
	return $res;
}
?>