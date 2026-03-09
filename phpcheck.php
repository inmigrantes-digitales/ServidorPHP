<?php
header('Content-Type: text/plain; charset=utf-8');

echo 'PHP_VERSION=' . PHP_VERSION . "\n";
echo 'SAPI=' . PHP_SAPI . "\n";

echo 'str_starts_with=' . (function_exists('str_starts_with') ? 'yes' : 'no') . "\n";

echo 'ext_pdo=' . (extension_loaded('pdo') ? 'yes' : 'no') . "\n";
echo 'ext_pdo_mysql=' . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . "\n";
echo 'ext_curl=' . (extension_loaded('curl') ? 'yes' : 'no') . "\n";
echo 'ext_json=' . (extension_loaded('json') ? 'yes' : 'no') . "\n";
echo 'ext_mbstring=' . (extension_loaded('mbstring') ? 'yes' : 'no') . "\n";
