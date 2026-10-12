<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/core.php';
umask(0077);
foreach (['sessions','locks'] as $folder) if (!is_dir(__DIR__.'/'.$folder)) mkdir(__DIR__.'/'.$folder,0700,true);
if (!is_file(__DIR__.'/config.json')) {
    $c=json_decode(file_get_contents(__DIR__.'/config.example.json'),true,32,JSON_THROW_ON_ERROR);
    $c['installation_secret']=bin2hex(random_bytes(32));
    file_put_contents(__DIR__.'/config.json',json_encode($c,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    chmod(__DIR__.'/config.json',0600);
}
payment_db(__DIR__);
$c=payment_config(__DIR__);
echo json_encode(['installed'=>true,'enabled'=>$c['enabled'],'missing'=>payment_missing($c),'extensions'=>['curl'=>extension_loaded('curl'),'pdo_sqlite'=>extension_loaded('pdo_sqlite')]],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
