<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(ini_get('phar.readonly')){ fwrite(STDERR,"Run php -d phar.readonly=0 tools/build.php\n");exit(1); }
@mkdir($root.'/build',0777,true);$output=$root.'/build/AmyxAnvil.phar';if(is_file($output)){ unlink($output); }
$phar=new Phar($output);$phar->startBuffering();$files=[$root.'/plugin.yml'];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src',FilesystemIterator::SKIP_DOTS)) as $file){ if($file->isFile()){ $files[]=$file->getPathname(); } }
sort($files);foreach($files as $file){ $phar->addFile($file,substr($file,strlen($root)+1)); }
$phar->setStub('<?php __HALT_COMPILER();');$phar->setSignatureAlgorithm(Phar::SHA256);$phar->stopBuffering();echo $output,PHP_EOL;
