<?php
/**
 * Instalar plugins a distancia, incluidos los nuestros.
 *
 * El problema que resuelve: la API de WordPress sabe instalar plugins del
 * directorio oficial por su nombre corto, pero no sabe instalar un zip. Y los
 * plugins que escribimos nosotros no están en el directorio oficial. Resultado:
 * en cualquier sitio donde no haya FTP ni SSH —eneaconsultoria, por ejemplo— la
 * primera instalación había que hacerla a mano desde el escritorio.
 *
 * Con esto, cualquier instancia con la contraseña de aplicación puede instalar,
 * actualizar, activar o desactivar plugins sin tocar el escritorio.
 *
 * ── Sobre la seguridad, que aquí importa ──────────────────────────────────
 *
 * Un endpoint que descarga código de una URL y lo ejecuta es, por definición,
 * una puerta trasera. Si se filtra una contraseña de aplicación, quien la tenga
 * puede meter lo que quiera en el sitio. Por eso hay dos cerrojos:
 *
 *   1. Hace falta el permiso `install_plugins`, que solo tienen los
 *      administradores. Una contraseña de aplicación hereda los permisos de su
 *      usuario, así que un editor no puede.
 *   2. El origen tiene que estar en una lista blanca. Por defecto solo se
 *      aceptan descargas de nuestro GitHub y del directorio oficial de
 *      WordPress. Una URL cualquiera se rechaza aunque las credenciales sean
 *      correctas.
 *
 * La lista se amplía con el filtro `pbn_origenes_permitidos` desde un
 * mu-plugin, nunca con un parámetro de la petición: si se pudiera pasar por la
 * petición, no sería una lista blanca.
 *
 * @package pbn-publisher
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * De dónde se acepta descargar un plugin.
 */
function pbn_origenes_permitidos() {
	return (array) apply_filters( 'pbn_origenes_permitidos', array(
		'github.com/faustorm/',
		'objects.githubusercontent.com/',   // a donde redirigen las descargas de GitHub
		'downloads.wordpress.org/',
	) );
}

/**
 * ¿Viene de un sitio del que nos fiamos?
 */
function pbn_origen_valido( $url ) {
	$url = (string) $url;
	if ( ! preg_match( '#^https://#i', $url ) ) {
		return false;                        // sin https no se descarga nada
	}
	foreach ( pbn_origenes_permitidos() as $permitido ) {
		if ( false !== stripos( $url, $permitido ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Deja lista la maquinaria de instalación del núcleo.
 */
function pbn_carga_instalador() {
	if ( ! class_exists( 'Plugin_Upgrader' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
}

/**
 * Un informador mudo: el del núcleo escribe HTML por pantalla y aquí eso
 * ensuciaría la respuesta JSON.
 *
 * OJO con dónde se declara. Extiende WP_Upgrader_Skin, que es una clase de
 * wp-admin y NO existe cuando WordPress carga los plugins. Declararla arriba
 * del fichero, como estaba, tumba el sitio entero con un error fatal en cuanto
 * se activa el plugin. Por eso se declara aquí dentro, después de haber cargado
 * el instalador del núcleo.
 */
function pbn_nueva_piel() {
	pbn_carga_instalador();          // hasta aquí WP_Upgrader_Skin no existe
	return new class extends WP_Upgrader_Skin {
		public $mensajes = array();
		public function header() {}
		public function footer() {}
		public function feedback( $cadena, ...$args ) {
			if ( is_string( $cadena ) && '' !== $cadena ) { $this->mensajes[] = $cadena; }
		}
		public function error( $errores ) {
			if ( is_wp_error( $errores ) ) { $this->mensajes[] = $errores->get_error_message(); }
		}
	};
}

/**
 * POST /wp-json/pbn/v1/plugin/install
 *   { "url": "https://github.com/faustorm/…/x.zip", "activar": true }
 *   { "slug": "all-in-one-wp-migration", "activar": true }
 */
function pbn_endpoint_instalar( $peticion ) {
	pbn_carga_instalador();
	$url  = trim( (string) $peticion->get_param( 'url' ) );
	$slug = trim( (string) $peticion->get_param( 'slug' ) );
	$activar = (bool) $peticion->get_param( 'activar' );

	if ( '' === $url && '' === $slug ) {
		return new WP_Error( 'pbn_falta_origen', 'Hace falta una url o un slug.', array( 'status' => 400 ) );
	}

	if ( '' !== $slug && '' === $url ) {
		// del directorio oficial: se resuelve su zip
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$info = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $info ) ) {
			return new WP_Error( 'pbn_slug_desconocido', $info->get_error_message(), array( 'status' => 404 ) );
		}
		$url = $info->download_link;
	}

	if ( ! pbn_origen_valido( $url ) ) {
		return new WP_Error(
			'pbn_origen_no_permitido',
			'Ese origen no está en la lista blanca. Se amplía con el filtro pbn_origenes_permitidos.',
			array( 'status' => 403, 'permitidos' => pbn_origenes_permitidos() )
		);
	}

	$piel = pbn_nueva_piel();
	$upgrader = new Plugin_Upgrader( $piel );
	$resultado = $upgrader->install( $url, array( 'overwrite_package' => true ) );

	if ( is_wp_error( $resultado ) ) {
		return new WP_Error( 'pbn_instalacion_fallida', $resultado->get_error_message(), array( 'status' => 500 ) );
	}
	if ( false === $resultado ) {
		return new WP_Error( 'pbn_instalacion_fallida',
			implode( ' · ', $piel->mensajes ) ?: 'La instalación no ha devuelto nada.',
			array( 'status' => 500 ) );
	}

	// plugin_info() vuelve vacío cuando se sobrescribe un plugin que ya está en
	// marcha —el caso de que este plugin se actualice a sí mismo—. En ese caso
	// el nombre de la carpeta sí viene en el resultado del instalador.
	$fichero = $upgrader->plugin_info();
	if ( ! $fichero && is_array( $resultado ) && ! empty( $resultado['destination_name'] ) ) {
		$carpeta = $resultado['destination_name'];
		foreach ( array_keys( get_plugins() ) as $candidato ) {
			if ( 0 === strpos( $candidato, $carpeta . '/' ) ) {
				$fichero = $candidato;
				break;
			}
		}
	}
	$activado = null;
	if ( $activar && $fichero ) {
		$a = activate_plugin( $fichero );
		$activado = is_wp_error( $a ) ? $a->get_error_message() : true;
	}

	$datos = $fichero ? get_plugin_data( WP_PLUGIN_DIR . '/' . $fichero, false, false ) : array();

	pbn_apunta_instalacion( $fichero, $url, $activado );

	return rest_ensure_response( array(
		'instalado' => true,
		'plugin'    => $fichero,
		'nombre'    => $datos['Name'] ?? null,
		'version'   => $datos['Version'] ?? null,
		'activado'  => $activado,
		'origen'    => $url,
		'detalle'   => $piel->mensajes,
	) );
}

/**
 * POST /wp-json/pbn/v1/plugin/state   { "plugin": "carpeta/fichero.php", "accion": "activate" }
 * Acciones: activate, deactivate, delete.
 */
function pbn_endpoint_estado( $peticion ) {
	pbn_carga_instalador();
	$plugin = (string) $peticion->get_param( 'plugin' );
	$accion = (string) $peticion->get_param( 'accion' );

	$v = validate_plugin( $plugin );   // devuelve WP_Error si ese fichero no existe
	if ( is_wp_error( $v ) ) {
		return new WP_Error( 'pbn_plugin_desconocido', $v->get_error_message(), array( 'status' => 404 ) );
	}

	switch ( $accion ) {
		case 'activate':
			$r = activate_plugin( $plugin );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( 'pbn_no_activa', $r->get_error_message(), array( 'status' => 500 ) );
			}
			break;
		case 'deactivate':
			deactivate_plugins( array( $plugin ) );
			break;
		case 'delete':
			deactivate_plugins( array( $plugin ) );
			$r = delete_plugins( array( $plugin ) );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( 'pbn_no_borra', $r->get_error_message(), array( 'status' => 500 ) );
			}
			break;
		default:
			return new WP_Error( 'pbn_accion_desconocida',
				'Acciones válidas: activate, deactivate, delete.', array( 'status' => 400 ) );
	}

	return rest_ensure_response( array( 'plugin' => $plugin, 'accion' => $accion, 'hecho' => true ) );
}

/**
 * GET /wp-json/pbn/v1/plugin/list
 */
function pbn_endpoint_lista( $peticion ) {
	pbn_carga_instalador();
	$salida = array();
	foreach ( get_plugins() as $fichero => $d ) {
		$salida[] = array(
			'plugin'  => $fichero,
			'nombre'  => $d['Name'],
			'version' => $d['Version'],
			'activo'  => is_plugin_active( $fichero ),
		);
	}
	return rest_ensure_response( $salida );
}

/**
 * Registro de lo instalado. Sin esto, un día no sabes de dónde salió algo.
 */
function pbn_apunta_instalacion( $plugin, $url, $activado ) {
	$log = get_option( 'pbn_instalaciones', array() );
	if ( ! is_array( $log ) ) { $log = array(); }
	array_unshift( $log, array(
		'cuando'   => current_time( 'mysql' ),
		'quien'    => wp_get_current_user()->user_login,
		'plugin'   => $plugin,
		'origen'   => $url,
		'activado' => $activado,
	) );
	update_option( 'pbn_instalaciones', array_slice( $log, 0, 50 ), false );
}

function pbn_puede_instalar() {
	return current_user_can( 'install_plugins' );
}

function pbn_registra_endpoints_instalador() {
	register_rest_route( 'pbn/v1', '/plugin/install', array(
		'methods'             => 'POST',
		'callback'            => 'pbn_endpoint_instalar',
		'permission_callback' => 'pbn_puede_instalar',
		'args'                => array(
			'url'     => array( 'type' => 'string', 'default' => '' ),
			'slug'    => array( 'type' => 'string', 'default' => '' ),
			'activar' => array( 'type' => 'boolean', 'default' => true ),
		),
	) );
	register_rest_route( 'pbn/v1', '/plugin/state', array(
		'methods'             => 'POST',
		'callback'            => 'pbn_endpoint_estado',
		'permission_callback' => 'pbn_puede_instalar',
		'args'                => array(
			'plugin' => array( 'type' => 'string', 'required' => true ),
			'accion' => array( 'type' => 'string', 'required' => true ),
		),
	) );
	register_rest_route( 'pbn/v1', '/plugin/list', array(
		'methods'             => 'GET',
		'callback'            => 'pbn_endpoint_lista',
		'permission_callback' => 'pbn_puede_instalar',
	) );
}
add_action( 'rest_api_init', 'pbn_registra_endpoints_instalador' );
