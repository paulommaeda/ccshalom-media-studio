<?php
defined('ABSPATH') || exit;
final class CCSM_Renderer {
    private array $settings;
    private string $font;
    public function __construct(array $settings) {
        $this->settings = $settings;
        $id = absint($settings['font']??0);
        $file = $id ? get_attached_file($id) : '';
        if ($file && is_readable($file) && preg_match('/\.(ttf|otf)$/i',$file)) {
            $this->font = $file;
        } else {
            $choices = ['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'];
            $this->font = '';
            foreach ($choices as $choice) if (is_readable($choice)) { $this->font=$choice; break; }
        }
    }
    public function generate_background(string $prompt, string $size) {
        $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
            'timeout'=>180,
            'headers'=>['Authorization'=>'Bearer '.$this->settings['key'], 'Content-Type'=>'application/json'],
            'body'=>wp_json_encode([
                'model'=>$this->settings['model'] ?: 'gpt-image-1.5',
                'prompt'=>$prompt,'size'=>$size,'quality'=>'medium',
                'output_format'=>'png','n'=>1,
            ]),
        ]);
        if (is_wp_error($response)) return $response;
        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $payload = json_decode($raw,true);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('ccsm_api', 'OpenAI API: HTTP '.$status.' · '.sanitize_text_field($payload['error']['message']??'Falha ao gerar imagem.'));
        }
        $b64 = $payload['data'][0]['b64_json'] ?? '';
        if (!$b64) return new WP_Error('ccsm_empty', 'API não retornou imagem em base64.');
        $binary = base64_decode($b64, true);
        if (!$binary || strlen($binary) > 20*1024*1024) return new WP_Error('ccsm_file', 'Imagem retornada inválida ou maior que 20 MB.');
        $im=@imagecreatefromstring($binary);
        if (!$im) return new WP_Error('ccsm_image', 'Formato de imagem retornado não suportado pelo servidor.');
        return $im;
    }
    private function rgb($hex): array {
        $hex=ltrim($hex,'#');
        return [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
    }
    private function col($im,string $hex,int $alpha=0): int {
        [$r,$g,$b]=$this->rgb($hex);return imagecolorallocatealpha($im,$r,$g,$b,$alpha);
    }
    private function rect($im,int $x,int $y,int $w,int $h,string $hex,int $alpha=0): void {
        imagefilledrectangle($im,$x,$y,$x+$w,$y+$h,$this->col($im,$hex,$alpha));
    }
    private function roundrect($im,int $x,int $y,int $w,int $h,int $radius,string $hex,int $alpha=0): void {
        $color=$this->col($im,$hex,$alpha);
        imagefilledrectangle($im,$x+$radius,$y,$x+$w-$radius,$y+$h,$color);
        imagefilledrectangle($im,$x,$y+$radius,$x+$w,$y+$h-$radius,$color);
        foreach ([[$x+$radius,$y+$radius],[$x+$w-$radius,$y+$radius],[$x+$radius,$y+$h-$radius],[$x+$w-$radius,$y+$h-$radius]] as [$cx,$cy])
            imagefilledellipse($im,$cx,$cy,$radius*2,$radius*2,$color);
    }
    private function txt($im,string $text,int $x,int $y,int $size,string $hex='FFFFFF',string $align='left',int $maxWidth=0): void {
        $color=$this->col($im,'#'.$hex);
        if ($this->font && function_exists('imagettftext')) {
            if ($maxWidth>0) {
                for ($s=$size;$s>=14;$s-=2) {
                    $bb=imagettfbbox($s,0,$this->font,$text);
                    if (abs($bb[2]-$bb[0]) <= $maxWidth) { $size=$s;break; }
                }
            }
            $bb=imagettfbbox($size,0,$this->font,$text);
            $w=abs($bb[2]-$bb[0]);
            if ($align==='center') $x-=(int)($w/2);
            if ($align==='right') $x-=$w;
            imagettftext($im,$size,0,$x,$y,$color,$this->font,$text);
        } else {
            // The built-in GD font lacks reliable Unicode rendering; use a configured TTF for production.
            $font=5;$w=imagefontwidth($font)*strlen($text);
            if ($align==='center') $x-=(int)($w/2);
            if ($align==='right') $x-=$w;
            imagestring($im,$font,max(0,$x),max(0,$y-15),$text,$color);
        }
    }
    private function text_lines($im,string $text,int $x,int $y,int $size,string $hex,int $maxWidth,int $line=82): int {
        $words=preg_split('/\s+/u',trim($text));$current='';$lines=[];
        foreach ($words as $word) {
            $test=trim($current.' '.$word);
            if ($current && $this->width($test,$size)>$maxWidth) {$lines[]=$current;$current=$word;}
            else $current=$test;
        }
        if ($current) $lines[]=$current;
        foreach ($lines as $line_text) {$this->txt($im,$line_text,$x,$y,$size,$hex,'center',$maxWidth);$y+=$line;}
        return $y;
    }
    private function width(string $text,int $size): int {
        if ($this->font && function_exists('imagettfbbox')) {
            $b=imagettfbbox($size,0,$this->font,$text);
            return abs($b[2]-$b[0]);
        }
        return strlen($text)*10;
    }
    private function image_from_attachment(int $id) {
        $path=$id ? get_attached_file($id) : '';
        if (!$path || !is_readable($path)) return null;
        $info=@getimagesize($path);
        if (!$info) return null;
        if ($info[2]===IMAGETYPE_PNG) return @imagecreatefrompng($path);
        if ($info[2]===IMAGETYPE_JPEG) return @imagecreatefromjpeg($path);
        if ($info[2]===IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($path);
        return null;
    }
    private function composite_image($canvas,$asset,int $x,int $y,int $w,int $h,int $opacity=100): void {
        $sw=imagesx($asset);$sh=imagesy($asset);
        if (!$sw || !$sh) return;
        $scale=min($w/$sw,$h/$sh);
        $nw=max(1,(int)round($sw*$scale));$nh=max(1,(int)round($sh*$scale));
        $dx=$x+(int)(($w-$nw)/2);$dy=$y+(int)(($h-$nh)/2);
        $scaled=imagecreatetruecolor($nw,$nh);
        imagealphablending($scaled,false);imagesavealpha($scaled,true);
        imagefill($scaled,0,0,imagecolorallocatealpha($scaled,0,0,0,127));
        imagecopyresampled($scaled,$asset,0,0,0,0,$nw,$nh,$sw,$sh);
        imagealphablending($canvas,true);
        if ($opacity===100) imagecopy($canvas,$scaled,$dx,$dy,0,0,$nw,$nh);
        else {
            // Alpha preserving opacity for a translucent watermark.
            for ($i=0;$i<$nw;$i++) for ($j=0;$j<$nh;$j++) {
                $rgba=imagecolorat($scaled,$i,$j);$a=($rgba>>24)&127;
                $na=127-(int)((127-$a)*$opacity/100);
                if ($na>=127) continue;
                $c=imagecolorallocatealpha($canvas,($rgba>>16)&255,($rgba>>8)&255,$rgba&255,$na);
                imagesetpixel($canvas,$dx+$i,$dy+$j,$c);
            }
        }
        imagedestroy($scaled);
    }
    private function photo($canvas,int $photoid,int $x,int $y,int $w,int $h): void {
        $asset=$this->image_from_attachment($photoid);
        if (!$asset) return;
        // Original pixel content is used: no AI face synthesis or retouching.
        // Recommend transparent PNG; JPG will retain its own rectangular background.
        $this->composite_image($canvas,$asset,$x,$y,$w,$h);
        imagedestroy($asset);
    }
    private function header($canvas,int $w,int $h): void {
        $logo=$this->image_from_attachment(absint($this->settings['logo']??0));
        if ($logo) {
            $this->composite_image($canvas,$logo,(int)($w*.21), (int)($h*.035), (int)($w*.58),(int)($h*.11));
            imagedestroy($logo);
        } else $this->txt($canvas,'CCShalom Curitiba',(int)($w/2),(int)($h*.105),33,'FFFFFF','center');
    }
    private function watermark($canvas,int $w,int $h): void {
        $asset=$this->image_from_attachment(absint($this->settings['dove']??0));
        if (!$asset) return;
        $this->composite_image($canvas,$asset,(int)($w*.50),(int)($h*.11),(int)($w*.55),(int)($h*.34),7);
        imagedestroy($asset);
    }
    private function pill($canvas,string $label,int $cx,int $cy,int $width,int $height,bool $highlight=false): void {
        $this->roundrect($canvas,$cx-(int)($width/2),$cy,$width,$height,(int)($height/2),$highlight?'#001BE1':'#000A52',18);
        $this->txt($canvas,$label,$cx,$cy+(int)($height*.67),min(27,(int)($height*.35)),'FFFFFF','center',$width-30);
    }
    private function add_footer($im,int $w,int $h): void {
        $foot=(string)($this->settings['footer']??'');
        $this->txt($im,$foot,(int)($w/2),(int)($h*.969),$w>1100?17:16,'FFFFFF','center',$w-80);
    }
    public function compose(string $type,array $d,$bg) {
        $portrait = str_starts_with($type,'story');
        $w=$portrait?1080:1280;$h=$portrait?1920:720;
        $canvas=imagecreatetruecolor($w,$h);imagealphablending($canvas,true);imagesavealpha($canvas,true);
        imagecopyresampled($canvas,$bg,0,0,0,0,$w,$h,imagesx($bg),imagesy($bg));
        // Dark center-gradient overlay ensures a legible title even with a bright AI backdrop.
        if ($portrait) {
            for ($y=400;$y<1390;$y+=2) {
                $distance=abs($y-800)/590;
                $alpha=(int)(95+min(1,$distance)*23);
                $this->rect($canvas,0,$y,$w,2,'#000A52',$alpha);
            }
        } else {
            $this->rect($canvas,0,0,(int)($w*.74),$h,'#000A52',45);
        }
        $this->watermark($canvas,$w,$h);
        if ($type!=='thumb_recorded') $this->header($canvas,$w,$h);
        else {
            // Place the official logo upper-left on recorded thumbnail.
            $logo=$this->image_from_attachment(absint($this->settings['logo']??0));
            if ($logo) { $this->composite_image($canvas,$logo,35,12,410,100);imagedestroy($logo); }
            else $this->txt($canvas,'CCShalom Curitiba',60,75,30);
        }
        $theme=trim((string)($d['theme']??''));
        $date=!empty($d['date'])?date_i18n('d/m',strtotime($d['date'])):'';
        $first=trim((string)($d['time1']??''));$second=trim((string)($d['time2']??''));
        $time=$first . ($second?' e '.$second:'');
        $address=(string)($d['address']??'');
        if ($portrait) {
            $this->pill($canvas, $type==='story_live'?'TEMA DA MENSAGEM':'TEMA DA MENSAGEM', $w/2,375,650,70);
            $size=mb_strlen($theme)>30?64:84;
            $end=$this->text_lines($canvas,$theme,$w/2,620,$size,'FFFFFF',970,$size+31);
            if ($type==='story_live') {
                $this->pill($canvas,'● TRANSMISSÃO AO VIVO',$w/2,max(955,$end+35),700,100,true);
                $this->pill($canvas,'DOMINGO ÀS '.$first,$w/2,max(1090,$end+170),680,96);
                $this->pill($canvas,'ACOMPANHE AO VIVO',$w/2,1640,720,106,true);
            } else {
                $this->pill($canvas,'DOMINGO '.$date,$w/2,max(1050,$end+40),620,92);
                $this->pill($canvas,'ÀS '.$time,$w/2,max(1165,$end+155),620,92);
                if ($address) $this->pill($canvas,$address,$w/2,1530,950,95);
                $this->pill($canvas,'VENHA CELEBRAR CONOSCO',$w/2,1650,780,96,true);
            }
        } else {
            $photoid=absint($d['pastor_photo']??0);
            if ($type==='thumb_recorded' && $photoid) {
                $this->photo($canvas,$photoid,760,60,515,630);
                $this->rect($canvas,740,0,25,720,'#000A52',92);
            }
            $cx=$type==='thumb_recorded'?380:640;
            $start=$type==='thumb_recorded'?180:235;
            $max=$type==='thumb_recorded'?700:1160;
            $this->pill($canvas,$type==='thumb_live'?'TEMA DA MENSAGEM':'MENSAGEM DE DOMINGO',$cx,$type==='thumb_recorded'?115:160,$type==='thumb_recorded'?610:650,60);
            $theme_length=function_exists('mb_strlen') ? mb_strlen($theme) : strlen($theme);
            $s=$theme_length>35 ? 47 : ($theme_length>22 ? 58 : 83);
            $end=$this->text_lines($canvas,$theme,$cx,$start,$s,'FFFFFF',$max,$s+22);
            if ($type==='thumb_live') {
                $this->pill($canvas,'● TRANSMISSÃO AO VIVO',420,540,550,78,true);
                $this->pill($canvas,'DOMINGO ÀS '.$first,960,540,480,78);
            } else {
                $this->pill($canvas,'MENSAGEM · '.$d['pastor'],380,605,690,68);
            }
        }
        $this->add_footer($canvas,$w,$h);
        $uploads=wp_upload_dir();
        if (!empty($uploads['error'])) { imagedestroy($canvas);return new WP_Error('ccsm_upload',$uploads['error']);}
        $filename=wp_unique_filename($uploads['path'],'ccshalom-'.sanitize_title($theme).'-'.$type.'.png');
        $path=trailingslashit($uploads['path']).$filename;
        $written=imagepng($canvas,$path,6);
        imagedestroy($canvas);
        if (!$written) return new WP_Error('ccsm_save','Não foi possível gravar PNG.');
        require_once ABSPATH.'wp-admin/includes/image.php';
        $id=wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>$theme.' - '.$type,'post_status'=>'inherit'], $path);
        if (is_wp_error($id) || !$id) return new WP_Error('ccsm_attach','Não foi possível anexar imagem.');
        wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$path));
        return $id;
    }
}
