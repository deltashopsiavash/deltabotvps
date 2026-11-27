<?php
// Database maintenance is local CLI only; never expose migration over HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')!=='--update'){fwrite(STDERR,"Usage: php install.php --update\n");exit(2);}
chdir(__DIR__);
require __DIR__.'/update.php';
updateBot();
