<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/detector.php';
$count=0;
function check(bool $ok, string $label): void { global $count; if (!$ok) { throw new RuntimeException('FAILED: '.$label); } $count++; echo "PASS $label\n"; }
function rejects(callable $fn, string $label): void { try { $fn(); } catch (RuntimeException $e) { check(true,$label); return; } check(false,$label); }
check(verdict(0.0)==='Likely non-AI','zero');
check(verdict(0.2)==='Likely non-AI','low boundary');
check(verdict(0.201)==='Uncertain','above low boundary');
check(verdict(0.799)==='Uncertain','below high boundary');
check(verdict(0.8)==='Likely AI-generated','high boundary');
check(verdict(1.0)==='Likely AI-generated','one');
foreach ([-0.01,1.01,NAN,INF] as $v) { rejects(fn()=>verdict($v),'reject invalid numeric score'); }
check(parseDetectorResponse('{"status":"success","type":{"ai_generated":0.91}}')===0.91,'valid provider response');
foreach (['{}','null','not json','{"status":"failure"}','{"status":"success","type":{"ai_generated":"0.9"}}','{"status":"success","type":{"ai_generated":3}}'] as $json) { rejects(fn()=>parseDetectorResponse($json),'reject malformed provider response'); }
$tmp=tempnam(sys_get_temp_dir(),'ig-test-');
try {
 file_put_contents($tmp,'<?php echo "unsafe";'); rejects(fn()=>inspectImage($tmp),'reject PHP masquerading as image');
 file_put_contents($tmp,'<svg xmlns="http://www.w3.org/2000/svg"></svg>'); rejects(fn()=>inspectImage($tmp),'reject SVG');
 file_put_contents($tmp,''); rejects(fn()=>inspectImage($tmp),'reject empty file');
 foreach (['png','jpeg','webp'] as $type) {
  $im=imagecreatetruecolor(32,24); $fn='image'.$type; $fn($im,$tmp); imagedestroy($im); clearstatcache(true,$tmp);
  $i=inspectImage($tmp); check($i['width']===32 && $i['height']===24,'accept decoded '.$type);
 }
 $im=imagecreatetruecolor(6001,1); imagepng($im,$tmp); imagedestroy($im); clearstatcache(true,$tmp);
 rejects(fn()=>inspectImage($tmp),'reject oversized dimensions');
 file_put_contents($tmp,str_repeat('x',8*1024*1024+1)); clearstatcache(true,$tmp); rejects(fn()=>inspectImage($tmp),'reject oversized file');
} finally { unlink($tmp); }
echo "$count checks passed. No external API calls were made.\n";
