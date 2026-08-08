<?php
/**
 * Bloque de preguntas y respuestas para toda la red.
 *
 * Existe por una razón concreta: que un modelo de lenguaje que lea el artículo
 * encuentre la respuesta ya separada de su pregunta y pueda citarla sin tener
 * que entresacarla de la prosa. Por eso el bloque hace dos cosas a la vez:
 * pinta las preguntas en la página y suelta un JSON-LD de tipo FAQPage con el
 * mismo contenido. Lo primero es para la persona; lo segundo, para la máquina.
 *
 * Se usa así, dentro del contenido del post:
 *
 *   [pbn_preguntas titulo="Preguntas sobre la ucronía hispana"]
 *   P: ¿Qué es una ucronía?
 *   R: Una ficción que cambia un hecho del pasado y cuenta el mundo que sale.
 *   P: ¿Y una ucronía hispana?
 *   R: La que parte de un punto de la historia de España o de América.
 *   [/pbn_preguntas]
 *
 * Cada par ocupa dos líneas. La respuesta puede seguir en las líneas de abajo
 * hasta que aparezca otra «P:», así que un párrafo largo no hay que meterlo en
 * una sola línea kilométrica.
 *
 * Los estilos son deliberadamente pobres: en la red hay veintitantos themes
 * distintos y el bloque tiene que caer bien en todos. Hereda color y tipografía
 * del sitio y solo pone ritmo, un filete y el peso de la pregunta.
 *
 * @package pbn-publisher
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Parte el contenido del shortcode en pares de pregunta y respuesta.
 *
 * @param string $texto Contenido crudo del shortcode.
 * @return array Lista de array( pregunta, respuesta ).
 */
function pbn_preguntas_parsea( $texto ) {
	$texto = (string) $texto;
	// WordPress ya ha metido <br> y <p> por el camino: se deshacen a saltos.
	$texto = preg_replace( '#<br\s*/?>#i', "\n", $texto );
	$texto = preg_replace( '#</p>#i', "\n", $texto );
	$texto = wp_strip_all_tags( $texto );
	$texto = html_entity_decode( $texto, ENT_QUOTES, 'UTF-8' );

	$pares   = array();
	$actual  = null;
	foreach ( preg_split( '/\r\n|\r|\n/', $texto ) as $linea ) {
		$linea = trim( $linea );
		if ( '' === $linea ) { continue; }

		if ( preg_match( '/^P\s*[:.\-–]\s*(.+)$/u', $linea, $m ) ) {
			if ( $actual && '' !== $actual[1] ) { $pares[] = $actual; }
			$actual = array( trim( $m[1] ), '' );
		} elseif ( preg_match( '/^R\s*[:.\-–]\s*(.+)$/u', $linea, $m ) ) {
			if ( $actual ) { $actual[1] = trim( $m[1] ); }
		} elseif ( $actual && '' !== $actual[1] ) {
			// La respuesta sigue abajo. Se une con un espacio y no con un salto:
			// una respuesta escrita en cuatro líneas es un párrafo, no cuatro.
			$actual[1] .= ' ' . $linea;
		}
	}
	if ( $actual && '' !== $actual[1] ) { $pares[] = $actual; }
	return $pares;
}

/**
 * Pinta el bloque y su JSON-LD.
 */
function pbn_preguntas_shortcode( $atts, $contenido = null ) {
	$atts = shortcode_atts( array(
		'titulo' => 'Preguntas frecuentes',
		'schema' => 'si',      // 'no' para no emitir el JSON-LD
	), is_array( $atts ) ? $atts : array(), 'pbn_preguntas' );

	$pares = pbn_preguntas_parsea( $contenido );
	if ( ! $pares ) { return ''; }

	static $estilos = false;
	$html = '';

	if ( ! $estilos ) {
		$estilos = true;
		$html .= '<style id="pbn-preguntas-css">'
			. '.pbn-faq{margin:2.2em 0;padding:1.4em 0 .2em;'
			. 'border-top:1px solid currentColor;border-bottom:1px solid currentColor;'
			. 'border-color:color-mix(in srgb,currentColor 22%,transparent)}'
			. '.pbn-faq__t{margin:0 0 1em;font-size:1.15em;line-height:1.25}'
			. '.pbn-faq__par{margin:0 0 1.35em;padding-left:1em;'
			. 'border-left:2px solid color-mix(in srgb,currentColor 28%,transparent)}'
			. '.pbn-faq__par:last-child{margin-bottom:1.4em}'
			. '.pbn-faq__p{margin:0 0 .35em;font-size:1.02em;font-weight:700;line-height:1.3}'
			. '.pbn-faq__r{margin:0}'
			. '.pbn-faq__r p{margin:0 0 .5em}'
			. '.pbn-faq__r p:last-child{margin-bottom:0}'
			. '</style>';
	}

	$html .= '<section class="pbn-faq" aria-labelledby="pbn-faq-titulo">';
	if ( '' !== $atts['titulo'] ) {
		$html .= '<h2 class="pbn-faq__t" id="pbn-faq-titulo">' . esc_html( $atts['titulo'] ) . '</h2>';
	}

	$para_schema = array();
	foreach ( $pares as $par ) {
		list( $p, $r ) = $par;
		$parrafos = '';
		foreach ( preg_split( "/\n+/", $r ) as $trozo ) {
			$trozo = trim( $trozo );
			if ( '' !== $trozo ) { $parrafos .= '<p>' . esc_html( $trozo ) . '</p>'; }
		}
		$html .= '<div class="pbn-faq__par">'
			. '<h3 class="pbn-faq__p">' . esc_html( $p ) . '</h3>'
			. '<div class="pbn-faq__r">' . $parrafos . '</div>'
			. '</div>';

		$para_schema[] = array(
			'@type'          => 'Question',
			'name'           => $p,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => preg_replace( "/\s*\n+\s*/", ' ', $r ),
			),
		);
	}
	$html .= '</section>';

	// El JSON-LD es la mitad del asunto: sin esto el bloque es solo maquetación.
	if ( 'no' !== strtolower( $atts['schema'] ) && $para_schema ) {
		$datos = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $para_schema,
		);
		$html .= '<script type="application/ld+json">'
			. wp_json_encode( $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			. '</script>';
	}

	return $html;
}
add_shortcode( 'pbn_preguntas', 'pbn_preguntas_shortcode' );

/**
 * El shortcode va dentro del contenido, así que wpautop lo rodea de <p> y le
 * mete <br>. Esto lo deja limpio: sin ello el bloque sale con párrafos vacíos
 * por encima y por debajo en la mitad de los themes de la red.
 */
function pbn_preguntas_limpia( $contenido ) {
	if ( false === strpos( $contenido, 'pbn-faq' ) ) { return $contenido; }
	$contenido = preg_replace( '#<p>\s*(<section class="pbn-faq")#', '$1', $contenido );
	$contenido = preg_replace( '#(</section>)\s*</p>#', '$1', $contenido );
	$contenido = preg_replace( '#<p>\s*(<style id="pbn-preguntas-css">)#', '$1', $contenido );
	$contenido = preg_replace( '#(</style>)\s*</p>#', '$1', $contenido );
	$contenido = preg_replace( '#<p>\s*</p>#', '', $contenido );
	return $contenido;
}
add_filter( 'the_content', 'pbn_preguntas_limpia', 20 );
