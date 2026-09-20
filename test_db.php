<?php
require 'config.php';
$res = $mysqli->query('SHOW COLUMNS FROM schools');
while($r = $res->fetch_assoc()) {
    print_r($r);
}
