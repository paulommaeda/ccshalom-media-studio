<?php
/**
 * Plugin Name: CCShalom Media Studio
 * Description: Campanhas visuais da CCShalom Curitiba: dois Stories e duas thumbnails, com cenários gerados por IA.
 * Version: 0.2.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: CCShalom Curitiba
 * Update URI: https://github.com/paulommaeda/ccshalom-media-studio
 * Text Domain: ccshalom-media-studio
 */
defined('ABSPATH') || exit;
define('CCSM_VERSION', '0.2.0');
define('CCSM_FILE', __FILE__);
define('CCSM_DIR', plugin_dir_path(__FILE__));
define('CCSM_URL', plugin_dir_url(__FILE__));

require_once CCSM_DIR . 'includes/class-ccsm-prompts.php';
require_once CCSM_DIR . 'includes/class-ccsm-image-providers.php';
require_once CCSM_DIR . 'includes/class-ccsm-renderer.php';
require_once CCSM_DIR . 'includes/class-ccsm-github-update.php';

final class CCSM_Plugin {
    private const OPTION = 'ccsm_settings';
    private const CPT = 'ccsm_campaign';
    private const META = '_ccsm_data';

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('admin_post_ccsm_save_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_ccsm_create', [__CLASS__, 'create_campaign']);
        add_action('admin_post_ccsm_generate', [__CLASS__, 'start_generation']);
        add_action('admin_post_ccsm_retry', [__CLASS__, 'retry_art']);
        add_action('ccsm_process_campaign', [__CLASS__, 'process_campaign'], 10, 1);
        add_action('admin_notices', [__CLASS__, 'notice']);
        CCSM_GitHub_Update::init();
    }
    public static function register_cpt(): void {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Campanhas', 'singular_name' => 'Campanha'],
            'public' => false, 'show_ui' => false, 'supports' => ['title'],
            'capability_type' => 'post',
        ]);
    }
    public static function defaults(): array {
        return array_merge(CCSM_Image_Providers::defaults(), [
            'key' => '', 'model' => 'gpt-image-1.5', 'logo' => 0, 'dove' => 0,
            'font' => 0, 'footer' => 'COMUNHÃO  |  DISCIPULADO  |  MISSÃO',
            'address' => 'Rua Cascavel, 750 - Boqueirão - Curitiba/PR',
        ]);
    }
    public static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }
    public static function menu(): void {
        add_menu_page('CCShalom Studio', 'CCShalom Studio', 'manage_options', 'ccsm', [__CLASS__, 'dashboard'], 'dashicons-format-gallery', 32);
        add_submenu_page('ccsm', 'Nova campanha', 'Nova campanha', 'manage_options', 'ccsm-new', [__CLASS__, 'new_screen']);
        add_submenu_page('ccsm', 'Configurações', 'Configurações', 'manage_options', 'ccsm-settings', [__CLASS__, 'settings_screen']);
    }
    public static function enqueue(string $hook): void {
        if (strpos($hook, 'ccsm') === false) return;
        wp_enqueue_media();
        wp_enqueue_style('ccsm-admin', CCSM_URL . 'assets/admin.css', [], CCSM_VERSION);
        wp_enqueue_script('ccsm-admin', CCSM_URL . 'assets/admin.js', ['jquery'], CCSM_VERSION, true);
    }
    private static function require_admin(string $action): void {
        if (!current_user_can('manage_options')) wp_die('Sem permissão.', '', ['response' => 403]);
        check_admin_referer($action);
    }
    private static function shell(string $heading): void {
        echo '<div class="wrap ccsm-wrap"><header class="ccsm-head"><div><span class="ccsm-eyebrow">CCSHALOM CURITIBA</span><h1>' . esc_html($heading) . '</h1><p>Comunhão · Discipulado · Missão</p></div><nav><a class="button" href="' . esc_url(admin_url('admin.php?page=ccsm')) . '">Campanhas</a> <a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=ccsm-new')) . '">+ Nova campanha</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=ccsm-settings')) . '">Configurações</a></nav></header>';
    }
    public static function notice(): void {
        if (!isset($_GET['ccsm_notice']) || strpos((string)($_GET['page'] ?? ''), 'ccsm') !== 0) return;
        $messages = ['saved' => 'Dados salvos.', 'queued' => 'Geração iniciada. Atualize para acompanhar.', 'error' => 'Verifique o erro apresentado na campanha.'];
        $message = $messages[sanitize_key(wp_unslash($_GET['ccsm_notice']))] ?? '';
        if ($message) echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
    public static function settings_screen(): void {
        self::shell('Configurações');
        $s = self::settings();
        echo '<div class="ccsm-panel"><h2>Integração e identidade visual</h2><p>Escolha o provedor que vai gerar os cenários. Os textos, a logo oficial e a foto do pregador são aplicados localmente, sem recriar os rostos.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccsm_settings'); echo '<input type="hidden" name="action" value="ccsm_save_settings">';
        echo '<div class="ccsm-fields">';
        echo '<label class="ccsm-field ccsm-provider-select"><b>Provedor de geração de imagem</b><select name="provider" id="ccsm-provider">';
        foreach (CCSM_Image_Providers::labels() as $code=>$label) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($code, CCSM_Image_Providers::active_provider($s), false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><small>O provedor selecionado será usado nas próximas campanhas. Cada serviço possui faturamento próprio.</small></label>';
        echo '<div class="ccsm-provider-section" data-provider="openai">';
        self::secret_field('key','OpenAI API Key',!empty($s['key']));
        self::field('model','Modelo OpenAI','text',(string)$s['model'],'Ex.: gpt-image-1.5');
        echo '</div>';
        echo '<div class="ccsm-provider-section" data-provider="gemini">';
        self::secret_field('gemini_key','Google AI Studio API Key',!empty($s['gemini_key']));
        self::field('gemini_model','Modelo Gemini','text',(string)$s['gemini_model'],'Ex.: gemini-2.5-flash-image; informe um modelo com geração de imagem.');
        echo '<small><a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Criar chave no Google AI Studio</a></small></div>';
        echo '<div class="ccsm-provider-section" data-provider="vertex">';
        self::field('vertex_project','Google Cloud Project ID','text',(string)$s['vertex_project']);
        self::field('vertex_location','Região Google Cloud','text',(string)$s['vertex_location'],'Ex.: us-central1; habilite a Vertex AI API e a conta de faturamento.');
        self::field('vertex_model','Modelo Vertex AI (Gemini ou Imagen)','text',(string)$s['vertex_model'],'Ex.: gemini-2.5-flash-image ou imagen-4.0-generate-001');
        self::secret_field('vertex_credentials','JSON da service account Google Cloud (colar inteiro)',!empty($s['vertex_credentials']),true);
        echo '<p class="ccsm-hint">Requer uma conta de serviço com acesso à Vertex AI. Recomendado: armazenar o JSON no wp-config.php como CCSM_VERTEX_CREDENTIALS_JSON, em vez de salvar no banco. A chave não será exibida aqui.</p></div>';
        echo '<div class="ccsm-provider-section" data-provider="stability">';
        self::secret_field('stability_key','Stability AI API Key',!empty($s['stability_key']));
        echo '<p class="ccsm-hint">Utiliza Stable Image Core pela API oficial.</p></div>';
        echo '<div class="ccsm-provider-section" data-provider="replicate">';
        self::secret_field('replicate_key','Replicate API Token',!empty($s['replicate_key']));
        self::field('replicate_model','Modelo Replicate (oficial)','text',(string)$s['replicate_model'],'Ex.: black-forest-labs/flux-schnell. O modelo deve aceitar prompt e aspect_ratio.');
        echo '<p class="ccsm-hint">Modelos oficiais com saída de imagem por URL, por exemplo FLUX Schnell.</p></div>';
        self::media_field('logo', 'Logo oficial branca', (int)$s['logo']);
        self::media_field('dove', 'Marca d’água da pomba (PNG transparente)', (int)$s['dove']);
        self::media_field('font', 'Fonte TTF/OTF para os textos (recomendado)', (int)$s['font']);
        self::field('address', 'Endereço padrão', 'text', $s['address']);
        self::field('footer', 'Rodapé padrão', 'text', $s['footer']);
        echo '</div><p><button class="button button-primary button-large">Salvar configurações</button></p></form></div>';
        echo '<p class="ccsm-hint">Provedor ativo: <strong>' . esc_html(CCSM_Image_Providers::labels()[CCSM_Image_Providers::active_provider($s)]) . '</strong>.</p>';
        echo '</div>';
    }
    private static function field(string $name, string $label, string $type, string $value, string $hint = ''): void {
        echo '<label class="ccsm-field"><b>' . esc_html($label) . '</b><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($type === 'password' ? ' autocomplete="new-password"' : '') . '><small>' . esc_html($hint) . '</small></label>';
    }
    private static function secret_field(string $name,string $label,bool $saved,bool $textarea=false): void {
        echo '<div class="ccsm-field"><b>' . esc_html($label) . '</b>';
        if ($textarea) echo '<textarea name="' . esc_attr($name) . '" rows="4" autocomplete="off" placeholder="Cole o JSON de credenciais somente para substituir"></textarea>';
        else echo '<input type="password" name="' . esc_attr($name) . '" value="" autocomplete="new-password" placeholder="Insira uma nova chave para substituir">';
        echo '<small>' . ($saved ? 'Credencial configurada. Deixe em branco para preservar.' : 'Ainda não configurada.') . '</small>';
        echo '<label class="ccsm-clear-secret"><input type="checkbox" name="clear_' . esc_attr($name) . '" value="1"> Excluir credencial armazenada</label></div>';
    }
    private static function media_field(string $name, string $label, int $id): void {
        $url = $id ? wp_get_attachment_url($id) : '';
        echo '<div class="ccsm-field ccsm-media"><b>' . esc_html($label) . '</b><div class="ccsm-media-row"><input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($id) . '"><span class="ccsm-media-preview">' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Arquivo selecionado (#' . absint($id) . ')</a>' : 'Nenhum arquivo selecionado') . '</span><button type="button" class="button ccsm-select">Selecionar arquivo</button><button type="button" class="button ccsm-clear">Limpar</button></div></div>';
    }
    public static function save_settings(): void {
        self::require_admin('ccsm_settings');
        $old = self::settings();
        $new=$old;
        $provider=sanitize_key(wp_unslash($_POST['provider']??'openai'));
        $new['provider']=array_key_exists($provider,CCSM_Image_Providers::labels()) ? $provider : 'openai';
        foreach (['model','gemini_model','vertex_project','vertex_location','vertex_model','replicate_model'] as $field) {
            $new[$field]=sanitize_text_field(wp_unslash($_POST[$field]??($old[$field]??'')));
        }
        foreach (['key','gemini_key','vertex_credentials','stability_key','replicate_key'] as $field) {
            if (!empty($_POST['clear_'.$field])) {
                $new[$field]='';continue;
            }
            $value=isset($_POST[$field]) ? trim((string)wp_unslash($_POST[$field])) : '';
            if ($value==='') continue;
            if ($field==='vertex_credentials') {
                $credentials=json_decode($value,true);
                if (!is_array($credentials) || ($credentials['type']??'')!=='service_account'
                    || empty($credentials['client_email']) || empty($credentials['private_key'])) {
                    wp_die('O JSON da conta de serviço Google Cloud não é válido.');
                }
                $new[$field]=wp_json_encode($credentials);
            } else {
                $new[$field]=sanitize_text_field($value);
            }
        }
        $new['logo']=absint($_POST['logo']??0);
        $new['dove']=absint($_POST['dove']??0);
        $new['font']=absint($_POST['font']??0);
        $new['address']=sanitize_text_field(wp_unslash($_POST['address']??''));
        $new['footer']=sanitize_text_field(wp_unslash($_POST['footer']??''));
        update_option(self::OPTION, $new, false);
        wp_safe_redirect(admin_url('admin.php?page=ccsm-settings&ccsm_notice=saved')); exit;
    }
    public static function new_screen(): void {
        self::shell('Nova campanha');
        $s = self::settings();
        echo '<div class="ccsm-panel"><h2>Dados da mensagem</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccsm_create'); echo '<input type="hidden" name="action" value="ccsm_create"><div class="ccsm-fields">';
        self::field('theme', 'Tema da mensagem *', 'text', '');
        self::field('pastor', 'Nome do pregador *', 'text', '');
        self::media_field('pastor_photo', 'Foto original do pastor (preferencialmente PNG transparente)', 0);
        self::field('date', 'Data do culto *', 'date', wp_date('Y-m-d'));
        self::field('time1', 'Horário do culto / transmissão *', 'time', '19:00');
        self::field('time2', 'Outro horário do culto (opcional)', 'time', '');
        self::field('address', 'Endereço', 'text', $s['address']);
        echo '<label class="ccsm-field"><b>Cenário</b><select name="scene">';
        foreach (CCSM_Prompts::scenes() as $key=>$label) echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        echo '</select></label>';
        self::field('custom_scene', 'Descrição complementar do cenário', 'text', '', 'Ex.: água que brota das rochas, montanhas ao fundo.');
        echo '<label class="ccsm-field"><b>Estilo</b><select name="mood"><option value="balanced">Equilibrado</option><option value="sober">Mais sóbrio</option><option value="vivid">Mais vibrante</option></select></label></div>';
        echo '<p><button class="button button-primary button-hero">Salvar campanha</button></p></form></div>';
        echo '<p class="ccsm-hint">Após salvar, clique em Gerar 4 artes para consumir a API. A foto do pastor é aplicada apenas à thumbnail gravada.</p></div>';
    }
    public static function create_campaign(): void {
        self::require_admin('ccsm_create');
        $theme = sanitize_text_field(wp_unslash($_POST['theme'] ?? ''));
        $pastor = sanitize_text_field(wp_unslash($_POST['pastor'] ?? ''));
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $time1 = sanitize_text_field(wp_unslash($_POST['time1'] ?? ''));
        $time2 = sanitize_text_field(wp_unslash($_POST['time2'] ?? ''));
        if (!$theme || !$pastor || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time1)) wp_die('Informe tema, pastor, data e hora válidos.');
        $scene = sanitize_key(wp_unslash($_POST['scene'] ?? 'sky'));
        if (!array_key_exists($scene, CCSM_Prompts::scenes())) $scene = 'sky';
        $mood = sanitize_key(wp_unslash($_POST['mood'] ?? 'balanced'));
        if (!in_array($mood, ['balanced','sober','vivid'], true)) $mood = 'balanced';
        $id = wp_insert_post(['post_type'=>self::CPT,'post_title'=>$theme,'post_status'=>'publish'], true);
        if (is_wp_error($id)) wp_die(esc_html($id->get_error_message()));
        update_post_meta($id, self::META, [
            'theme'=>$theme, 'pastor'=>$pastor, 'pastor_photo'=>absint($_POST['pastor_photo'] ?? 0),
            'date'=>$date, 'time1'=>$time1,'time2'=>$time2,
            'address'=>sanitize_text_field(wp_unslash($_POST['address'] ?? '')),
            'scene'=>$scene,'custom_scene'=>sanitize_text_field(wp_unslash($_POST['custom_scene'] ?? '')),
            'mood'=>$mood,'status'=>'draft','arts'=>[], 'error'=>'',
        ]);
        wp_safe_redirect(admin_url('admin.php?page=ccsm&campaign=' . $id . '&ccsm_notice=saved')); exit;
    }
    private static function campaign(int $id): array {
        if (get_post_type($id) !== self::CPT) return [];
        return (array) get_post_meta($id, self::META, true);
    }
    public static function dashboard(): void {
        self::shell('Campanhas');
        $campaign_id = absint($_GET['campaign'] ?? 0);
        if ($campaign_id) self::show_campaign($campaign_id);
        else {
            $posts = get_posts(['post_type'=>self::CPT, 'post_status'=>'publish','posts_per_page'=>40,'orderby'=>'date','order'=>'DESC']);
            echo '<div class="ccsm-panel"><h2>Campanhas recentes</h2>';
            if (!$posts) echo '<p>Nenhuma campanha criada. Clique em Nova campanha.</p>';
            foreach ($posts as $p) {
                $d = self::campaign($p->ID);
                echo '<a class="ccsm-campaign" href="' . esc_url(admin_url('admin.php?page=ccsm&campaign=' . $p->ID)) . '"><strong>' . esc_html($p->post_title) . '</strong><span>' . esc_html($d['pastor'] ?? '') . ' · ' . esc_html($d['status'] ?? '') . '</span><span>Ver campanha →</span></a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    private static function show_campaign(int $id): void {
        $d = self::campaign($id);
        if (!$d) { echo '<p>Campanha não encontrada.</p>'; return; }
        echo '<div class="ccsm-panel"><h2>' . esc_html($d['theme']) . '</h2><p><strong>Pregador:</strong> ' . esc_html($d['pastor']) . ' · <strong>Data:</strong> ' . esc_html($d['date']) . ' · <strong>Horários:</strong> ' . esc_html($d['time1'] . (!empty($d['time2']) ? ' / ' . $d['time2'] : '')) . '</p>';
        $s = self::settings();
        if (!CCSM_Image_Providers::ready($s)) echo '<p class="ccsm-warning">Configure as credenciais do provedor ' . esc_html(CCSM_Image_Providers::labels()[CCSM_Image_Providers::active_provider($s)]) . ' antes de gerar.</p>';
        if (!extension_loaded('gd')) echo '<p class="ccsm-warning">A extensão GD do PHP é necessária.</p>';
        if (!$s['logo']) echo '<p class="ccsm-warning">Selecione a logo oficial em Configurações para garantir identidade visual.</p>';
        if (empty($d['pastor_photo'])) echo '<p class="ccsm-warning">Sem foto do pregador: a quarta imagem será gerada sem retrato. Para retrato fiel, anexe a foto antes de criar a campanha.</p>';
        $busy = in_array($d['status'] ?? '', ['queued','generating'], true);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccsm_generate_' . $id);
        echo '<input type="hidden" name="action" value="ccsm_generate"><input type="hidden" name="campaign_id" value="' . esc_attr($id) . '"><button class="button button-primary button-hero" ' . disabled($busy, true, false) . '>Gerar 4 artes</button></form>';
        echo '<p><strong>Status:</strong> ' . esc_html($d['status'] ?? 'rascunho') . '</p>';
        if (!empty($d['error'])) echo '<p class="ccsm-warning">' . esc_html($d['error']) . '</p>';
        echo '</div><div class="ccsm-grid">';
        $types = ['story_invite'=>'Story · Convite 9:16','story_live'=>'Story · Ao vivo 9:16','thumb_live'=>'Thumbnail · Live 16:9','thumb_recorded'=>'Thumbnail · Gravada 16:9'];
        foreach ($types as $type=>$label) {
            echo '<div class="ccsm-art"><h3>' . esc_html($label) . '</h3>';
            $attachment = absint($d['arts'][$type] ?? 0);
            if ($attachment) {
                echo wp_get_attachment_image($attachment, 'large');
                echo '<p><a class="button" download href="' . esc_url(wp_get_attachment_url($attachment)) . '">Baixar PNG</a> <a class="button" href="' . esc_url(get_edit_post_link($attachment)) . '">Mídia</a></p>';
            } else echo '<p class="ccsm-empty">Aguardando geração</p>';
            echo '</div>';
        }
        echo '</div>';
    }
    public static function start_generation(): void {
        $id = absint($_POST['campaign_id'] ?? 0);
        self::require_admin('ccsm_generate_' . $id);
        $d = self::campaign($id);
        if (!$d) wp_die('Campanha inválida.');
        $s = self::settings();
        if (!CCSM_Image_Providers::ready($s) || !extension_loaded('gd')) wp_die('Configure o provedor de imagem e habilite a extensão GD.');
        if (in_array($d['status'] ?? '', ['queued','generating'], true)) wp_die('Geração já em andamento.');
        $d['status']='queued'; $d['error']=''; $d['arts']=[];
        update_post_meta($id, self::META, $d);
        wp_schedule_single_event(time()+5, 'ccsm_process_campaign', [$id]);
        spawn_cron();
        wp_safe_redirect(admin_url('admin.php?page=ccsm&campaign=' . $id . '&ccsm_notice=queued'));exit;
    }
    public static function retry_art(): void { wp_die('Use Gerar 4 artes para regenerar a campanha.'); }

    public static function process_campaign(int $id): void {
        $lock='ccsm_lock_' . $id;
        if (get_transient($lock)) return;
        set_transient($lock, 1, 15 * MINUTE_IN_SECONDS);
        try {
            $d = self::campaign($id);
            if (!$d || !in_array($d['status'] ?? '', ['queued','generating'], true)) return;
            $d['status']='generating'; update_post_meta($id, self::META, $d);
            $s = self::settings();
            $renderer = new CCSM_Renderer($s);
            $background_story = $renderer->generate_background(CCSM_Prompts::background($d, 'portrait'), '1024x1536');
            if (is_wp_error($background_story)) throw new RuntimeException($background_story->get_error_message());
            $background_thumb = $renderer->generate_background(CCSM_Prompts::background($d, 'landscape'), '1536x1024');
            if (is_wp_error($background_thumb)) throw new RuntimeException($background_thumb->get_error_message());
            foreach (['story_invite','story_live','thumb_live','thumb_recorded'] as $type) {
                $art = $renderer->compose($type, $d, $type==='story_invite'||$type==='story_live' ? $background_story : $background_thumb);
                if (is_wp_error($art)) throw new RuntimeException($type . ': ' . $art->get_error_message());
                $d['arts'][$type] = $art;
                update_post_meta($id, self::META, $d);
            }
            $d['status']='complete'; $d['error']='';
            update_post_meta($id, self::META, $d);
        } catch (Throwable $e) {
            $d = self::campaign($id);
            $d['status']='error'; $d['error']=substr($e->getMessage(),0,700);
            update_post_meta($id, self::META, $d);
            error_log('CCSM generation error: ' . $e->getMessage());
        } finally { delete_transient($lock); }
    }
}
add_action('plugins_loaded', ['CCSM_Plugin','init']);
