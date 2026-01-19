<?php
/**
 * Plugin Name: TWTGR Reviews Tracker
 * Description: Tracking de cliques de Google Review com relatórios filtráveis, export CSV (linha-a-linha) e shortcodes sem conflito (prefixo twtgr_).
 * Version: 1.4.0
 * Author: TWT + ChatGPT
 * Text Domain: twtgr
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TWTGR_VER', '1.4.0' );
define( 'TWTGR_URL',  plugin_dir_url( __FILE__ ) );
define( 'TWTGR_PATH', plugin_dir_path( __FILE__ ) );

global $wpdb;
define( 'TWTGR_TABLE', $wpdb->prefix . 'twtgr_clicks' );

register_activation_hook( __FILE__, function(){
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE " . TWTGR_TABLE . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        person_name VARCHAR(190) NULL,
        position VARCHAR(190) NULL,
        departamento VARCHAR(190) NULL,
        google_user VARCHAR(190) NULL,
        session_id VARCHAR(64) NULL,
        user_id BIGINT UNSIGNED NULL,
        ip_hash CHAR(64) NULL,
        ua_hash CHAR(64) NULL,
        referrer TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY created_at (created_at),
        KEY person_name (person_name),
        KEY position (position),
        KEY departamento (departamento)
    ) $charset_collate;";
    dbDelta( $sql );
    if ( ! get_option( 'twtgr_settings' ) ){
        add_option( 'twtgr_settings', array(
            'acf_review_url_key' => 'google_review_url',
            'acf_person_key'     => 'name',
            'acf_position_key'   => 'position',
            'acf_dept_key'       => 'departamento',
            'acf_company_key'    => 'company_name',
            'anonymize_ip'       => 1,
            'collect_ua'         => 0,
            'per_page_default'   => 25,
            'acf_multi_review_keys' => array(),
        ), '', false );
    }
    if ( ! get_option( 'twtgr_ip_salt' ) ) {
        add_option( 'twtgr_ip_salt', wp_generate_password( 32, true, true ), '', false );
    }
});

class TWTGR_Plugin {
    public static function init(){
        add_shortcode( 'twtgr_review_button', [__CLASS__, 'sc_review_button'] );
        add_shortcode( 'twtgr_review_image',  [__CLASS__, 'sc_review_image'] );
        add_shortcode( 'twtgr_report_table',  [__CLASS__, 'sc_report_table'] );
        add_shortcode( 'twtgr_position_table', [__CLASS__, 'sc_group_table'] );
        add_shortcode( 'twtgr_departamento_table', [__CLASS__, 'sc_group_table'] );
        add_shortcode( 'twtgr_author_report', [__CLASS__, 'sc_author_report'] );

        add_action( 'rest_api_init', [__CLASS__, 'register_rest'] );
        add_action( 'admin_menu', [__CLASS__, 'admin_menu'] );
        add_action( 'wp_enqueue_scripts', [__CLASS__, 'register_assets'] );
        add_action( 'wp', [__CLASS__, 'maybe_localize_current_post'] );

        // Export BO
        add_action( 'admin_post_twtgr_export', [__CLASS__, 'handle_export'] );
        add_action( 'admin_post_nopriv_twtgr_export', [__CLASS__, 'handle_export'] );

        // Export front (?twtgr_export=1)
        add_action( 'template_redirect', [__CLASS__, 'maybe_front_export'] );

        // Delete (BO) cliques selecionados
        add_action( 'admin_post_twtgr_delete', [__CLASS__, 'handle_delete'] );
    }

    /* ===== Bootstraps & assets ===== */

    public static function maybe_front_export(){
        if ( is_admin() ) return;
        if ( isset($_GET['twtgr_export']) && intval($_GET['twtgr_export']) === 1 ) {
            self::handle_export();
        }
    }

    public static function s(){
        $defaults = array(
            'acf_review_url_key' => 'google_review_url',
            'acf_person_key'     => 'name',
            'acf_position_key'   => 'position',
            'acf_dept_key'       => 'departamento',
            'acf_company_key'    => 'company_name',
            'anonymize_ip'       => 1,
            'collect_ua'         => 0,
            'per_page_default'   => 25,
            'acf_multi_review_keys' => array(),
        );
        $s = get_option( 'twtgr_settings', array() );
        return wp_parse_args( is_array($s) ? $s : array(), $defaults );
    }

    public static function register_assets(){
        wp_register_script( 'twtgr-tracker', TWTGR_URL . 'assets/js/tracker.js', array('wp-api-fetch'), TWTGR_VER, true );
        wp_register_style( 'twtgr-admin', TWTGR_URL . 'assets/css/admin.css', array(), TWTGR_VER );
        wp_register_style( 'twtgr-front', TWTGR_URL . 'assets/css/front.css', array(), TWTGR_VER );
    }

    protected static function enqueue_front_css(){
        if ( ! wp_style_is('twtgr-front', 'enqueued') ){
            wp_enqueue_style('twtgr-front');
        }
    }

public static function maybe_localize_current_post(){
    if ( is_admin() ) return;

    $post_id = get_queried_object_id();
    if ( ! $post_id ) $post_id = 0;

    $s = self::s();
    $multi = array();

    // --- 1) URLs do próprio post (se existirem)
    //   a) pelos keys configurados no BO
    $cfg = ( isset($s['acf_multi_review_keys']) && is_array($s['acf_multi_review_keys']) ) ? $s['acf_multi_review_keys'] : array();
    foreach ( $cfg as $meta_key ){
        $val = get_post_meta( $post_id, $meta_key, true );
        if ( $val ) {
            $multi[] = array('post_id'=>(int)$post_id,'key'=>sanitize_key($meta_key),'url'=>esc_url_raw($val));
        }
    }
    //   b) auto-scan por google_review_*
    $all_meta = $post_id ? get_post_meta( $post_id ) : array();
    if ( is_array($all_meta) ){
        foreach ( $all_meta as $meta_key => $values ){
            if ( strpos($meta_key, 'google_review_') === 0 ){
                $val = get_post_meta( $post_id, $meta_key, true );
                if ( $val ){
                    $u = esc_url_raw($val);
                    $exists = false; foreach($multi as $m){ if($m['url']===$u){ $exists=true; break; } }
                    if(!$exists){ $multi[] = array('post_id'=>(int)$post_id,'key'=>sanitize_key($meta_key),'url'=>$u); }
                }
            }
        }
    }

    // --- 2) URLs GLOBAIS (para popups/templates) — em cache
    $global = get_transient('twtgr_multi_urls');
    if ( ! is_array($global) ){
        global $wpdb;
        $like = $wpdb->esc_like('google_review_').'%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                   FROM {$wpdb->postmeta}
                  WHERE meta_key LIKE %s AND meta_value <> '' 
                  LIMIT 1000",
                $like
            ),
            ARRAY_A
        );
        $global = array();
        if ( $rows ){
            foreach( $rows as $r ){
                $url = esc_url_raw( $r['meta_value'] );
                if ( ! $url ) continue;
                $global[] = array(
                    'post_id' => (int)$r['post_id'],
                    'key'     => sanitize_key($r['meta_key']),
                    'url'     => $url,
                );
            }
        }
        set_transient('twtgr_multi_urls', $global, MINUTE_IN_SECONDS * 10);
    }
    if ( is_array($global) ){
        // merge sem duplicar URL
        $seen = wp_list_pluck($multi, 'url');
        foreach ( $global as $g ){
            if ( ! in_array($g['url'], $seen, true) ){
                $multi[] = $g;
                $seen[]  = $g['url'];
            }
        }
    }

    // --- 3) URL "principal" (só para garantir que o JS carrega)
    $review_url = '';
    $primary = get_post_meta( $post_id, sanitize_key($s['acf_review_url_key']), true );
    if ( $primary ) { $review_url = esc_url_raw($primary); }
    if ( ! $review_url && ! empty($multi) ) { $review_url = $multi[0]['url']; }

    // --- 4) Enfileirar sempre (mesmo que review_url esteja vazio; o multi global cuida do resto)
    wp_enqueue_script( 'wp-api-fetch' );
    wp_enqueue_script( 'twtgr-tracker' );
    wp_localize_script( 'twtgr-tracker', 'TWTGR', array(
        'rest' => array(
            'base'  => esc_url_raw( rest_url('twtgr/v1') ),
            'nonce' => wp_create_nonce('wp_rest'),
        ),
        'auto'  => array(
            'post_id'    => (int)$post_id,
            'review_url' => $review_url,
        ),
        'multi' => $multi, // agora vem com post_id + url de TODA a base
    ));
}



    protected static function ensure_scripts(){
        if ( ! wp_script_is( 'twtgr-tracker', 'enqueued' ) ){
            wp_enqueue_script( 'wp-api-fetch' );
            wp_enqueue_script( 'twtgr-tracker' );
        }
        if ( ! wp_scripts()->get_data( 'twtgr-tracker', 'data' ) ){
            wp_localize_script( 'twtgr-tracker', 'TWTGR', array(
                'rest'   => array(
                    'base'  => esc_url_raw( rest_url( 'twtgr/v1' ) ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                ),
            ));
        }
    }

    protected static function current_review_url( $post_id, $url_field, $override ){
        if ( $override ) return esc_url_raw( $override );
        $url = get_post_meta( $post_id, sanitize_key($url_field), true );
        return $url ? esc_url_raw( $url ) : '';
    }

    protected static function placeholder( $msg ){
        if ( is_admin() && ! wp_doing_ajax() ){
            return '<div class="twtgr-ph" style="padding:12px;border:1px dashed #bbb;border-radius:8px;color:#666;font-size:13px;">'
                 . esc_html( $msg ) . '</div>';
        }
        return '';
    }

    /* ===== Shortcodes de clique ===== */

    public static function sc_review_button( $atts, $content = null ){
        $a = shortcode_atts( array(
            'post_id'    => get_the_ID(),
            'text'       => '',
            'class'      => 'twtgr-btn',
            'ask_user'   => 'no',
            'url_field'  => self::s()['acf_review_url_key'],
            'review_url' => '',
            'target'     => '_blank',
        ), $atts, 'twtgr_review_button' );
        $post_id = intval( $a['post_id'] );
        if ( ! $post_id ) return self::placeholder('Sem post_id.');
        $review_url = self::current_review_url( $post_id, $a['url_field'], $a['review_url'] );
        if ( ! $review_url ) return self::placeholder('Sem URL de review (ACF ou review_url="").');
        self::ensure_scripts();
        self::enqueue_front_css();
        $ask  = strtolower( $a['ask_user'] ) === 'yes' ? 'yes' : 'no';
        $text = $a['text'] ? $a['text'] : ( $content ? $content : 'Avaliar no Google' );
        return sprintf(
            '<a href="%s" class="%s twtgr-link twtgr-pill" data-twtgr="1" data-post-id="%d" data-ask-user="%s" rel="nofollow noopener" target="%s"><span class="twtgr-g">G</span>%s</a>',
            esc_url( $review_url ), esc_attr($a['class']), $post_id, esc_attr($ask), esc_attr($a['target']), esc_html($text)
        );
    }

    public static function sc_review_image( $atts, $content = null ){
        $a = shortcode_atts( array(
            'post_id'    => get_the_ID(),
            'img_id'     => 0,
            'img_url'    => '',
            'img_size'   => 'medium',
            'class'      => 'twtgr-review-img',
            'ask_user'   => 'no',
            'url_field'  => self::s()['acf_review_url_key'],
            'review_url' => '',
            'target'     => '_blank',
            'alt'        => 'Google Review',
        ), $atts, 'twtgr_review_image' );
        $post_id = intval( $a['post_id'] );
        if ( ! $post_id ) return self::placeholder('Sem post_id.');
        $review_url = self::current_review_url( $post_id, $a['url_field'], $a['review_url'] );
        if ( ! $review_url ) return self::placeholder('Sem URL de review (ACF ou review_url="").');
        self::ensure_scripts();
        self::enqueue_front_css();
        $img_html = '';
        if ( $a['img_id'] ){
            $img_html = wp_get_attachment_image( intval($a['img_id']), $a['img_size'], false, array(
                'alt' => esc_attr($a['alt']),
                'class' => 'twtgr-img-el',
            ));
        } elseif ( $a['img_url'] ){
            $img_html = sprintf('<img src="%s" alt="%s" class="twtgr-img-el" />', esc_url($a['img_url']), esc_attr($a['alt']));
        } else {
            $img_html = '<span class="twtgr-img-el">Google Review</span>';
        }
        return sprintf(
            '<a href="%s" class="%s twtgr-link" data-twtgr="1" data-post-id="%d" data-ask-user="%s" rel="nofollow noopener" target="%s">%s</a>',
            esc_url( $review_url ), esc_attr($a['class']), $post_id, esc_attr($a['ask_user']), esc_attr($a['target']), $img_html
        );
    }

    /* ===== Shortcodes de relatório ===== */

    public static function sc_report_table( $atts ){
        $a = shortcode_atts( array(
            'from' => '',
            'to'   => '',
            'post_type' => '',
            'export' => 'no',
            'per_page' => 0,
        ), $atts, 'twtgr_report_table' );
        self::enqueue_front_css();
        ob_start();
        echo '<div class="twtgr-report">';
        $allow_export = ( $a['export']==='yes' && current_user_can('edit_posts') );
        self::render_overview_table( $a['from'], $a['to'], $a['post_type'], $allow_export, false, intval($a['per_page']) );
        echo '</div>';
        return ob_get_clean();
    }

    public static function sc_group_table( $atts, $content = null, $tag = '' ){
        $a = shortcode_atts( array(
            'from' => '',
            'to'   => '',
            'post_type' => '',
            'export' => 'no',
            'per_page' => 0,
        ), $atts, $tag );
        self::enqueue_front_css();
        $allow_export = ( $a['export']==='yes' && current_user_can('edit_posts') );
        $field = $tag === 'twtgr_position_table' ? 'position' : 'departamento';
        ob_start();
        echo '<div class="twtgr-report">';
        self::render_group_table( $field, $a['from'], $a['to'], $a['post_type'], $allow_export, false, intval($a['per_page']) );
        echo '</div>';
        return ob_get_clean();
    }

    public static function sc_author_report( $atts ){
        if ( ! is_user_logged_in() ) return '';
        $user_id = get_current_user_id();
        $a = shortcode_atts( array(
            'from' => '',
            'to'   => '',
            'post_type' => '',
            'export' => 'yes',
            'per_page' => 0,
        ), $atts, 'twtgr_author_report' );
        self::enqueue_front_css();
        ob_start();
        echo '<div class="twtgr-report">';
        self::render_overview_table( $a['from'], $a['to'], $a['post_type'], $a['export']==='yes', $user_id, intval($a['per_page']) );
        echo '<hr />';
        self::render_group_table( 'position', $a['from'], $a['to'], $a['post_type'], false, $user_id, intval($a['per_page']) );
        echo '<hr />';
        self::render_group_table( 'departamento', $a['from'], $a['to'], $a['post_type'], false, $user_id, intval($a['per_page']) );
        echo '</div>';
        return ob_get_clean();
    }

    /* ===== REST ===== */

    public static function register_rest(){
        register_rest_route( 'twtgr/v1', '/click', array(
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'rest_click'],
            'permission_callback' => function(){ return wp_verify_nonce( $_SERVER['HTTP_X_WP_NONCE'] ?? '', 'wp_rest' ); },
            'args' => array(
                'post_id'     => array('required'=>true, 'type'=>'integer'),
                'google_user' => array('required'=>false, 'type'=>'string'),
            ),
        ));
    }

    public static function rest_click( WP_REST_Request $req ){
        global $wpdb; $s = self::s();
        $post_id  = intval( $req->get_param('post_id') );
        if ( ! $post_id || get_post_status( $post_id ) === false ) {
            return new WP_REST_Response( array('ok'=>false, 'msg'=>'invalid post'), 400 );
        }
        $google_user = sanitize_text_field( $req->get_param('google_user') ?? '' );
        $person   = get_post_meta( $post_id, sanitize_key($s['acf_person_key']), true );
        $position = get_post_meta( $post_id, sanitize_key($s['acf_position_key']), true );
        $dept     = get_post_meta( $post_id, sanitize_key($s['acf_dept_key']), true ); // 'departamento'
        $ip_hash  = $s['anonymize_ip'] ? hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . get_option('twtgr_ip_salt','twtgr')) : null;
        $ua_hash  = $s['collect_ua'] ? hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') ) : null;
        $session_id = isset($_COOKIE['twtgr_sid']) ? sanitize_text_field($_COOKIE['twtgr_sid']) : wp_generate_password(20, false, false);
        if ( ! isset($_COOKIE['twtgr_sid']) ) {
            setcookie( 'twtgr_sid', $session_id, time()+60*60*24*365, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
        }
        $ref = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
        $wpdb->insert( TWTGR_TABLE, array(
            'post_id'      => $post_id,
            'person_name'  => $person,
            'position'     => $position,
            'departamento' => $dept,
            'google_user'  => $google_user ?: null,
            'session_id'   => $session_id,
            'user_id'      => get_current_user_id() ?: null,
            'ip_hash'      => $ip_hash,
            'ua_hash'      => $ua_hash,
            'referrer'     => $ref,
            'created_at'   => current_time( 'mysql' ),
        ), array('%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s') );
        return new WP_REST_Response( array('ok'=>true), 200 );
    }

    /* ===== Admin menu ===== */

    public static function admin_menu(){
        add_menu_page('TWTGR Reviews','TWTGR Reviews','edit_posts','twtgr-reviews',[__CLASS__,'admin_page'],'dashicons-thumbs-up',65);
        add_submenu_page('twtgr-reviews','Relatórios','Relatórios','edit_posts','twtgr-reviews',[__CLASS__,'admin_page']);
        add_submenu_page('twtgr-reviews','Definições','Definições','manage_options','twtgr-settings',[__CLASS__,'admin_settings']);
    }

    protected static function post_type_label( $pt ){
        $obj = get_post_type_object( $pt );
        return $obj ? ( $obj->labels->singular_name ?: $obj->label ) : $pt;
    }

    /* ===== Data helpers ===== */

    // Antigo agregado (mantido caso precises)
    protected static function fetch_rows( $from='', $to='', $post_type='', $author_id=false ){
        global $wpdb;
        $where = 'WHERE 1=1';
        $args = array();

        if ( $from ) { $where .= ' AND c.created_at >= %s'; $args[] = $from; }
        if ( $to )   { $where .= ' AND c.created_at <= %s'; $args[] = $to; }

        $join = " INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id ";
        if ( $post_type ){
            $where .= ' AND p.post_type = %s'; $args[] = $post_type;
        }
        if ( $author_id ){
            $where .= ' AND p.post_author = %d'; $args[] = intval($author_id);
        }

        $sql = "SELECT c.post_id,
                       COALESCE( (SELECT gu.google_user FROM ".TWTGR_TABLE." gu WHERE gu.post_id=c.post_id AND gu.google_user IS NOT NULL ORDER BY gu.id DESC LIMIT 1), c.person_name, '—') AS pessoa_label,
                       COUNT(*) AS clicks,
                       COUNT(DISTINCT CONCAT_WS('|', COALESCE(c.ip_hash,''), COALESCE(c.session_id,''))) AS visitantes,
                       p.post_title AS post_title,
                       p.post_type  AS post_type
                FROM " . TWTGR_TABLE . " c
                $join
                $where
                GROUP BY c.post_id, p.post_title, p.post_type
                ORDER BY clicks DESC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
    }

    // Detalhe linha-a-linha com fallbacks fortes para Departamento
    protected static function fetch_click_rows( $from='', $to='', $post_type='', $author_id=false ){
        global $wpdb;
        $settings = self::s();
        $mk = sanitize_key($settings['acf_dept_key']); // 'departamento'
        $pm = $wpdb->postmeta;

        $where = 'WHERE 1=1'; $args = array();
        if ( $from ) { $where .= ' AND c.created_at >= %s'; $args[] = $from; }
        if ( $to )   { $where .= ' AND c.created_at <= %s'; $args[] = $to; }

        $join = " INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id ";
        // meta fallback (departamento / department)
        $join .= " LEFT JOIN $pm pm1 ON pm1.post_id = p.ID AND pm1.meta_key = '$mk' ";
        $join .= " LEFT JOIN $pm pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'department' ";

        if ( $post_type ){ $where .= ' AND p.post_type = %s'; $args[] = $post_type; }
        if ( $author_id ){ $where .= ' AND p.post_author = %d'; $args[] = intval($author_id); }

        $sql = "SELECT
                    c.id         AS id,
                    p.ID         AS post_id,
                    p.post_title AS post_title,
                    p.post_type  AS post_type,
                    COALESCE(NULLIF(c.person_name,''), NULLIF(c.google_user,''), '—') AS nome,
                    COALESCE(NULLIF(c.position,''), '—') AS position,
                    COALESCE(NULLIF(c.departamento,''), NULLIF(pm1.meta_value,''), NULLIF(pm2.meta_value,''), '—') AS departamento,
                    c.google_user AS google_user,
                    c.referrer    AS referrer,
                    c.created_at  AS created_at
                FROM ".TWTGR_TABLE." c
                $join
                $where
                ORDER BY c.created_at DESC, c.id DESC";

        $rows = $wpdb->get_results( $wpdb->prepare($sql, $args), ARRAY_A );

        // Extra: tenta taxonomias se ainda vier '—'
        if ( ! empty($rows) ){
            foreach ( $rows as &$r ){
                if ( $r['departamento'] === '—' || $r['departamento'] === '' || $r['departamento'] === null ){
                    $terms = get_the_terms( $r['post_id'], 'departamento' );
                    if ( is_wp_error($terms) || empty($terms) ){
                        $terms = get_the_terms( $r['post_id'], 'department' );
                    }
                    if ( ! is_wp_error($terms) && ! empty($terms) ){
                        $r['departamento'] = implode(', ', wp_list_pluck($terms, 'name'));
                    }
                }
            }
            unset($r);
        }

        return $rows;
    }

    /* ===== UI helpers ===== */

    protected static function render_filters_form( $from, $to, $post_type ){
        $pts = get_post_types( array('public'=>true), 'objects' );
        echo '<form method="get" class="twtgr-filters">';
        echo '<input type="hidden" name="page" value="twtgr-reviews"/>';
        echo '<label>De: <input type="date" name="from" value="'.esc_attr($from).'"/></label>';
        echo '<label>Até: <input type="date" name="to" value="'.esc_attr($to).'"/></label>';
        echo '<label>Tipo de cartão: <select name="post_type"><option value="">Todos</option>';
        foreach( $pts as $pt => $obj ){
            printf('<option value="%s"%s>%s</option>', esc_attr($pt), selected($post_type,$pt,false), esc_html($obj->labels->singular_name));
        }
        echo '</select></label>';
        echo '<button class="button button-primary">Filtrar</button>';
        echo '</form>';
    }

    protected static function pagination( $total_items, $per_page, $current, $param ){
        if ( $per_page <= 0 ) return '';
        $total_pages = max(1, (int)ceil($total_items / $per_page));
        if ( $total_pages <= 1 ) return '';
        $args = $_GET;
        $out  = '<div class="twtgr-pagination" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">';
        $out .= '<span style="opacity:.8;font-size:12px;">Página '.intval($current).' de '.$total_pages.' ('.$total_items.' registos)</span>';
        $base = is_admin() ? ( admin_url('admin.php?page=twtgr-reviews') ) : ( function_exists('get_permalink') ? get_permalink() : home_url('/') );

        $link = function($page) use ($args,$param,$base){
            $args[$param] = $page;
            return esc_url( add_query_arg($args, $base) );
        };

        if ( $current > 1 ) {
            $out .= '<a class="button" href="'.$link($current-1).'">&laquo; Anterior</a>';
        }
        for ( $i=1; $i<=$total_pages; $i++ ){
            if ( $i === intval($current) ) {
                $out .= '<span class="button" style="pointer-events:none;opacity:.7;">'.$i.'</span>';
            } else {
                $out .= '<a class="button" href="'.$link($i).'">'.$i.'</a>';
            }
        }
        if ( $current < $total_pages ) {
            $out .= '<a class="button" href="'.$link($current+1).'">Seguinte &raquo;</a>';
        }
        $out .= '</div>';
        return $out;
    }

    /* ===== Render: Visão geral (linha-a-linha) ===== */

    protected static function render_overview_table( $from='', $to='', $post_type='', $show_export=false, $author_id=false, $per_page=0 ){
        $s = self::s();
        if ( ! $per_page ) $per_page = intval($s['per_page_default']);
        $rows_all = self::fetch_click_rows( $from, $to, $post_type, $author_id );

        // Paginação
        $param = is_admin() ? 'pg' : 'pg';
        $current = isset($_GET[$param]) ? max(1, intval($_GET[$param])) : 1;
        $total = count($rows_all);
        $offset = ($current-1) * $per_page;
        $rows = array_slice($rows_all, $offset, $per_page);

        echo '<h2>Visão geral</h2>';
        if ( is_admin() && current_user_can('edit_posts') ){
            self::render_filters_form( $from, $to, $post_type );
        }

        // Export – um botão, CSV completo por linhas
        if ( $show_export ){
            $args = array('from'=>$from,'to'=>$to,'post_type'=>$post_type);
            if ( $author_id ){
                $args['twtgr_export'] = 1;
                $args['author_id']    = intval($author_id);
                $args['front']        = 1;
                $args['_wpnonce']     = wp_create_nonce('twtgr_export');
                $base = home_url( '/' );
            } else {
                $args['action'] = 'twtgr_export';
                $base = admin_url('admin-post.php');
            }
            $url = add_query_arg( $args, $base );
            echo '<p class="twtgr-export-buttons"><a class="button" href="'.esc_url($url).'">Exportar CSV</a></p>';
        }

        // Tabela com checkboxes e delete em massa (apenas BO)
        $can_delete = is_admin() && current_user_can('delete_posts');
        if ( $can_delete ) {
            echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
            wp_nonce_field('twtgr_delete_nonce','twtgr_delete_nonce');
            echo '<input type="hidden" name="action" value="twtgr_delete" />';
        }

        echo '<table class="widefat striped"><thead><tr>';
        if ( $can_delete ) echo '<th style="width:28px;"><input type="checkbox" onclick="document.querySelectorAll(\'.twtgr-delcb\').forEach(cb=>cb.checked=this.checked);" /></th>';
        echo '  <th>Data/Hora</th>
                <th>Título</th>
                <th>Tipo de cartão</th>
                <th>Nome</th>
                <th>Posição</th>
                <th>Departamento</th>
              </tr></thead><tbody>';

        if ( empty($rows) ){
            echo '<tr><td'.($can_delete?' colspan="7"':' colspan="6"').'>Sem dados.</td></tr>';
        } else {
            foreach( $rows as $r ){
                echo '<tr>';
                if ( $can_delete ) {
                    echo '<td><input class="twtgr-delcb" type="checkbox" name="ids[]" value="'.intval($r['id']).'"/></td>';
                }
                printf(
                    '<td>%s</td><td><a href="%s" target="_blank">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>',
                    esc_html( $r['created_at'] ),
                    esc_url( get_permalink( $r['post_id'] ) ),
                    esc_html( $r['post_title'] ?: '—' ),
                    esc_html( self::post_type_label( $r['post_type'] ) ),
                    esc_html( $r['nome'] ),
                    esc_html( $r['position'] ),
                    esc_html( $r['departamento'] )
                );
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        echo self::pagination( $total, $per_page, $current, $param );

        if ( $can_delete ) {
            echo '<p><button type="submit" class="button button-secondary" onclick="return confirm(\'Apagar as submissões selecionadas?\');">Apagar selecionados</button></p>';
            echo '</form>';
        }
    }

    /* ===== Render: Posição / Departamento (linha-a-linha + paginação) ===== */

    protected static function render_group_table( $field, $from='', $to='', $post_type='', $show_export=false, $author_id=false, $per_page=0 ){
        $s = self::s();
        if ( ! $per_page ) $per_page = intval($s['per_page_default']);

        $col  = $field === 'position' ? 'position' : 'departamento';
        $rows_all = self::fetch_click_rows( $from, $to, $post_type, $author_id );

        // Ordenar por grupo (estável por data desc dentro do grupo)
        usort($rows_all, function($a,$b) use ($col){
            $ga = strtolower((string)($col==='position' ? $a['position'] : $a['departamento']));
            $gb = strtolower((string)($col==='position' ? $b['position'] : $b['departamento']));
            if ( $ga === $gb ) return strcmp($b['created_at'], $a['created_at']);
            return strcmp($ga, $gb);
        });

        // Paginação específica por tabela
        $param = $col==='position' ? 'pg_pos' : 'pg_dep';
        $current = isset($_GET[$param]) ? max(1, intval($_GET[$param])) : 1;
        $total = count($rows_all);
        $offset = ($current-1) * $per_page;
        $rows = array_slice($rows_all, $offset, $per_page);

        $title = ($col==='position') ? 'Por posição' : 'Por departamento';
        echo '<h2>'.esc_html($title).'</h2>';

        if ( $show_export ){
            $args = array('from'=>$from,'to'=>$to,'post_type'=>$post_type,'group'=>$col);
            if ( $author_id ){
                $args['twtgr_export'] = 1;
                $args['author_id']    = intval($author_id);
                $args['front']        = 1;
                $args['_wpnonce']     = wp_create_nonce('twtgr_export');
                $base = home_url( '/' );
            } else {
                $args['action'] = 'twtgr_export';
                $base = admin_url('admin-post.php');
            }
            $url = add_query_arg( $args, $base );
            echo '<p class="twtgr-export-buttons"><a class="button" href="'.esc_url($url).'">Exportar CSV</a></p>';
        }

        echo '<table class="widefat striped"><thead><tr>
                <th>'.esc_html( ucfirst($col) ).'</th>
                <th>Data/Hora</th>
                <th>Título</th>
                <th>Tipo de cartão</th>
                <th>Nome</th>
              </tr></thead><tbody>';

        if ( empty($rows) ){
            echo '<tr><td colspan="5">Sem dados.</td></tr>';
        } else {
            foreach ( $rows as $r ){
                $grp = $col==='position' ? $r['position'] : $r['departamento'];
                printf(
                    '<tr><td>%s</td><td>%s</td><td><a href="%s" target="_blank">%s</a></td><td>%s</td><td>%s</td></tr>',
                    esc_html( $grp ?: '—' ),
                    esc_html( $r['created_at'] ),
                    esc_url( get_permalink( $r['post_id'] ) ),
                    esc_html( $r['post_title'] ?: '—' ),
                    esc_html( self::post_type_label( $r['post_type'] ) ),
                    esc_html( $r['nome'] )
                );
            }
        }
        echo '</tbody></table>';

        echo self::pagination( $total, $per_page, $current, $param );
    }

    /* ===== Export (CSV linha-a-linha) ===== */

    public static function handle_export(){
        $from      = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
        $to        = isset($_GET['to'])   ? sanitize_text_field($_GET['to'])   : '';
        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
        $author_id = isset($_GET['author_id']) ? intval($_GET['author_id']) : false;
        $group     = isset($_GET['group']) ? sanitize_key($_GET['group']) : '';
        $front     = isset($_GET['front']) ? intval($_GET['front']) : 0;

        // Permissões
        if ( current_user_can('edit_posts') ){
            // ok (BO)
        } elseif ( $front && $author_id && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'twtgr_export' ) ){
            if ( ! is_user_logged_in() || get_current_user_id() !== $author_id ){
                wp_die('Sem permissões.');
            }
        } else {
            wp_die('Sem permissões.');
        }

        $rows = self::fetch_click_rows( $from, $to, $post_type, $author_id );

        if ( $group === 'position' || $group === 'departamento' ){
            usort($rows, function($a,$b) use ($group){
                $ga = strtolower((string)($group==='position' ? $a['position'] : $a['departamento']));
                $gb = strtolower((string)($group==='position' ? $b['position'] : $b['departamento']));
                return strcmp($ga,$gb);
            });
        }

        $headers = array('Data/Hora','Post ID','Título','Tipo de cartão','Nome','Posição','Departamento','Google User','Referrer');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="twtgr_export_' . date('Y-m-d') . '.csv"');

        $fh = fopen('php://output', 'w');
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
        fputcsv($fh, $headers, ';');

        foreach( $rows as $r ){
            fputcsv($fh, array(
                $r['created_at'],
                $r['post_id'],
                $r['post_title'],
                self::post_type_label($r['post_type']),
                $r['nome'],
                $r['position'],
                $r['departamento'],
                $r['google_user'],
                $r['referrer'],
            ), ';');
        }
        fclose($fh);
        exit;
    }

    /* ===== Delete (BO) ===== */

    public static function handle_delete(){
        if ( ! current_user_can('delete_posts') ) {
            wp_die('Sem permissões.');
        }
        check_admin_referer('twtgr_delete_nonce','twtgr_delete_nonce');

        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
        if ( empty($ids) ) {
            wp_redirect( admin_url('admin.php?page=twtgr-reviews&deleted=0') );
            exit;
        }
        global $wpdb;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query( $wpdb->prepare("DELETE FROM ".TWTGR_TABLE." WHERE id IN ($in)", $ids) );

        wp_redirect( admin_url('admin.php?page=twtgr-reviews&deleted='.count($ids)) );
        exit;
    }

    /* ===== Admin pages ===== */

 public static function admin_page(){
    if ( ! current_user_can('edit_posts') ) return;
    wp_enqueue_style('twtgr-admin');

    $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $to   = isset($_GET['to'])   ? sanitize_text_field($_GET['to'])   : '';
    $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';

    echo '<div class="wrap"><h1>TWTGR — Relatórios</h1>';

    if ( isset($_GET['deleted']) ) {
        $n = intval($_GET['deleted']);
        echo '<div class="updated notice"><p>' . sprintf('%d submissões removidas.', $n) . '</p></div>';
    }

    self::render_overview_table( $from, $to, $post_type, true, false );
    echo '<hr />';
    self::render_group_table('position', $from, $to, $post_type, true, false);
    echo '<hr />';
    self::render_group_table('departamento', $from, $to, $post_type, true, false);
    echo '</div>';
}

public static function admin_settings(){
    if ( isset($_POST['twtgr_save']) && check_admin_referer('twtgr_save_settings') ){
        $s = array(
            'acf_review_url_key' => isset($_POST['acf_review_url_key']) ? sanitize_key($_POST['acf_review_url_key']) : 'google_review_url',
            'acf_person_key'     => isset($_POST['acf_person_key'])     ? sanitize_key($_POST['acf_person_key'])     : 'name',
            'acf_position_key'   => isset($_POST['acf_position_key'])   ? sanitize_key($_POST['acf_position_key'])   : 'position',
            'acf_dept_key'       => isset($_POST['acf_dept_key'])       ? sanitize_key($_POST['acf_dept_key'])       : 'departamento',
            'acf_company_key'    => isset($_POST['acf_company_key'])    ? sanitize_key($_POST['acf_company_key'])    : 'company_name',
            'anonymize_ip'       => isset($_POST['anonymize_ip']) ? 1 : 0,
            'collect_ua'         => isset($_POST['collect_ua'])   ? 1 : 0,
            'per_page_default'   => isset($_POST['per_page_default']) ? max(5, intval($_POST['per_page_default'])) : 25,
            'acf_multi_review_keys' => array(),
        );

        // Ler lista multi (um por linha / vírgulas / ponto-e-vírgula)
        $raw = isset($_POST['acf_multi_review_keys_raw']) ? (string) $_POST['acf_multi_review_keys_raw'] : '';
        $keys = preg_split('/[\r\n,;]+/', $raw);
        $keys = array_map('trim', $keys);
        $keys = array_filter($keys);
        $keys = array_map('sanitize_key', $keys);
        $keys = array_values(array_unique($keys));
        $s['acf_multi_review_keys'] = $keys;

        update_option( 'twtgr_settings', $s, false );
        echo '<div class="updated"><p>Guardado.</p></div>';
    }

    $s = self::s();

    echo '<div class="wrap"><h1>TWTGR — Definições</h1><form method="post">';
    wp_nonce_field('twtgr_save_settings');

    echo '<table class="form-table">';

    echo '<tr><th>ACF — campo URL Review</th><td><input name="acf_review_url_key" value="' . esc_attr($s['acf_review_url_key']) . '" class="regular-text"/></td></tr>';
    echo '<tr><th>ACF — Nome (pessoa)</th><td><input name="acf_person_key" value="' . esc_attr($s['acf_person_key']) . '" class="regular-text"/></td></tr>';
    echo '<tr><th>ACF — Posição</th><td><input name="acf_position_key" value="' . esc_attr($s['acf_position_key']) . '" class="regular-text"/></td></tr>';
    echo '<tr><th>ACF — Departamento</th><td><input name="acf_dept_key" value="' . esc_attr($s['acf_dept_key']) . '" class="regular-text"/></td></tr>';
    echo '<tr><th>ACF — Empresa</th><td><input name="acf_company_key" value="' . esc_attr($s['acf_company_key']) . '" class="regular-text"/></td></tr>';

    // NOVO CAMPO — lista de vários campos ACF URL
    echo '<tr><th>ACF — Campos URL Review (um por linha)</th><td>';
    echo '<textarea name="acf_multi_review_keys_raw" rows="6" class="large-text code" placeholder="google_review_bmw_porto&#10;google_review_bmw_gaia&#10;google_review_mini_porto">';
    echo esc_textarea( implode("\n", isset($s['acf_multi_review_keys']) && is_array($s['acf_multi_review_keys']) ? $s['acf_multi_review_keys'] : array() ) );
    echo '</textarea>';
    echo '<p class="description">Lista adicional de campos ACF de URL de review. Um por linha (ou separados por vírgulas). O plugin vai detetar e rastrear todos os links que usem estas URLs.</p>';
    echo '</td></tr>';

    echo '<tr><th>Linhas por página (paginação)</th><td>
            <input name="per_page_default" type="number" min="5" step="1" value="' . esc_attr($s['per_page_default']) . '" class="small-text"/>
            <span class="description">Aplicado por defeito em todas as tabelas.</span>
          </td></tr>';

    echo '<tr><th>Anonymizar IP</th><td><label>
            <input type="checkbox" name="anonymize_ip" ' . checked($s['anonymize_ip'], 1, false) . '/> Ativado
          </label></td></tr>';

    echo '<tr><th>Guardar User-Agent (hash)</th><td><label>
            <input type="checkbox" name="collect_ua" ' . checked($s['collect_ua'], 1, false) . '/> Ativado
          </label></td></tr>';

    echo '</table>';

    echo '<p><button class="button button-primary" name="twtgr_save" value="1">Guardar alterações</button></p>';
    echo '</form></div>';
}
}
TWTGR_Plugin::init();
