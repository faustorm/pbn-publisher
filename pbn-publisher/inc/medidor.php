<?php
/**
 * Medidor de visitas para los sitios que no están en el servidor propio.
 *
 * En el VPS, la analítica la pone la fábrica como mu-plugin al montar cada
 * WordPress. Los sitios que viven en hosting compartido nunca pasaron por ahí,
 * así que llevaban meses sin medirse: el panel los enseñaba a cero y parecía
 * que no los visitaba nadie.
 *
 * Aquí NO hay ninguna lista de dominios, y es a propósito. Este plugin vive en
 * un repositorio público: publicar qué webs comparten analítica sería regalar
 * la relación entre ellas. Cada sitio guarda su identificador en una opción
 * suya, que se rellena por API con las credenciales de ese sitio.
 *
 *   POST /wp-json/pbn/v1/medidor  { "id": "uuid", "host": "https://…" }
 *   GET  /wp-json/pbn/v1/medidor
 *
 * Si la opción está vacía no se imprime nada, de modo que instalar el plugin
 * en un sitio que ya se mide por otra vía no duplica la medición.
 *
 * @package pbn-publisher
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const PBN_MEDIDOR_OPCION = 'pbn_medidor';

/**
 * Lo guardado: identificador del sitio y servidor de analítica.
 */
function pbn_medidor_config() {
	$c = get_option( PBN_MEDIDOR_OPCION, array() );
	return array(
		'id'   => isset( $c['id'] ) ? (string) $c['id'] : '',
		'host' => isset( $c['host'] ) ? (string) $c['host'] : '',
	);
}

/**
 * La etiqueta, en el head y sin bloquear el pintado.
 *
 * No se imprime para quien ha iniciado sesión: las visitas de quien escribe no
 * son visitas, y contarlas engorda el panel con tráfico propio.
 */
function pbn_medidor_imprime() {
	if ( is_user_logged_in() ) { return; }
	if ( ! apply_filters( 'pbn_medidor_activo', true ) ) { return; }

	$c = pbn_medidor_config();
	if ( '' === $c['id'] || '' === $c['host'] ) { return; }

	printf(
		'<script defer src="%s/script.js" data-website-id="%s"></script>' . "\n",
		esc_url( untrailingslashit( $c['host'] ) ),
		esc_attr( $c['id'] )
	);
}
add_action( 'wp_head', 'pbn_medidor_imprime', 20 );

/**
 * Leer y escribir la configuración por API.
 */
function pbn_medidor_endpoints() {
	register_rest_route( 'pbn/v1', '/medidor', array(
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				return pbn_medidor_config();
			},
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		),
		array(
			'methods'             => 'POST',
			'callback'            => function ( $peticion ) {
				$id   = trim( (string) $peticion->get_param( 'id' ) );
				$host = trim( (string) $peticion->get_param( 'host' ) );

				if ( '' !== $id && ! preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
					return new WP_Error( 'pbn_id_raro', 'El identificador no tiene forma de UUID.', array( 'status' => 400 ) );
				}
				if ( '' !== $host && ! preg_match( '#^https://#i', $host ) ) {
					return new WP_Error( 'pbn_host_raro', 'El servidor tiene que ir por https.', array( 'status' => 400 ) );
				}

				$c = pbn_medidor_config();
				update_option( PBN_MEDIDOR_OPCION, array(
					'id'   => '' !== $id ? $id : $c['id'],
					'host' => '' !== $host ? $host : $c['host'],
				), false );

				return array( 'guardado' => true ) + pbn_medidor_config();
			},
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'id'   => array( 'type' => 'string', 'default' => '' ),
				'host' => array( 'type' => 'string', 'default' => '' ),
			),
		),
	) );
}
add_action( 'rest_api_init', 'pbn_medidor_endpoints' );
