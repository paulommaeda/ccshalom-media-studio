<?php
defined('ABSPATH') || exit;
final class CCSM_GitHub_Update {
    private const REPO = 'paulommaeda/ccshalom-media-studio';
    private const SLUG = 'ccshalom-media-studio';
    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check']);
        add_filter('plugins_api', [__CLASS__, 'info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'rename'], 10, 4);
    }
    private static function data() {
        $cached=get_transient('ccsm_github_version');
        if ($cached !== false) return $cached;
        $response=wp_remote_get('https://raw.githubusercontent.com/'.self::REPO.'/main/ccshalom-media-studio.php',[
            'timeout'=>12,'headers'=>['Accept'=>'text/plain','User-Agent'=>'CCShalom-Media-Studio/'.CCSM_VERSION]
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) return false;
        $body=wp_remote_retrieve_body($response);
        if (!preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/mi',$body,$matches)) return false;
        $version=$matches[1];
        $data=['version'=>$version,'package'=>'https://github.com/'.self::REPO.'/archive/refs/heads/main.zip'];
        set_transient('ccsm_github_version',$data,6*HOUR_IN_SECONDS);
        return $data;
    }
    public static function check($transient) {
        if (!is_object($transient) || empty($transient->checked)) return $transient;
        $path=plugin_basename(CCSM_FILE);
        $data=self::data();
        if (!$data) return $transient;
        if (version_compare($data['version'], CCSM_VERSION, '>')) {
            $transient->response[$path]=(object)[
                'slug'=>self::SLUG,'plugin'=>$path,'new_version'=>$data['version'],
                'url'=>'https://github.com/'.self::REPO,'package'=>$data['package'],
                'icons'=>[],'banners'=>[],'tested'=>'6.8',
            ];
        } else {
            $transient->no_update[$path]=(object)[
                'slug'=>self::SLUG,'plugin'=>$path,'new_version'=>CCSM_VERSION,
                'url'=>'https://github.com/'.self::REPO,'package'=>$data['package'],
            ];
        }
        return $transient;
    }
    public static function info($result,$action,$args) {
        if ($action!=='plugin_information' || empty($args->slug) || $args->slug!==self::SLUG) return $result;
        $data=self::data();
        if (!$data) return $result;
        return (object)[
            'name'=>'CCShalom Media Studio','slug'=>self::SLUG,'version'=>$data['version'],
            'author'=>'CCShalom Curitiba','requires'=>'6.2','requires_php'=>'8.0',
            'download_link'=>$data['package'],
            'sections'=>['description'=>'Geração de quatro artes para campanhas de mensagens da CCShalom Curitiba.','changelog'=>'Acompanhe as alterações na branch main do GitHub.'],
            'homepage'=>'https://github.com/'.self::REPO
        ];
    }
    public static function rename($source,$remote_source,$upgrader,$hook_extra) {
        $plugin=$hook_extra['plugin']??'';
        if ($plugin!==plugin_basename(CCSM_FILE) || !is_dir($source)) return $source;
        $dest=trailingslashit($remote_source).self::SLUG;
        if (rtrim($source,'/')===rtrim($dest,'/')) return $source;
        global $wp_filesystem;
        if (!$wp_filesystem || !method_exists($wp_filesystem,'move')) return $source;
        if ($wp_filesystem->exists($dest)) $wp_filesystem->delete($dest,true);
        if ($wp_filesystem->move($source,$dest)) return $dest;
        return new WP_Error('ccsm_upgrade','Falha ao preparar pasta para atualização pelo GitHub.');
    }
}
