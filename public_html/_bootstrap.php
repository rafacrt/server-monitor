<?php
$_cfgFile = dirname(__DIR__) . '/private/config.php';
if (!file_exists($_cfgFile)) {
    header('Location: setup.php'); exit;
}
require $_cfgFile;
