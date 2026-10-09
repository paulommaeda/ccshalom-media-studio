<?php
defined('ABSPATH') || exit;

/**
 * Adapters for third-party text-to-image APIs.
 * Only scenic background pixels are sent to external services.
 * Logos, church text and preacher photos are composited locally with GD.
 */
final class CCSM_Image_Providers {
    public static function labels(): array {
        return [
            'openai'    => 'OpenAI (GPT Image)',
            'gemini'    => 'Google AI Studio — Gemini / Nano Banana',
            'vertex'    => 'Google Cloud Vertex AI — Gemini / Imagen',
            'stability' => 'Stability AI — Stable Image Core',
            'replicate' => 'Replicate — FLUX e modelos oficiais',
        ];
    }
    public static function defaults(): array {
        return [
            'provider'=>'openai',
            'gemini_key'=>'', 'gemini_model'=>'gemini-2.5-flash-image',
            'vertex_credentials'=>'', 'vertex_project'=>'', 'vertex_location'=>'us-central1',
            'vertex_model'=>'gemini-2.5-flash-image',
            'stability_key'=>'',
            'replicate_key'=>'', 'replicate_model'=>'black-forest-labs/flux-schnell',
        ];
    }
    public static function active_provider(array $settings): string {
        $p = (string) ($settings['provider'] ?? 'openai');
        return isset(self::labels()[$p]) ? $p : 'openai';
    }
    public static function ready(array $settings): bool {
        switch (self::active_provider($settings)) {
            case 'openai': return !empty($settings['key']);
            case 'gemini': return !empty($settings['gemini_key']);
            case 'vertex': return (defined('CCSM_VERTEX_CREDENTIALS_JSON') || !empty($settings['vertex_credentials']))
                && !empty($settings['vertex_project']) && !empty($settings['vertex_location']);
            case 'stability': return !empty($settings['stability_key']);
            case 'replicate': return !empty($settings['replicate_key']);
        }
        return false;
    }
    public static function generate(array $settings, string $prompt, string $size) {
        if (!self::ready($settings)) {
            return new WP_Error('ccsm_provider_config','Credenciais ou configuração ausentes para '.self::labels()[self::active_provider($settings)].'.');
        }
        $aspect = ($size === '1024x1536') ? '9:16' : '16:9';
        switch (self::active_provider($settings)) {
            case 'openai': return self::openai($settings,$prompt,$size);
            case 'gemini': return self::gemini($settings,$prompt,$aspect);
            case 'vertex': return self::vertex($settings,$prompt,$aspect);
            case 'stability': return self::stability($settings,$prompt,$aspect);
            case 'replicate': return self::replicate($settings,$prompt,$aspect);
        }
        return new WP_Error('ccsm_provider','Provedor não reconhecido.');
    }
    private static function request(string $url, array $headers, $body, int $timeout=150) {
        $args=['timeout'=>$timeout,'redirection'=>0,'headers'=>$headers,'body'=>$body];
        return wp_remote_post($url,$args);
    }
    private static function json_request(string $url, array $headers, array $data, int $timeout=160) {
        $headers['Content-Type']='application/json';
        return self::request($url,$headers,wp_json_encode($data),$timeout);
    }
    private static function decoded($response, string $provider) {
        if (is_wp_error($response)) return $response;
        $status=(int)wp_remote_retrieve_response_code($response);
        $raw=wp_remote_retrieve_body($response);
        $json=json_decode($raw,true);
        if ($status<200 || $status>=300) {
            $msg=is_array($json) ? ($json['error']['message']??$json['message']??'Falha no serviço') : 'Falha no serviço';
            if (!is_string($msg)) $msg='Falha na API';
            // Never include request headers, credentials or service account keys in public diagnostics.
            return new WP_Error('ccsm_api', $provider.' HTTP '.$status.': '.substr(sanitize_text_field($msg),0,300));
        }
        if (!is_array($json)) return new WP_Error('ccsm_response',$provider.' retornou JSON inválido.');
        return $json;
    }
    private static function image_bytes($bytes, string $provider) {
        if (!is_string($bytes) || !$bytes || strlen($bytes)>24*1024*1024) {
            return new WP_Error('ccsm_image',$provider.': imagem vazia ou grande demais.');
        }
        $im=@imagecreatefromstring($bytes);
        if (!$im) return new WP_Error('ccsm_format',$provider.': formato de imagem inválido.');
        return $im;
    }
    private static function base64_image($data, string $provider) {
        $binary=is_string($data)?base64_decode($data,true):false;
        if ($binary===false) return new WP_Error('ccsm_b64',$provider.': imagem base64 inválida.');
        return self::image_bytes($binary,$provider);
    }
    private static function openai(array $s,string $prompt,string $size) {
        $response=self::json_request('https://api.openai.com/v1/images/generations',[
            'Authorization'=>'Bearer '.$s['key'],
        ],[
            'model'=>$s['model']??'gpt-image-1.5',
            'prompt'=>$prompt,'size'=>$size,'quality'=>'medium',
            'output_format'=>'png','n'=>1,
        ],180);
        $result=self::decoded($response,'OpenAI');
        if (is_wp_error($result)) return $result;
        return self::base64_image($result['data'][0]['b64_json']??null,'OpenAI');
    }
    private static function gemini_payload(string $prompt,string $aspect): array {
        return [
            'contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],
            'generationConfig'=>[
                'responseModalities'=>['IMAGE'],
                'imageConfig'=>['aspectRatio'=>$aspect],
            ],
        ];
    }
    private static function gemini_result(array $data, string $provider) {
        $parts=$data['candidates'][0]['content']['parts']??[];
        foreach ($parts as $part) {
            $inline=$part['inlineData']??$part['inline_data']??null;
            if (is_array($inline) && !empty($inline['data'])) {
                return self::base64_image($inline['data'],$provider);
            }
        }
        $reason=$data['candidates'][0]['finishReason']??'';
        return new WP_Error('ccsm_no_image',$provider.': nenhuma imagem retornada. '.sanitize_text_field($reason));
    }
    private static function model(string $raw,string $fallback): string {
        return preg_match('/^[a-zA-Z0-9._-]{3,120}$/',$raw) ? $raw : $fallback;
    }
    private static function gemini(array $s,string $prompt,string $aspect) {
        $model=self::model((string)($s['gemini_model']??''),'gemini-2.5-flash-image');
        $url='https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent';
        $r=self::json_request($url,['x-goog-api-key'=>$s['gemini_key']],self::gemini_payload($prompt,$aspect),180);
        $data=self::decoded($r,'Gemini AI Studio');
        return is_wp_error($data)?$data:self::gemini_result($data,'Gemini AI Studio');
    }
    private static function vertex_credentials(array $s) {
        $json=defined('CCSM_VERTEX_CREDENTIALS_JSON')?(string)constant('CCSM_VERTEX_CREDENTIALS_JSON'):(string)($s['vertex_credentials']??'');
        $cred=json_decode($json,true);
        if (!is_array($cred) || ($cred['type']??'')!=='service_account' || empty($cred['client_email']) || empty($cred['private_key'])) {
            return new WP_Error('ccsm_vertex_credentials','Google Cloud: informe o JSON de uma conta de serviço válido.');
        }
        return $cred;
    }
    private static function vertex_token(array $s) {
        $credentials=self::vertex_credentials($s);
        if (is_wp_error($credentials)) return $credentials;
        $cache_key='ccsm_google_token_'.md5($credentials['client_email'].substr($credentials['private_key'],0,64));
        $cached=get_transient($cache_key);
        if (is_string($cached)&&$cached!=='') return $cached;
        if (!function_exists('openssl_sign')) return new WP_Error('ccsm_ssl','Extensão OpenSSL indisponível para autenticação Google Cloud.');
        $now=time();
        $header=['alg'=>'RS256','typ'=>'JWT'];
        $claims=[
            'iss'=>$credentials['client_email'],
            'scope'=>'https://www.googleapis.com/auth/cloud-platform',
            'aud'=>'https://oauth2.googleapis.com/token',
            'iat'=>$now,'exp'=>$now+3500,
        ];
        $base64url=static fn($value)=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');
        $unsigned=$base64url(wp_json_encode($header)).'.'.$base64url(wp_json_encode($claims));
        $signature='';
        if (!openssl_sign($unsigned,$signature,$credentials['private_key'],OPENSSL_ALGO_SHA256)) {
            return new WP_Error('ccsm_jwt','Não foi possível assinar as credenciais Google Cloud.');
        }
        $jwt=$unsigned.'.'.$base64url($signature);
        $response=self::request('https://oauth2.googleapis.com/token',
            ['Content-Type'=>'application/x-www-form-urlencoded'],
            http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),30);
        $token_data=self::decoded($response,'Google OAuth');
        if (is_wp_error($token_data)) return $token_data;
        $token=$token_data['access_token']??'';
        if (!is_string($token)||!$token) return new WP_Error('ccsm_oauth','Google OAuth não retornou token.');
        set_transient($cache_key,$token,max(60,min(3400,(int)($token_data['expires_in']??3600)-120)));
        return $token;
    }
    private static function vertex(array $s,string $prompt,string $aspect) {
        $project=(string)($s['vertex_project']??'');
        $location=(string)($s['vertex_location']??'us-central1');
        if (!preg_match('/^[a-z][a-z0-9-]{3,62}$/',$project)
            || !preg_match('/^[a-z][a-z0-9-]{2,40}$/',$location)) {
            return new WP_Error('ccsm_vertex_project','Projeto ou região Vertex AI inválidos.');
        }
        $token=self::vertex_token($s);
        if (is_wp_error($token)) return $token;
        $model=self::model((string)($s['vertex_model']??''),'gemini-2.5-flash-image');
        $base='https://'.$location.'-aiplatform.googleapis.com/v1/projects/'.$project.'/locations/'.$location.'/publishers/google/models/'.$model;
        if (str_starts_with($model,'imagen-')) {
            $r=self::json_request($base.':predict',['Authorization'=>'Bearer '.$token],[
                'instances'=>[['prompt'=>$prompt]],
                'parameters'=>['sampleCount'=>1,'aspectRatio'=>$aspect],
            ],180);
            $out=self::decoded($r,'Vertex Imagen');
            return is_wp_error($out)?$out:self::base64_image($out['predictions'][0]['bytesBase64Encoded']??null,'Vertex Imagen');
        }
        $r=self::json_request($base.':generateContent',['Authorization'=>'Bearer '.$token],self::gemini_payload($prompt,$aspect),180);
        $data=self::decoded($r,'Vertex Gemini');
        return is_wp_error($data)?$data:self::gemini_result($data,'Vertex Gemini');
    }
    private static function stability(array $s,string $prompt,string $aspect) {
        // Stable Image Core expects multipart/form-data, including for text-only generation.
        // Construct a boundary directly so Requests does not silently send URL-encoded data.
        $boundary='ccsm-'.wp_generate_password(24,false,false);
        $fields=['prompt'=>$prompt,'aspect_ratio'=>$aspect,'output_format'=>'png'];
        $body='';
        foreach ($fields as $name=>$value) {
            $body.='--'.$boundary."\r\n".
                'Content-Disposition: form-data; name="'.$name.'"'."\r\n\r\n".
                str_replace(["\r","\n"],' ',$value)."\r\n";
        }
        $body.='--'.$boundary."--\r\n";
        $response=self::request('https://api.stability.ai/v2beta/stable-image/generate/core',[
            'Authorization'=>'Bearer '.$s['stability_key'],
            'Accept'=>'image/*',
            'Content-Type'=>'multipart/form-data; boundary='.$boundary,
        ],$body,180);
        if (is_wp_error($response)) return $response;
        $status=(int)wp_remote_retrieve_response_code($response);
        if ($status<200||$status>=300) {
            $data=json_decode(wp_remote_retrieve_body($response),true);
            return new WP_Error('ccsm_stability','Stability AI HTTP '.$status.': '.substr(sanitize_text_field($data['message']??'Erro ao gerar imagem'),0,300));
        }
        return self::image_bytes(wp_remote_retrieve_body($response),'Stability AI');
    }
    private static function replicate(array $s,string $prompt,string $aspect) {
        $model=(string)($s['replicate_model']??'black-forest-labs/flux-schnell');
        if (!preg_match('/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9._-]+$/',$model)) {
            return new WP_Error('ccsm_replicate_model','Nome do modelo Replicate inválido. Use proprietário/modelo.');
        }
        $input=['prompt'=>$prompt,'aspect_ratio'=>$aspect,'output_format'=>'png','num_outputs'=>1];
        $headers=['Authorization'=>'Bearer '.$s['replicate_key'],'Prefer'=>'wait=55'];
        $response=self::json_request('https://api.replicate.com/v1/models/'.$model.'/predictions',$headers,['input'=>$input],80);
        $prediction=self::decoded($response,'Replicate');
        if (is_wp_error($prediction)) return $prediction;
        $id=(string)($prediction['id']??'');
        if (!$id||!preg_match('/^[a-zA-Z0-9_-]+$/',$id)) return new WP_Error('ccsm_replicate_id','Replicate não retornou ID válido.');
        $deadline=time()+150;
        while (!in_array($prediction['status']??'', ['succeeded','failed','canceled'],true) && time()<$deadline) {
            sleep(3);
            $response=wp_remote_get('https://api.replicate.com/v1/predictions/'.$id,[
                'headers'=>['Authorization'=>'Bearer '.$s['replicate_key']],
                'timeout'=>25,'redirection'=>0,
            ]);
            $prediction=self::decoded($response,'Replicate');
            if (is_wp_error($prediction)) return $prediction;
        }
        if (($prediction['status']??'')!=='succeeded') {
            return new WP_Error('ccsm_replicate_status','Replicate: '.substr(sanitize_text_field($prediction['error']??($prediction['status']??'tempo limite')),0,200));
        }
        $url=$prediction['output'][0]??$prediction['output']??'';
        if (!is_string($url)) return new WP_Error('ccsm_replicate_output','Replicate retornou saída sem URL.');
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
        if (!str_starts_with($url,'https://') ||
            !($host==='replicate.delivery' || str_ends_with($host,'.replicate.delivery'))) {
            return new WP_Error('ccsm_replicate_url','URL de imagem do Replicate não permitida.');
        }
        $binary_response=wp_safe_remote_get($url,['timeout'=>45,'limit_response_size'=>24*1024*1024,'redirection'=>0]);
        if (is_wp_error($binary_response)) return $binary_response;
        if ((int)wp_remote_retrieve_response_code($binary_response)!==200) return new WP_Error('ccsm_replicate_download','Não foi possível obter imagem do Replicate.');
        return self::image_bytes(wp_remote_retrieve_body($binary_response),'Replicate');
    }
}
