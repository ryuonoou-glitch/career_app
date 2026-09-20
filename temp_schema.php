<?php
require 'config.php';
$res = $mysqli->query('SHOW TABLES');
while($row = $res->fetch_array()) {
    $table = $row[0];
    if (strpos($table, 'school') !== false || strpos($table, 'student') !== false || strpos($table, 'assessment') !== false || strpos($table, 'result') !== false || strpos($table, 'match') !== false) {
        $schema = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
        print_r($schema);
    }
}
?>
