<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
const LIMIT=4; const TTL=21600; const MAX_SIGNALS=500; const MAX_BODY=262144;
$dir=__DIR__.'/booth-data'; if(!is_dir($dir)||!is_writable($dir)) out(500,['error'=>'Storage unavailable']);
function out(int $s,array $v):never{http_response_code($s);echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function input():array{$n=(int)($_SERVER['CONTENT_LENGTH']??0);if($n>MAX_BODY)out(413,['error'=>'Request too large']);$r=file_get_contents('php://input',false,null,0,MAX_BODY+1);if($r===false||strlen($r)>MAX_BODY)out(413,['error'=>'Request too large']);if($r==='')return[];try{$v=json_decode($r,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){out(400,['error'=>'Malformed JSON']);}return is_array($v)?$v:[];}
function code(mixed $v):?string{$v=strtoupper((string)$v);return preg_match('/^[A-Z2-9]{6}$/',$v)?$v:null;}
function random_string(int $n,string $a):string{$r='';for($i=0;$i<$n;$i++)$r.=$a[random_int(0,strlen($a)-1)];return$r;}
function room_code():string{return random_string(6,'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');}
function peer_id():string{return random_string(8,'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');}
function token():string{return bin2hex(random_bytes(32));}
function supplied():string{return trim((string)($_SERVER['HTTP_X_BOOTH_TOKEN']??''));}
function role(array $r,string $id,string $t):bool{return isset($r['participants'][$id]['token'])&&hash_equals($r['participants'][$id]['token'],hash('sha256',$t));}
function locked(string $d,string $c,callable $fn):mixed{$p="$d/$c.json";$l=fopen("$d/$c.lock",'c+');if(!$l||!flock($l,LOCK_EX))out(503,['error'=>'Retry']);try{$r=null;if(is_file($p)){$x=json_decode((string)@file_get_contents($p),true);if(is_array($x))$r=$x;}return$fn($p,$r);}finally{flock($l,LOCK_UN);fclose($l);}}
function save(string $p,array $r):void{$r['updated']=time();$j=json_encode($r,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if($j===false||file_put_contents($p,$j,LOCK_EX)===false)out(500,['error'=>'Save failed']);}
function signal(array &$r,string $type,string $from,?string $to,mixed $data):int{$r['seq']=(int)($r['seq']??0)+1;$r['signals'][]=['id'=>$r['seq'],'type'=>$type,'from'=>$from,'to'=>$to,'data'=>$data,'ts'=>time()];if(count($r['signals'])>MAX_SIGNALS)$r['signals']=array_slice($r['signals'],-MAX_SIGNALS);return$r['seq'];}
if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS')out(204,[]);$a=(string)($_GET['action']??'');$b=($_SERVER['REQUEST_METHOD']??'')==='POST'?input():[];
if($a==='ping')out(200,['ok'=>true,'build'=>'FJN-Booth-V2-Four-Seat']);
if($a==='create'){for($i=0;$i<10;$i++){$c=room_code();$id=peer_id();$t=token();$made=locked($dir,$c,function($p,$r)use($c,$id,$t){if($r!==null)return false;save($p,['room'=>$c,'created'=>time(),'participants'=>[$id=>['seat'=>1,'token'=>hash('sha256',$t),'seen'=>time()]],'seq'=>0,'signals'=>[]]);return true;});if($made)out(201,['ok'=>true,'room'=>$c,'peer'=>$id,'seat'=>1,'token'=>$t]);}out(503,['error'=>'Create failed']);}
$c=code($b['room']??($_GET['room']??''));if(!$c)out(400,['error'=>'Invalid room']);
if($a==='join')locked($dir,$c,function($p,$r)use($c){if(!$r)out(404,['error'=>'Room not found']);if(count($r['participants'])>=LIMIT)out(409,['error'=>'Room full','full'=>true]);$used=array_column($r['participants'],'seat');$seat=1;while(in_array($seat,$used,true))$seat++;$id=peer_id();$t=token();$r['participants'][$id]=['seat'=>$seat,'token'=>hash('sha256',$t),'seen'=>time()];signal($r,'peer-joined',$id,null,['seat'=>$seat]);save($p,$r);out(200,['ok'=>true,'room'=>$c,'peer'=>$id,'seat'=>$seat,'token'=>$t]);});
$id=(string)($b['peer']??($_GET['peer']??''));$t=supplied();
if($a==='signal')locked($dir,$c,function($p,$r)use($b,$id,$t){if(!$r)out(404,['error'=>'Room not found']);if(!role($r,$id,$t))out(403,['error'=>'Denied']);$type=(string)($b['type']??'');if(!in_array($type,['offer','answer','ice'],true))out(400,['error'=>'Bad signal']);$to=(string)($b['to']??'');if(!isset($r['participants'][$to])||$to===$id)out(400,['error'=>'Bad destination']);$n=signal($r,$type,$id,$to,$b['data']??null);save($p,$r);out(200,['ok'=>true,'id'=>$n]);});
if($a==='poll'){$after=max(0,(int)($_GET['after']??0));locked($dir,$c,function($p,$r)use($id,$t,$after){if(!$r)out(404,['error'=>'Room not found']);if(!role($r,$id,$t))out(403,['error'=>'Denied']);$r['participants'][$id]['seen']=time();$s=array_values(array_filter($r['signals'],fn($x)=>(int)$x['id']>$after&&($x['to']===null||$x['to']===$id||$x['from']===$id)));save($p,$r);$people=[];foreach($r['participants']as$pid=>$v)$people[]=['id'=>$pid,'seat'=>$v['seat']];out(200,['ok'=>true,'participants'=>$people,'signals'=>$s]);});}
if($a==='leave')locked($dir,$c,function($p,$r)use($id,$t){if(!$r)out(200,['ok'=>true]);if(!role($r,$id,$t))out(403,['error'=>'Denied']);$seat=$r['participants'][$id]['seat'];unset($r['participants'][$id]);if(!$r['participants']){@unlink($p);out(200,['ok'=>true,'closed'=>true]);}signal($r,'peer-left',$id,null,['seat'=>$seat]);save($p,$r);out(200,['ok'=>true]);});
out(400,['error'=>'Unknown action']);
