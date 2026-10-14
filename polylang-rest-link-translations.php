<?php
/**
 * Plugin Name:       Polylang REST - Link Translations
 * Plugin URI:        https://github.com/wantmyusername/polylang-rest-link-translations
 * Description:        Conecta posts como traducciones de Polylang vía REST API, usando pll_save_post_translations(). Endpoint: POST /wp-json/custom/v1/link-translation
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            wantmyusername
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       polylang-rest-link-translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint namespace/route.
 */
define( 'PRLT_NAMESPACE', 'custom/v1' );
define( 'PRLT_ROUTE', 'link-translation' );

/**
 * Registra el endpoint REST.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			PRLT_NAMESPACE,
			'/' . PRLT_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => 'prlt_link_translation_handler',
				'permission_callback' => 'prlt_permission_callback',
			)
		);
	}
);

/**
 * Control de permisos.
 *
 * Política (en este orden):
 *   1. Si existe la constante PRLT_SECRET, exige el header X-PRLT-Secret.
 *   2. Si el filtro 'prlt_allow_public' devuelve true, permite el acceso (solo dev).
 *   3. En caso contrario, exige usuario autenticado con capacidad 'edit_posts'.
 *      Funciona con Application Passwords de WordPress.
 *
 * @param WP_REST_Request $request Request actual.
 * @return true|WP_Error
 */
function prlt_permission_callback( WP_REST_Request $request ) {
	if ( defined( 'PRLT_SECRET' ) && '' !== PRLT_SECRET ) {
		$received = (string) $request->get_header( 'x-prlt-secret' );

		if ( ! hash_equals( (string) PRLT_SECRET, $received ) ) {
			return new WP_Error(
				'prlt_invalid_secret',
				'Secreto inválido o ausente.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permite abrir el endpoint sin autenticación. Solo para entornos de desarrollo.
	 *
	 * @param bool $allow Por defecto false.
	 */
	if ( apply_filters( 'prlt_allow_public', false ) ) {
		return true;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error(
			'prlt_forbidden',
			'Se requiere autenticación con permiso para editar posts.',
			array( 'status' => 401 )
		);
	}

	return true;
}

/**
 * Maneja la petición: valida y guarda las traducciones.
 *
 * Body esperado:
 *   {
 *     "translations": { "es": 123, "en": 456 }
 *   }
 *
 * @param WP_REST_Request $request Request actual.
 * @return WP_REST_Response|WP_Error
 */
function prlt_link_translation_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'pll_save_post_translations' ) ) {
		return new WP_Error(
			'prlt_no_polylang',
			'Polylang no está activo: falta la función pll_save_post_translations().',
			array( 'status' => 500 )
		);
	}

	$params = $request->get_json_params();

	if ( empty( $params ) || ! isset( $params['translations'] ) ) {
		return new WP_Error(
			'no_translations',
			'Falta la clave "translations".',
			array( 'status' => 400 )
		);
	}

	$translations = $params['translations'];

	if ( ! is_array( $translations ) || empty( $translations ) ) {
		return new WP_Error(
			'prlt_invalid_translations',
			'"translations" debe ser un objeto con { "idioma": post_id }.',
			array( 'status' => 400 )
		);
	}

	$clean = array();

	foreach ( $translations as $lang => $post_id ) {
		$lang    = sanitize_key( $lang );
		$post_id = absint( $post_id );

		if ( '' === $lang || ! $post_id ) {
			return new WP_Error(
				'prlt_invalid_entry',
				sprintf( 'Entrada inválida: "%s" => "%s".', $lang, (string) $translations[ $lang ] ),
				array( 'status' => 400 )
			);
		}

		if ( ! pll_languages_list() || ! in_array( $lang, pll_languages_list(), true ) ) {
			return new WP_Error(
				'prlt_invalid_lang',
				sprintf( 'El idioma "%s" no está configurado en Polylang.', $lang ),
				array( 'status' => 400 )
			);
		}

		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'prlt_post_not_found',
				sprintf( 'No existe el post con ID %d.', $post_id ),
				array( 'status' => 404 )
			);
		}

		$clean[ $lang ] = $post_id;
	}

	if ( count( $clean ) < 2 ) {
		return new WP_Error(
			'prlt_need_two_langs',
			'Debes enviar al menos dos idiomas juntos para enlazar un grupo de traducción.',
			array( 'status' => 400 )
		);
	}

	pll_save_post_translations( $clean );

	return new WP_REST_Response(
		array(
			'success'      => true,
			'translations' => $clean,
		),
		200
	);
}
