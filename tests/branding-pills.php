<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH . '/app/Services/BrandingText.php';
require BASE_PATH . '/app/Services/ImageBrandingService.php';
use App\Services\BrandingText;
use App\Services\ImageBrandingService;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
foreach (['app/Services/BrandingText.php','app/Services/ImageBrandingService.php','app/Services/DatabaseUpgradeService.php','public/index.php'] as $file) {token_get_all(file_get_contents(BASE_PATH.'/'.$file),TOKEN_PARSE);check(true,'PHP syntax '.$file);}
check(count(BrandingText::lines("one\r\n\n two\rthree\n"))===3,'three nonempty lines');
BrandingText::validate('☎ ✆ ◎ 🌐 📞 📍 www.example.com +1 202 555 0142 বাংলা');
check(true,'multiple Unicode icons, Bangla and ASCII font coverage');
try {BrandingText::validate("\u{10FFFF}");check(false,'reject unsupported');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'unsupported'),'unsupported glyph is explained');}
check(preg_match('/^[\x{E000}-\x{F8FF}] [\x{E000}-\x{F8FF}] [\x{E000}-\x{F8FF}]$/u', BrandingText::gdText('📞 🌐 🏛')) === 1, 'supplementary icons use GD-compatible BMP aliases');
check(BrandingText::gdText('A&B &#128222; বাংলা ☎') === 'A&#38;B &#38;#128222; বাংলা ☎', 'literal ampersands and text preserved');
$draw=new ReflectionMethod(ImageBrandingService::class,'drawText');
$pill=new ReflectionMethod(ImageBrandingService::class,'drawPill');
$base=['folder_text_color'=>'#FFFFFF','folder_text_opacity'=>90,'folder_text_background_enabled'=>1,'folder_text_background_color'=>'#050F19','folder_text_background_opacity'=>35,'folder_text_size_pct'=>4];
$text="📞 +1 202 555 0142\n\n🌐 www.example.com\n🏛 123 Example Street, Example City";
foreach(ImageBrandingService::positions() as $position){
 $im=imagecreatetruecolor(1000,625);$bg=imagecolorallocate($im,90,115,125);imagefill($im,0,0,$bg);
 $draw->invoke(null,$im,$text,$base+['folder_text_position'=>$position],false);
 $bands=0;$in=false;$minX=1000;$maxX=0;$minY=625;$maxY=0;
 for($y=0;$y<625;$y++){$row=false;for($x=0;$x<1000;$x++){if(imagecolorat($im,$x,$y)!==$bg){$row=true;$minX=min($x,$minX);$maxX=max($x,$maxX);$minY=min($y,$minY);$maxY=max($y,$maxY);}}if($row&&!$in)$bands++;$in=$row;}
 check($bands===1,"$position renders one horizontal band");check($minX>=34&&$maxX<=965&&$minY>=21&&$maxY<=604,"$position fits frame");
 if($position==='bottom-center')imagepng($im,sys_get_temp_dir().'/branding-pills-render.png');
 imagedestroy($im);
}
foreach([0,35,100] as $alpha){
 $im=imagecreatetruecolor(200,80);$bg=imagecolorallocate($im,100,100,100);imagefill($im,0,0,$bg);imagealphablending($im,true);
 $fg=imagecolorallocatealpha($im,255,255,255,25);$fill=imagecolorallocatealpha($im,0,0,0,127-(int)round($alpha*1.27));
 $pill->invoke(null,$im,20,20,160,40,2,$fg,$fill,$alpha>0);
 $center=imagecolorat($im,100,40)&255;$edge=imagecolorat($im,21,40)&255;
 check($center===($alpha===0?100:($alpha===100?0:65)),"background $alpha% behaves correctly");
 check($edge>200,"border independent of background $alpha%");imagedestroy($im);
}
$im=imagecreatetruecolor(360,640);$draw->invoke(null,$im,str_repeat('longwebsite',12).'.com'."\n☎ +1 202 555 0142\n🌐 ◎",$base+['folder_text_position'=>'bottom-right'],true);check(true,'long unbroken link and portrait Story fit');
$draw->invoke(null,$im,implode("\n",array_fill(0,15,'☎ Phone')),$base+['folder_text_position'=>'middle-center'],false);check(true,'15 lines fit without truncation');
// The outer corner must contain intermediate colors after supersampling.
$im=imagecreatetruecolor(640,480);$flat=imagecolorallocate($im,20,40,60);imagefill($im,0,0,$flat);
$draw->invoke(null,$im,'☎ Smooth border',$base+['folder_text_position'=>'bottom-center'],false);
$top=null;$cornerColors=[];
for($y=0;$y<480;$y++){
 for($x=0;$x<640;$x++){
  $pixel=imagecolorat($im,$x,$y);
  if($pixel!==$flat){$top??=$y;if($y<=$top+2)$cornerColors[$pixel]=true;}
 }
 if($top!==null&&$y>$top+2)break;
}
check(count($cornerColors)>4,'rounded border has antialiased intermediate colors');
echo "ALL TESTS PASSED\n";
