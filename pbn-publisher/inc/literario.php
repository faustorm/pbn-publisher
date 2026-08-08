<?php
/**
 * Libros y autores como taxonomías, para los blogs literarios de la red.
 *
 * La idea: un artículo sobre Dostoyevski y otro sobre novela rusa hablan del
 * mismo autor, pero hasta ahora no había forma de saberlo ni de que el lector
 * saltara de uno a otro. Con esto cada libro y cada autor tienen su página, que
 * reúne todo lo que se ha escrito mencionándolos.
 *
 * Sirve para tres cosas a la vez, y conviene tenerlas separadas:
 *   · Al lector le da por dónde seguir leyendo.
 *   · Al buscador le da páginas de entidad —un autor, una obra— que es
 *     exactamente lo que sabe entender.
 *   · A un modelo le da la relación explícita entre el texto y de quién habla,
 *     sin tener que deducirla.
 *
 * NO se activa sola. Un blog de baloncesto no necesita una taxonomía de autores
 * literarios. Se enciende por sitio con la opción `pbn_lit_activo`, que está
 * expuesta en la API REST para poder encenderla en remoto.
 *
 * @package pbn-publisher
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ¿Está encendido en este blog?
 */
function pbn_lit_activo() {
	return (bool) get_option( 'pbn_lit_activo', 0 );
}

/**
 * La opción, visible en la API REST para encenderla desde fuera.
 */
function pbn_lit_registra_opcion() {
	register_setting( 'general', 'pbn_lit_activo', array(
		'type'         => 'boolean',
		'default'      => false,
		'show_in_rest' => true,
		'description'  => 'Activa las taxonomías de libros y autores en este blog.',
	) );
}
add_action( 'init', 'pbn_lit_registra_opcion' );

/**
 * Las dos taxonomías.
 *
 * Van con su propia base de URL —/libro/ y /autor/— porque son páginas que
 * queremos que existan por sí mismas, no un filtro escondido.
 */
function pbn_lit_registra_taxonomias() {
	if ( ! pbn_lit_activo() ) { return; }

	register_taxonomy( 'pbn_libro', array( 'post' ), array(
		'labels' => array(
			'name'          => 'Libros',
			'singular_name' => 'Libro',
			'menu_name'     => 'Libros mencionados',
			'search_items'  => 'Buscar libros',
			'all_items'     => 'Todos los libros',
			'edit_item'     => 'Editar libro',
			'add_new_item'  => 'Añadir libro',
			'not_found'     => 'No hay libros',
		),
		'public'            => true,
		'hierarchical'      => false,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'libro', 'with_front' => false ),
	) );

	register_taxonomy( 'pbn_autor', array( 'post' ), array(
		'labels' => array(
			'name'          => 'Autores',
			'singular_name' => 'Autor',
			'menu_name'     => 'Autores mencionados',
			'search_items'  => 'Buscar autores',
			'all_items'     => 'Todos los autores',
			'edit_item'     => 'Editar autor',
			'add_new_item'  => 'Añadir autor',
			'not_found'     => 'No hay autores',
		),
		'public'            => true,
		'hierarchical'      => false,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'autor', 'with_front' => false ),
	) );
}
add_action( 'init', 'pbn_lit_registra_taxonomias', 5 );

/**
 * Al encender la opción hay que refrescar las reglas de URL o los archivos dan 404.
 */
function pbn_lit_refresca_urls( $viejo, $nuevo ) {
	if ( $viejo !== $nuevo ) {
		pbn_lit_registra_taxonomias();
		flush_rewrite_rules( false );
	}
}
add_action( 'update_option_pbn_lit_activo', 'pbn_lit_refresca_urls', 10, 2 );


/* ============================================================
 * Las fichas flojas no se indexan
 * ============================================================ */

/**
 * Cuántos artículos tiene que reunir una ficha para merecer estar en el índice.
 * Con uno solo, la página no aporta nada que no tenga ya el propio artículo: es
 * una lista de un elemento. Sirve para navegar y para que un modelo vea la
 * relación, pero no para que Google la ofrezca como resultado.
 */
function pbn_lit_minimo_para_indexar() {
	return (int) apply_filters( 'pbn_lit_minimo_indexar', 2 );
}

/**
 * ¿Estamos en una ficha con menos artículos de la cuenta?
 */
function pbn_lit_ficha_floja() {
	if ( ! pbn_lit_activo() || ! is_tax( array( 'pbn_libro', 'pbn_autor' ) ) ) {
		return false;
	}
	$t = get_queried_object();
	return ( $t instanceof WP_Term ) && $t->count < pbn_lit_minimo_para_indexar();
}

/**
 * Con Yoast puesto manda él: hay que decírselo por su filtro o pondría el suyo
 * y saldrían dos etiquetas contradictorias en la misma página.
 */
function pbn_lit_yoast_noindex( $robots ) {
	if ( pbn_lit_ficha_floja() ) {
		$robots['index'] = 'noindex';
	}
	return $robots;
}
add_filter( 'wpseo_robots_array', 'pbn_lit_yoast_noindex' );

/**
 * Y si no hay Yoast, la ponemos nosotros.
 */
function pbn_lit_noindex_propio() {
	if ( ! pbn_lit_ficha_floja() ) { return; }
	if ( defined( 'WPSEO_VERSION' ) ) { return; }   // ya lo ha hecho Yoast
	echo '<meta name="robots" content="noindex, follow">' . "\n";
}
add_action( 'wp_head', 'pbn_lit_noindex_propio', 1 );

/**
 * Fuera del sitemap también, que si no se le sigue ofreciendo al buscador.
 */
function pbn_lit_fuera_del_sitemap( $terms, $taxonomy ) {
	if ( ! in_array( $taxonomy, array( 'pbn_libro', 'pbn_autor' ), true ) ) {
		return $terms;
	}
	$minimo = pbn_lit_minimo_para_indexar();
	return array_values( array_filter( $terms, function ( $t ) use ( $minimo ) {
		return isset( $t->count ) && $t->count >= $minimo;
	} ) );
}
add_filter( 'wpseo_sitemap_terms', 'pbn_lit_fuera_del_sitemap', 10, 2 );

/**
 * El sitemap del núcleo de WordPress va por otro sitio.
 */
function pbn_lit_fuera_del_sitemap_nucleo( $args, $taxonomy ) {
	if ( in_array( $taxonomy, array( 'pbn_libro', 'pbn_autor' ), true ) ) {
		$args['meta_query'] = array();          // por si acaso
		$args['hide_empty'] = true;
	}
	return $args;
}
add_filter( 'wp_sitemaps_taxonomies_query_args', 'pbn_lit_fuera_del_sitemap_nucleo', 10, 2 );


/* ============================================================
 * El bloque de libros y autores
 * ============================================================ */

/**
 * Devuelve los términos que tocan según dónde estemos.
 *
 * Dentro de un artículo enseña los suyos, que es lo útil ahí: de quién habla
 * esto y dónde seguir. Fuera, los más mencionados de todo el blog.
 *
 * @return array array( lista de WP_Term, 'actual'|'top' )
 */
function pbn_lit_terminos( $taxonomia, $limite ) {
	if ( is_singular( 'post' ) ) {
		$suyos = get_the_terms( get_queried_object_id(), $taxonomia );
		if ( $suyos && ! is_wp_error( $suyos ) ) {
			return array( array_slice( $suyos, 0, $limite ), 'actual' );
		}
	}
	$top = get_terms( array(
		'taxonomy'   => $taxonomia,
		'orderby'    => 'count',
		'order'      => 'DESC',
		'number'     => $limite,
		'hide_empty' => true,
	) );
	return array( is_wp_error( $top ) ? array() : $top, 'top' );
}

/**
 * Pinta la lista. Los estilos son mínimos y heredan del theme, como el resto
 * de componentes: en la red hay veintitantos y tienen que caer bien en todos.
 */
function pbn_lit_pinta( $taxonomia, $titulo, $limite, $titulo_actual = '' ) {
	if ( ! pbn_lit_activo() ) { return ''; }

	list( $terminos, $modo ) = pbn_lit_terminos( $taxonomia, $limite );
	if ( ! $terminos ) { return ''; }

	if ( 'actual' === $modo && $titulo_actual ) { $titulo = $titulo_actual; }

	static $css = false;
	$html = '';
	if ( ! $css ) {
		$css = true;
		$html .= '<style id="pbn-lit-css">'
			. '.pbn-lit{margin:0}'
			. '.pbn-lit ul{list-style:none;margin:0;padding:0;'
			. 'display:flex;flex-wrap:wrap;gap:.4em}'
			. '.pbn-lit li{margin:0}'
			. '.pbn-lit a{display:inline-block;text-decoration:none;'
			. 'font-size:.92em;line-height:1.3;padding:.28em .7em;'
			. 'border:1px solid color-mix(in srgb,currentColor 28%,transparent);'
			. 'border-radius:999px;color:inherit}'
			. '.pbn-lit a:hover{border-color:currentColor;'
			. 'background:color-mix(in srgb,currentColor 8%,transparent)}'
			. '.pbn-lit .pbn-lit__n{opacity:.55;font-size:.85em;margin-left:.35em}'
			. '</style>';
	}

	$html .= '<div class="pbn-lit"><ul>';
	foreach ( $terminos as $t ) {
		$enlace = get_term_link( $t );
		if ( is_wp_error( $enlace ) ) { continue; }
		$html .= '<li><a href="' . esc_url( $enlace ) . '">' . esc_html( $t->name );
		// el número solo tiene sentido en la lista de más mencionados
		if ( 'top' === $modo && $t->count > 1 ) {
			$html .= '<span class="pbn-lit__n">' . (int) $t->count . '</span>';
		}
		$html .= '</a></li>';
	}
	$html .= '</ul></div>';

	return array( $html, $titulo );
}

/* ── Shortcodes, por si se quiere dentro de un artículo ─────────────────── */
function pbn_lit_sc_libros( $atts ) {
	$a = shortcode_atts( array( 'limite' => 12, 'titulo' => '' ), $atts, 'pbn_libros' );
	$r = pbn_lit_pinta( 'pbn_libro', '', (int) $a['limite'] );
	return $r ? $r[0] : '';
}
add_shortcode( 'pbn_libros', 'pbn_lit_sc_libros' );

function pbn_lit_sc_autores( $atts ) {
	$a = shortcode_atts( array( 'limite' => 12, 'titulo' => '' ), $atts, 'pbn_autores' );
	$r = pbn_lit_pinta( 'pbn_autor', '', (int) $a['limite'] );
	return $r ? $r[0] : '';
}
add_shortcode( 'pbn_autores', 'pbn_lit_sc_autores' );


/* ============================================================
 * Los dos widgets del sidebar
 * ============================================================ */

class PBN_Widget_Libros extends WP_Widget {
	public function __construct() {
		parent::__construct( 'pbn_widget_libros', 'PBN · Libros',
			array( 'description' => 'Los libros mencionados en este artículo, o los más mencionados del blog.' ) );
	}
	public function widget( $args, $instance ) {
		$limite = isset( $instance['limite'] ) ? (int) $instance['limite'] : 12;
		$r = pbn_lit_pinta( 'pbn_libro',
			! empty( $instance['title'] ) ? $instance['title'] : 'Top libros',
			$limite, 'Libros de los que habla este artículo' );
		if ( ! $r ) { return; }
		list( $html, $titulo ) = $r;
		echo $args['before_widget'];
		if ( $titulo ) { echo $args['before_title'] . esc_html( $titulo ) . $args['after_title']; }
		echo $html;
		echo $args['after_widget'];
	}
	public function form( $instance ) {
		$t = isset( $instance['title'] ) ? $instance['title'] : 'Top libros';
		$l = isset( $instance['limite'] ) ? (int) $instance['limite'] : 12;
		printf( '<p><label>Título<input class="widefat" name="%s" value="%s"></label></p>',
			esc_attr( $this->get_field_name( 'title' ) ), esc_attr( $t ) );
		printf( '<p><label>Cuántos<input class="tiny-text" type="number" min="1" max="40" name="%s" value="%d"></label></p>',
			esc_attr( $this->get_field_name( 'limite' ) ), $l );
	}
	public function update( $nueva, $vieja ) {
		return array(
			'title'  => sanitize_text_field( $nueva['title'] ?? '' ),
			'limite' => max( 1, min( 40, (int) ( $nueva['limite'] ?? 12 ) ) ),
		);
	}
}

class PBN_Widget_Autores extends WP_Widget {
	public function __construct() {
		parent::__construct( 'pbn_widget_autores', 'PBN · Autores',
			array( 'description' => 'Los autores mencionados en este artículo, o los más mencionados del blog.' ) );
	}
	public function widget( $args, $instance ) {
		$limite = isset( $instance['limite'] ) ? (int) $instance['limite'] : 12;
		$r = pbn_lit_pinta( 'pbn_autor',
			! empty( $instance['title'] ) ? $instance['title'] : 'Top autores',
			$limite, 'Autores de los que habla este artículo' );
		if ( ! $r ) { return; }
		list( $html, $titulo ) = $r;
		echo $args['before_widget'];
		if ( $titulo ) { echo $args['before_title'] . esc_html( $titulo ) . $args['after_title']; }
		echo $html;
		echo $args['after_widget'];
	}
	public function form( $instance ) {
		$t = isset( $instance['title'] ) ? $instance['title'] : 'Top autores';
		$l = isset( $instance['limite'] ) ? (int) $instance['limite'] : 12;
		printf( '<p><label>Título<input class="widefat" name="%s" value="%s"></label></p>',
			esc_attr( $this->get_field_name( 'title' ) ), esc_attr( $t ) );
		printf( '<p><label>Cuántos<input class="tiny-text" type="number" min="1" max="40" name="%s" value="%d"></label></p>',
			esc_attr( $this->get_field_name( 'limite' ) ), $l );
	}
	public function update( $nueva, $vieja ) {
		return array(
			'title'  => sanitize_text_field( $nueva['title'] ?? '' ),
			'limite' => max( 1, min( 40, (int) ( $nueva['limite'] ?? 12 ) ) ),
		);
	}
}

function pbn_lit_registra_widgets() {
	if ( ! pbn_lit_activo() ) { return; }
	register_widget( 'PBN_Widget_Libros' );
	register_widget( 'PBN_Widget_Autores' );
}
add_action( 'widgets_init', 'pbn_lit_registra_widgets' );
