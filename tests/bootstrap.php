<?php
define('JIGOSHOP_DIR', dirname(__FILE__).'/..');

require_once(JIGOSHOP_DIR.'/src/Jigoshop/ClassLoader.php');
$loader = new \JigoshopClassLoader('Jigoshop', JIGOSHOP_DIR.'/src');
$loader->register();