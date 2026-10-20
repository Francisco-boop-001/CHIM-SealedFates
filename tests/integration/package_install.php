<?php
declare(strict_types=1);
// Pinned official installer, isolated filesystem root and peer-socket migration callback only.
if(getenv('WSL_DISTRO_NAME')!=='DwemerAI4Skyrim3-test')throw new RuntimeException('clone only');
$out='/mnt/k/ActorwrightExchange/artifacts/chim-correspondence-implementation-20261019';
require $out.'/core-extra/lib/plugin_package_manager.php';
$archive=$argv[1]??'';$name=$argv[2]??'';$version=$argv[3]??'';
if(!in_array($name,['correspondence','sworn_and_scorned','mind_poisoning'],true))throw new RuntimeException('invalid fixture target');
if(preg_match('/\\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\\z/D',$version)!==1)throw new RuntimeException('invalid fixture version');
$root=$out.'/installer-fixture/'.$name.'-'.$version.'-'.bin2hex(random_bytes(4));
if(file_exists($root))throw new RuntimeException('preserve prior installer fixture; choose a new candidate fixture');
$c=pg_connect('host=/var/run/postgresql dbname=chim_correspondence_installer_it user=postgres');
$row=pg_fetch_assoc(pg_query($c,"SELECT current_database() db,current_setting('listen_addresses') listeners"));
if($row!==['db'=>'chim_correspondence_installer_it','listeners'=>''])throw new RuntimeException('owned peer fixture DB only');
$migrations=[];
$manager=new DwemerPluginPackageManager($root,$root.'/data/plugin_packages',function($target,$plugin,$paths)use($c,&$migrations){
    if(!pg_query($c,'BEGIN'))throw new RuntimeException('fixture begin');
    try{foreach($paths as$path){if(!str_starts_with(realpath($path),realpath($target).'/migrations/'))throw new RuntimeException('migration escaped');if(!pg_query($c,file_get_contents($path)))throw new RuntimeException('fixture migration');$migrations[]=basename($path);}if(!pg_query($c,'COMMIT'))throw new RuntimeException('fixture commit');}catch(Throwable $e){pg_query($c,'ROLLBACK');throw $e;}
});
$job=$manager->installArchive($archive,basename($archive),$name,$version);
if($job['status']!=='completed')throw new RuntimeException('official installer failed: '.json_encode($job));
$zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('archive open');
$count=0;
for($i=0;$i<$zip->numFiles;$i++){$path=$zip->getNameIndex($i);if(!str_starts_with($path,'server/'))continue;$deployed=$root.'/ext/'.$name.'/'.substr($path,7);if(!is_file($deployed)||hash_file('sha256',$deployed)!==hash('sha256',$zip->getFromIndex($i)))throw new RuntimeException('deployed source differs');$count++;}
$probe=$manager->probe($name,$version);if($probe['upload_required']!==false)throw new RuntimeException('installed probe differs');
echo json_encode(['status'=>'PASS','kind'=>'official pinned CHIM installer in isolated fixture root; migration connection is explicit peer fixture callback, no installed-core change','name'=>$name,'version'=>$version,'files'=>$count,'migrations'=>$migrations,'archive_sha256'=>hash_file('sha256',$archive)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
