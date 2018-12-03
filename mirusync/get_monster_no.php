<!DOCTYPE html>
<html>
<body>
<?php
include 'miru_common.php';
include 'sql_param.php';
$name = array_key_exists('name', $_GET) && $_GET['name'] != '' ? $_GET['name'] : 'miru';
$conn = connect_sql($host, $user, $pass, $schema);
?>
<form method="get">
NAME: <input type="text" name="name" value="<?php echo $name;?>">
<input type="submit">
</form>
<?php
//$img_url = 'https://storage.googleapis.com/mirubot/padimages/jp/portrait/';
$img_url = '/portrait/';
//$utf_string = file_get_contents('mons.txt');
//foreach(explode(PHP_EOL, $utf_string) as $name){
$time_start = microtime(true);
$out = '';
$mon = query_monster($conn, $name);
if($mon){
	if($mon['MONSTER_NO'] > 10000){ // crows in computedNames
		$mon['MONSTER_NO'] = $mon['MONSTER_NO'] - 10000;
	}
	$key = array_keys($mon);
	$out = $out . '<div><img src="' . $img_url . $mon['MONSTER_NO'] . '.png"><br/>';
	foreach($key as $k){
		if($k == 'MONSTER_NO'){
			$out = $out .  '[' . $mon[$k] . ']<br/>';
		}else{
			$out = $out . '(' . $k . ') ' . $mon[$k] . '<br/>';
		}
	}
	$out = $out .  '</div>';
}
echo '<p>Total execution time in seconds: ' . (microtime(true) - $time_start) . '</p>';
echo '<div>' . $out . '</div>';
//}
?>
</body>
</html>