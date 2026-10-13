<?php
// CLI only: user places their chosen password into the private file, never into chat.
declare(strict_types=1);if(PHP_SAPI!=='cli')exit;umask(0077);
$p=__DIR__.'/admin-password.txt';if(!is_file($p))exit("Create private admin-password.txt first\n");
$v=trim(file_get_contents($p));if(strlen($v)<12||strlen($v)>72)exit("Password must contain 12 to 72 bytes; nothing changed\n");
$c=['password_hash'=>password_hash($v,PASSWORD_DEFAULT),'version'=>bin2hex(random_bytes(24))];
$f=__DIR__.'/admin-auth.tmp';file_put_contents($f,json_encode($c,JSON_THROW_ON_ERROR));chmod($f,0600);rename($f,__DIR__.'/admin-auth.json');file_put_contents($p,'');chmod($p,0600);unset($v);echo "Admin password secured; input file cleared; existing sessions invalidated\n";
