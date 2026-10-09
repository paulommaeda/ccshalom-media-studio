<?php
/**
 * Run with: php tests/providers-smoke.php
 * Synthetic credentials and mocked WordPress transport only: NO real API requests.
 */
define('ABSPATH', __DIR__.'/');
class WP_Error {
    private string $code;
    private string $message;
    public function __construct($code,$message) { $this->code=$code;$this->message=$message; }
    public function get_error_message(){return $this->message;}
}
function is_wp_error($result){return $result instanceof WP_Error;}
function wp_json_encode($value){return json_encode($value,JSON_UNESCAPED_SLASHES);}
function sanitize_text_field($value){return strip_tags((string)$value);}
function wp_generate_password($len=24,$special=false,$extra=false){return str_repeat('x',$len);}
function get_transient($key){return false;}
function set_transient($key,$val,$expiry){return true;}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
$GLOBALS['requests']=[];
function wp_remote_post($url,$opts) {
    $GLOBALS['requests'][]=['url'=>$url,'opts'=>$opts];
    if ($url==='https://oauth2.googleapis.com/token') {
        return ['code'=>200,'body'=>json_encode(['access_token'=>'TEST_OAUTH_TOKEN','expires_in'=>3600])];
    }
    return ['code'=>401,'body'=>json_encode(['error'=>['message'=>'synthetic rejection']])];
}
function wp_remote_get($url,$opts){throw new RuntimeException('GET unexpected during mocked error test');}
function wp_safe_remote_get($url,$opts){throw new RuntimeException('image download not expected');}
require_once dirname(__DIR__).'/includes/class-ccsm-image-providers.php';
function verify($condition,$label) {
    if (!$condition) {fwrite(STDERR,"FAILED: ".$label."\n");exit(1);}
    echo "OK: ".$label."\n";
}
$defs=CCSM_Image_Providers::defaults();
verify(count(CCSM_Image_Providers::labels())===5,'Five supported providers');
verify(CCSM_Image_Providers::active_provider([])==='openai','Fallback OpenAI');
verify(!CCSM_Image_Providers::ready($defs),'Missing secrets prevent generation');
$s=$defs;
$s['provider']='openai';$s['key']='SECRET_OPENAI';$s['model']='gpt-image-1.5';
$r=CCSM_Image_Providers::generate($s,'test landscape','1536x1024');
verify(is_wp_error($r) && str_contains($GLOBALS['requests'][0]['url'],'api.openai.com/v1/images/generations'),'OpenAI route');
verify(!str_contains($r->get_error_message(),'SECRET_OPENAI'),'OpenAI key not exposed');
$s=$defs;$s['provider']='gemini';$s['gemini_key']='SECRET_GEMINI';
$r=CCSM_Image_Providers::generate($s,'test portrait','1024x1536');
$g=end($GLOBALS['requests']);
verify(is_wp_error($r) && str_contains($g['url'],'generativelanguage.googleapis.com'),'Gemini AI Studio route');
$data=json_decode($g['opts']['body'],true);
verify($data['generationConfig']['imageConfig']['aspectRatio']==='9:16','Gemini portrait ratio');
verify($g['opts']['headers']['x-goog-api-key']==='SECRET_GEMINI','Gemini uses request header');
$s=$defs;$s['provider']='stability';$s['stability_key']='SECRET_STABILITY';
$r=CCSM_Image_Providers::generate($s,'test portrait','1024x1536');
$st=end($GLOBALS['requests']);
verify(is_wp_error($r) && str_contains($st['url'],'api.stability.ai/v2beta/stable-image/generate/core'),'Stability Core route');
verify(str_contains($st['opts']['headers']['Content-Type'],'multipart/form-data') &&
    str_contains($st['opts']['body'],'name="prompt"') &&
    str_contains($st['opts']['body'],'9:16'),'Stability multipart fields');
$s=$defs;$s['provider']='replicate';$s['replicate_key']='SECRET_REPLICATE';
$r=CCSM_Image_Providers::generate($s,'test landscape','1536x1024');
$rep=end($GLOBALS['requests']);
verify(is_wp_error($r) && str_contains($rep['url'],'api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions'),'Replicate official route');
verify(json_decode($rep['opts']['body'],true)['input']['aspect_ratio']==='16:9','Replicate landscape ratio');
if (function_exists('openssl_pkey_new')&&function_exists('openssl_pkey_export')) {
    $private=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($private,$key);
    $credentials=['type'=>'service_account','client_email'=>'test@example.iam.gserviceaccount.com','private_key'=>$key,'project_id'=>'church-test-123'];
    $s=$defs;$s['provider']='vertex';$s['vertex_credentials']=json_encode($credentials);$s['vertex_project']='church-test-123';
    $r=CCSM_Image_Providers::generate($s,'test landscape','1536x1024');
    $v=end($GLOBALS['requests']);
    verify(is_wp_error($r) && str_contains($v['url'],'aiplatform.googleapis.com') && str_ends_with($v['url'],':generateContent'),'Vertex Gemini OAuth and route');
    verify($v['opts']['headers']['Authorization']==='Bearer TEST_OAUTH_TOKEN','Vertex OAuth token applied');
    $s['vertex_model']='imagen-4.0-generate-001';
    $r=CCSM_Image_Providers::generate($s,'test portrait','1024x1536');
    $v=end($GLOBALS['requests']);
    verify(is_wp_error($r) && str_ends_with($v['url'],':predict'),'Vertex Imagen route');
    verify(json_decode($v['opts']['body'],true)['parameters']['aspectRatio']==='9:16','Vertex Imagen portrait ratio');
}
echo "PASS: mocked API provider smoke tests\n";
