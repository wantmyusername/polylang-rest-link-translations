<?php
/**
 * Snippet mínimo para functions.php
 *
 * Pega esto al final del functions.php de tu tema hijo (o en un plugin
 * de snippets como Code Snippets).
 *
 * ⚠️ AVISO: permission_callback => '__return_true' deja el endpoint
 * abierto a cualquiera. Úsalo solo en desarrollo. Para producción usa
 * el plugin incluido en este repo, que aplica autenticación.
 */

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/link-translation', [
        'methods' => 'POST',
        'callback' => function ($request) {
            $params = $request->get_json_params();

            if (!isset($params['translations'])) {
                return new WP_Error('no_translations', 'Missing translations', ['status' => 400]);
            }

            pll_save_post_translations($params['translations']);

            return [
                'success' => true,
                'translations' => $params['translations']
            ];
        },
        'permission_callback' => '__return_true'
    ]);
});
