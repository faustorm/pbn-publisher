<?php
/**
 * Plugin Name: PBN Publisher
 * Description: Mejoras en la API REST de WordPress para publicación remota desde la PBN.
 *              Añade endpoints personalizados y metadatos para gestión centralizada.
 * Version: 1.2.0
 * Author: Fausto
 * Text Domain: pbn-publisher
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PBN_PUBLISHER_VERSION', '1.3.0');
define('PBN_PUBLISHER_SLUG', 'pbn-publisher/pbn-publisher.php');
define('PBN_PUBLISHER_UPDATE_URL', 'https://raw.githubusercontent.com/faustorm/pbn-publisher/main/update-info.json');

class PBN_Publisher {

    public function __construct() {
        add_action('rest_api_init', [$this, 'registrar_endpoints']);
        add_action('rest_api_init', [$this, 'registrar_campos_meta']);

        // Auto-update desde GitHub
        add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2);

        // Auto-instalar actualizaciones sin intervención
        add_filter('auto_update_plugin', function ($update, $item) {
            return (isset($item->slug) && $item->slug === 'pbn-publisher') ? true : $update;
        }, 10, 2);
    }

    /**
     * Registra endpoints personalizados para la PBN.
     */
    public function registrar_endpoints() {

        // GET /wp-json/pbn/v1/check-update — Fuerza comprobación de actualización (debug)
        register_rest_route('pbn/v1', '/check-update', [
            'methods'  => 'GET',
            'callback' => [$this, 'endpoint_check_update'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        // GET /wp-json/pbn/v1/info — Información del blog para el índice
        register_rest_route('pbn/v1', '/info', [
            'methods'  => 'GET',
            'callback' => [$this, 'endpoint_info'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);

        // GET /wp-json/pbn/v1/stats — Estadísticas de publicación
        register_rest_route('pbn/v1', '/stats', [
            'methods'  => 'GET',
            'callback' => [$this, 'endpoint_stats'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);

        // POST /wp-json/pbn/v1/publish — Publicar con metadatos PBN
        register_rest_route('pbn/v1', '/publish', [
            'methods'  => 'POST',
            'callback' => [$this, 'endpoint_publish'],
            'permission_callback' => function () {
                return current_user_can('publish_posts');
            },
            'args' => [
                'title'      => ['required' => true, 'type' => 'string'],
                'content'    => ['required' => true, 'type' => 'string'],
                'status'     => ['default' => 'draft', 'type' => 'string'],
                'categories' => ['default' => [], 'type' => 'array'],
                'tags'       => ['default' => [], 'type' => 'array'],
                'excerpt'    => ['default' => '', 'type' => 'string'],
                'meta_description' => ['default' => '', 'type' => 'string'],
                'focus_keyword'    => ['default' => '', 'type' => 'string'],
                'pbn_source'       => ['default' => 'claude-agent', 'type' => 'string'],
            ],
        ]);

        // POST /wp-json/pbn/v1/bulk-publish — Publicar varios artículos de golpe
        register_rest_route('pbn/v1', '/bulk-publish', [
            'methods'  => 'POST',
            'callback' => [$this, 'endpoint_bulk_publish'],
            'permission_callback' => function () {
                return current_user_can('publish_posts');
            },
            'args' => [
                'posts' => ['required' => true, 'type' => 'array'],
            ],
        ]);
    }

    /**
     * Registra campos meta personalizados en la REST API de posts.
     */
    public function registrar_campos_meta() {
        $meta_fields = ['pbn_source', 'pbn_focus_keyword', 'pbn_meta_description'];

        foreach ($meta_fields as $field) {
            register_post_meta('post', $field, [
                'show_in_rest' => true,
                'single'       => true,
                'type'         => 'string',
            ]);
        }
    }

    /**
     * Devuelve información del blog para el índice de la PBN.
     */
    public function endpoint_info() {
        return rest_ensure_response([
            'name'        => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url'         => home_url(),
            'language'    => get_locale(),
            'timezone'    => wp_timezone_string(),
            'categories'  => $this->obtener_categorias(),
            'tags'        => $this->obtener_tags_populares(),
            'post_count'  => (int) wp_count_posts()->publish,
            'plugin_version' => PBN_PUBLISHER_VERSION,
        ]);
    }

    /**
     * Devuelve estadísticas de publicación.
     */
    public function endpoint_stats() {
        $counts = wp_count_posts();

        // Posts de los últimos 30 días
        $recientes = new WP_Query([
            'post_type'   => 'post',
            'post_status' => 'publish',
            'date_query'  => [['after' => '30 days ago']],
            'fields'      => 'ids',
        ]);

        // Posts publicados vía PBN
        $pbn_posts = new WP_Query([
            'post_type'   => 'post',
            'meta_key'    => 'pbn_source',
            'fields'      => 'ids',
        ]);

        return rest_ensure_response([
            'total_publicados' => (int) $counts->publish,
            'borradores'       => (int) $counts->draft,
            'ultimos_30_dias'  => $recientes->found_posts,
            'publicados_via_pbn' => $pbn_posts->found_posts,
        ]);
    }

    /**
     * Publica un artículo con metadatos PBN.
     */
    public function endpoint_publish($request) {
        $post_data = [
            'post_title'   => sanitize_text_field($request['title']),
            'post_content' => wp_kses_post($request['content']),
            'post_status'  => sanitize_text_field($request['status']),
            'post_excerpt' => sanitize_text_field($request['excerpt']),
            'post_type'    => 'post',
        ];

        if (!empty($request['categories'])) {
            $post_data['post_category'] = array_map('intval', $request['categories']);
        }

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return new WP_Error('pbn_publish_error', $post_id->get_error_message(), ['status' => 500]);
        }

        // Tags
        if (!empty($request['tags'])) {
            wp_set_post_tags($post_id, $request['tags']);
        }

        // Meta PBN
        update_post_meta($post_id, 'pbn_source', sanitize_text_field($request['pbn_source']));

        if (!empty($request['focus_keyword'])) {
            update_post_meta($post_id, 'pbn_focus_keyword', sanitize_text_field($request['focus_keyword']));
        }
        if (!empty($request['meta_description'])) {
            update_post_meta($post_id, 'pbn_meta_description', sanitize_text_field($request['meta_description']));
        }

        return rest_ensure_response([
            'id'     => $post_id,
            'link'   => get_permalink($post_id),
            'status' => get_post_status($post_id),
            'message' => 'Artículo publicado correctamente vía PBN.',
        ]);
    }

    /**
     * Publica varios artículos de golpe.
     */
    public function endpoint_bulk_publish($request) {
        $posts = $request['posts'];
        $resultados = [];

        foreach ($posts as $post) {
            $sub_request = new WP_REST_Request('POST', '/pbn/v1/publish');
            $sub_request->set_body_params($post);
            $response = $this->endpoint_publish($sub_request);

            if (is_wp_error($response)) {
                $resultados[] = [
                    'title' => $post['title'] ?? '(sin título)',
                    'error' => $response->get_error_message(),
                ];
            } else {
                $resultados[] = $response->get_data();
            }
        }

        return rest_ensure_response([
            'total'      => count($posts),
            'resultados' => $resultados,
        ]);
    }

    /**
     * Obtiene las categorías del blog.
     */
    private function obtener_categorias() {
        $cats = get_categories(['hide_empty' => false]);
        return array_map(function ($cat) {
            return ['id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug];
        }, $cats);
    }

    /**
     * Obtiene los tags más populares.
     */
    private function obtener_tags_populares() {
        $tags = get_tags(['orderby' => 'count', 'order' => 'DESC', 'number' => 20]);
        if (!$tags) return [];
        return array_map(function ($tag) {
            return ['id' => $tag->term_id, 'name' => $tag->name, 'count' => $tag->count];
        }, $tags);
    }
    /**
     * Fuerza comprobación de actualización y devuelve diagnóstico.
     */
    public function endpoint_check_update() {
        // Borrar transient para forzar consulta fresca
        delete_transient('pbn_publisher_update_info');

        // Forzar refresco del transient de plugins de WordPress
        delete_site_transient('update_plugins');

        // Consultar GitHub directamente
        $response = wp_remote_get(PBN_PUBLISHER_UPDATE_URL, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $result = [
            'current_version' => PBN_PUBLISHER_VERSION,
            'update_url' => PBN_PUBLISHER_UPDATE_URL,
            'plugin_slug' => PBN_PUBLISHER_SLUG,
        ];

        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            $result['can_reach_github'] = false;
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            $result['http_status'] = $code;
            $result['can_reach_github'] = ($code === 200);
            $result['remote_version'] = $data->version ?? null;
            $result['download_url'] = $data->download_url ?? null;
            $result['needs_update'] = isset($data->version) ? version_compare(PBN_PUBLISHER_VERSION, $data->version, '<') : false;
            $result['transients_cleared'] = true;
        }

        return rest_ensure_response($result);
    }

    // =========================================================================
    // AUTO-UPDATE DESDE GITHUB
    // =========================================================================

    /**
     * Consulta el JSON remoto y compara versiones.
     * Se cachea en un transient de 12 horas para no saturar GitHub.
     */
    private function get_remote_update_info() {
        $cache_key = 'pbn_publisher_update_info';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(PBN_PUBLISHER_UPDATE_URL, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data->version)) {
            return false;
        }

        set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Inyecta la actualización en el transient de WordPress si hay nueva versión.
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_update_info();

        if ($remote && version_compare(PBN_PUBLISHER_VERSION, $remote->version, '<')) {
            $plugin_data = new stdClass();
            $plugin_data->slug        = 'pbn-publisher';
            $plugin_data->plugin      = PBN_PUBLISHER_SLUG;
            $plugin_data->new_version = $remote->version;
            $plugin_data->url         = $remote->homepage ?? '';
            $plugin_data->package     = $remote->download_url;
            $plugin_data->tested      = $remote->tested ?? '';
            $plugin_data->requires    = $remote->requires ?? '';

            $transient->response[PBN_PUBLISHER_SLUG] = $plugin_data;
        }

        return $transient;
    }

    /**
     * Muestra la info del plugin en el modal de detalles de WordPress.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'pbn-publisher') {
            return $result;
        }

        $remote = $this->get_remote_update_info();

        if (!$remote) {
            return $result;
        }

        $info = new stdClass();
        $info->name          = 'PBN Publisher';
        $info->slug          = 'pbn-publisher';
        $info->version       = $remote->version;
        $info->author        = '<a href="https://pangostudio.com">Pango Studio</a>';
        $info->homepage      = $remote->homepage ?? 'https://pangostudio.com';
        $info->download_link = $remote->download_url;
        $info->requires      = $remote->requires ?? '6.0';
        $info->tested        = $remote->tested ?? '6.7';
        $info->sections      = [
            'description'  => $remote->description ?? 'Plugin de publicación remota para la PBN de Pango Studio.',
            'changelog'    => $remote->changelog ?? 'Ver GitHub para el historial de cambios.',
        ];

        return $info;
    }

    /**
     * Limpia el cache del transient después de actualizar.
     */
    public function after_update($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient('pbn_publisher_update_info');
        }
    }
}

new PBN_Publisher();

// =========================================================================
// INDEXNOW — Notificación automática a Bing/Yandex/DuckDuckGo
// =========================================================================

/**
 * Genera y almacena una clave IndexNow única para este sitio.
 * Se crea una vez y se reutiliza.
 */
function pbn_indexnow_get_key() {
    $key = get_option( 'pbn_indexnow_key' );
    if ( ! $key ) {
        $key = wp_generate_password( 32, false, false );
        update_option( 'pbn_indexnow_key', $key, true );
    }
    return $key;
}

/**
 * Sirve el archivo de verificación de la clave IndexNow.
 * IndexNow requiere que exista [key].txt en la raíz del dominio.
 * En vez de crear un archivo físico, lo servimos vía rewrite.
 */
function pbn_indexnow_serve_key() {
    $key = pbn_indexnow_get_key();
    $request = trim( $_SERVER['REQUEST_URI'] ?? '', '/' );
    if ( $request === $key . '.txt' ) {
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo esc_html( $key );
        exit;
    }
}
add_action( 'init', 'pbn_indexnow_serve_key', 1 );

/**
 * Envía una URL a IndexNow cuando un post se publica.
 * Se activa en transición de cualquier estado a 'publish'.
 */
function pbn_indexnow_on_publish( $new_status, $old_status, $post ) {
    if ( 'publish' !== $new_status ) {
        return;
    }
    if ( $old_status === $new_status && $old_status === 'publish' ) {
        return; // Solo en publicación nueva o scheduled→publish, no en updates
    }
    if ( ! in_array( $post->post_type, array( 'post', 'page', 'pango_partner' ), true ) ) {
        return;
    }

    $url = get_permalink( $post->ID );
    if ( ! $url ) {
        return;
    }

    $key  = pbn_indexnow_get_key();
    $host = wp_parse_url( home_url(), PHP_URL_HOST );

    $body = wp_json_encode( array(
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => home_url( '/' . $key . '.txt' ),
        'urlList'     => array( $url ),
    ) );

    $response = wp_remote_post( 'https://api.indexnow.org/indexnow', array(
        'timeout' => 10,
        'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
        'body'    => $body,
    ) );

    $code = is_wp_error( $response ) ? 'error' : wp_remote_retrieve_response_code( $response );

    // Log para debug (se puede ver en /wp-json/pbn/v1/indexnow-log)
    $log = get_option( 'pbn_indexnow_log', array() );
    $log[] = array(
        'url'    => $url,
        'status' => $code,
        'time'   => gmdate( 'Y-m-d H:i:s' ),
        'error'  => is_wp_error( $response ) ? $response->get_error_message() : null,
    );
    // Mantener solo los últimos 50 registros
    $log = array_slice( $log, -50 );
    update_option( 'pbn_indexnow_log', $log, false );
}
add_action( 'transition_post_status', 'pbn_indexnow_on_publish', 10, 3 );

/**
 * Endpoint REST para ver el log de IndexNow y el estado de la clave.
 * GET /wp-json/pbn/v1/indexnow-status
 */
function pbn_indexnow_register_endpoints() {
    register_rest_route( 'pbn/v1', '/indexnow-status', array(
        'methods'             => 'GET',
        'callback'            => 'pbn_indexnow_status_endpoint',
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    ) );

    // POST /wp-json/pbn/v1/indexnow-submit — enviar URL manualmente
    register_rest_route( 'pbn/v1', '/indexnow-submit', array(
        'methods'             => 'POST',
        'callback'            => 'pbn_indexnow_submit_endpoint',
        'permission_callback' => function () {
            return current_user_can( 'publish_posts' );
        },
        'args' => array(
            'url' => array( 'required' => true, 'type' => 'string' ),
        ),
    ) );
}
add_action( 'rest_api_init', 'pbn_indexnow_register_endpoints' );

function pbn_indexnow_status_endpoint() {
    $key = pbn_indexnow_get_key();
    $log = get_option( 'pbn_indexnow_log', array() );
    return rest_ensure_response( array(
        'key'              => $key,
        'key_url'          => home_url( '/' . $key . '.txt' ),
        'total_submitted'  => count( $log ),
        'last_submissions' => array_slice( array_reverse( $log ), 0, 10 ),
        'plugin_version'   => PBN_PUBLISHER_VERSION,
    ) );
}

function pbn_indexnow_submit_endpoint( $request ) {
    $url  = esc_url_raw( $request['url'] );
    $key  = pbn_indexnow_get_key();
    $host = wp_parse_url( home_url(), PHP_URL_HOST );

    $body = wp_json_encode( array(
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => home_url( '/' . $key . '.txt' ),
        'urlList'     => array( $url ),
    ) );

    $response = wp_remote_post( 'https://api.indexnow.org/indexnow', array(
        'timeout' => 10,
        'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
        'body'    => $body,
    ) );

    $code = is_wp_error( $response ) ? 'error' : wp_remote_retrieve_response_code( $response );

    $log = get_option( 'pbn_indexnow_log', array() );
    $log[] = array(
        'url'    => $url,
        'status' => $code,
        'time'   => gmdate( 'Y-m-d H:i:s' ),
        'manual' => true,
    );
    $log = array_slice( $log, -50 );
    update_option( 'pbn_indexnow_log', $log, false );

    return rest_ensure_response( array(
        'url'        => $url,
        'status'     => $code,
        'message'    => ( $code === 200 || $code === 202 ) ? 'URL enviada correctamente a IndexNow' : 'Error al enviar',
        'response'   => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ),
    ) );
}
