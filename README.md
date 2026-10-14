# Polylang REST - Link Translations

Conecta dos (o más) posts como **traducciones de Polylang** usando la REST API de WordPress. Pensado para automatizaciones con **n8n**, Make, Zapier o scripts propios.

> **El problema:** Polylang expone el campo `translations` en la REST API **solo para lectura**. Al enviar `{"lang":"en","translations":{"es":123}}` a `/wp/v2/posts/{id}`, WordPress guarda el idioma pero **ignora la relación de traducción**. No existe forma de enlazar posts por REST de fábrica.
>
> **La solución:** un endpoint custom que llama a la función interna de Polylang `pll_save_post_translations()`.

---

## Índice

- [Cómo funciona](#cómo-funciona)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
  - [Opción A - Como plugin](#opción-a---como-plugin)
  - [Opción B - Como snippet en functions.php](#opción-b---como-snippet-en-functionsphp)
- [Uso del endpoint](#uso-del-endpoint)
- [Ejemplo con cURL](#ejemplo-con-curl)
- [Ejemplo con n8n](#ejemplo-con-n8n)
- [Verificar que quedó conectado](#verificar-que-quedó-conectado)
- [Puntos clave (los que rompen todo)](#puntos-clave-los-que-rompen-todo)
- [Errores comunes](#errores-comunes)
- [Seguridad](#seguridad)
- [Licencia](#licencia)

---

## Cómo funciona

```
n8n ──POST /wp-json/custom/v1/link-translation──> WordPress
        { "translations": { "es": 123, "en": 456 } }
                          │
                          ▼
              pll_save_post_translations()
                          │
                          ▼
              Polylang crea el grupo de traducción
              y enlaza ambos posts
```

Polylang agrupa las traducciones en un **grupo de traducción interno**. La única forma fiable de crear o modificar ese grupo es la función PHP `pll_save_post_translations( $array )`, donde `$array` mapea `idioma => post_id`.

La REST API estándar no permite escribir esa relación, por eso registramos un endpoint propio que expone esa función.

---

## Requisitos

- WordPress 5.6+
- [Polylang](https://wordpress.org/plugins/polylang/) (o Polylang Pro) activo
- Permisos para editar archivos del sitio (plugin o `functions.php`)
- (Opcional) [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) de WordPress para autenticar el request

---

## Instalación

### Opción A - Como plugin

**Recomendado.** Aísla el código y no se pierde al cambiar de tema.

1. Copia la carpeta `polylang-rest-link-translations/` completa a `wp-content/plugins/`.
2. Activa **Polylang REST - Link Translations** en *Plugins*.
3. Verifica que el endpoint responde (ver [Uso del endpoint](#uso-del-endpoint)).

Estructura del plugin:

```
polylang-rest-link-translations.php   # Código del plugin
```

### Opción B - Como snippet en functions.php

Si prefieres no instalar un plugin, pega el contenido de [`snippet.php`](./snippet.php) al final del `functions.php` de tu tema **hijo** (o en un plugin de snippets como Code Snippets).

```php
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
```

> `permission_callback => '__return_true'` deja el endpoint **abierto a cualquiera**. Úsalo solo en desarrollo. Para producción, mira [Seguridad](#seguridad).

---

## Uso del endpoint

| Campo | Valor |
| --- | --- |
| **Método** | `POST` |
| **URL** | `https://TU-SITIO.com/wp-json/custom/v1/link-translation` |
| **Content-Type** | `application/json` |

**Body (JSON):**

```json
{
  "translations": {
    "es": 123,
    "en": 456
  }
}
```

- Las claves (`es`, `en`, ...) son **slugs de idioma de Polylang**.
- Los valores son los **IDs de los posts**.
- Debes enviar **todos los idiomas del grupo juntos**, no uno por uno.

**Respuesta correcta (200):**

```json
{
  "success": true,
  "translations": {
    "es": 123,
    "en": 456
  }
}
```

---

## Ejemplo con cURL

```bash
curl -X POST "https://TU-SITIO.com/wp-json/custom/v1/link-translation" \
  -H "Content-Type: application/json" \
  -u "usuario:application_password" \
  -d '{
    "translations": {
      "es": 123,
      "en": 456
    }
  }'
```

Sin autenticación (si usas la variante abierta):

```bash
curl -X POST "https://TU-SITIO.com/wp-json/custom/v1/link-translation" \
  -H "Content-Type: application/json" \
  -d '{"translations":{"es":123,"en":456}}'
```

---

## Ejemplo con n8n

Flujo recomendado:

1. **Crear el post en español** (ej. nodo *WordPress* o *HTTP Request*) → obtienes `123`.
2. **Crear el post en inglés** → obtienes `456`.
3. **Llamar al endpoint custom** para enlazarlos.

Nodo **HTTP Request** del paso 3:

| Configuración | Valor |
| --- | --- |
| **Method** | `POST` |
| **URL** | `https://TU-SITIO.com/wp-json/custom/v1/link-translation` |
| **Send Body** | `ON` |
| **Body Content Type** | `JSON` |
| **Authentication** | *Basic Auth* con Application Password (recomendado) |

**Body (JSON):**

```json
{
  "translations": {
    "es": {{ $('Creamos el post ES').item.json.id }},
    "en": {{ $('Creamos el post EN').item.json.id }}
  }
}
```

> ⚠️ **Muy importante en n8n:** el método `POST` va en el **dropdown "Method"**, nunca dentro del campo **URL**. Si escribes `POST https://...` en la URL, n8n lanza `Invalid URL: POST https://... URL must start with "http" or "https"`. Ese error es de n8n, no de WordPress.

---

## Verificar que quedó conectado

Tras el request, consulta el post en la REST API:

```bash
curl "https://TU-SITIO.com/wp-json/wp/v2/posts/123" | jq '.translations'
```

Debe devolver algo como:

```json
{ "es": 123, "en": 456 }
```

Si sigues en duda, en PHP la fuente de verdad absoluta es:

```php
pll_get_post_translations(123);
```

Devuelve el mapa real de traducciones de Polylang (no lo que muestre el admin).

---

## Puntos clave (los que rompen todo)

1. **`translations` es solo lectura en REST.** WP guarda `lang`, pero ignora `translations`. Por eso necesitas el endpoint custom.
2. **Envía todos los idiomas juntos.** `{"es":123}` solo no enlaza. Debe ser `{"es":123,"en":456}`.
3. **Orden:** crear ES → crear EN → enlazar. Polylang crea el grupo a partir del primer post.
4. **Si el post ya tenía grupo**, límpialo antes si necesitas reasignar:

   ```php
   pll_delete_post_translations(456);
   ```

5. **En n8n**, el método va en el dropdown, no en la URL.

---

## Errores comunes

| Error | Causa | Solución |
| --- | --- | --- |
| `Invalid URL: POST https://...` | El método quedó dentro del campo URL en n8n | Pon `POST` solo en el dropdown *Method* |
| `404 rest_no_route` | El endpoint no está registrado | Activa el plugin o revisa `functions.php`; prueba `/wp-json/` para confirmar que REST responde |
| `400 no_translations` | Falta la clave `translations` en el body | Revisa el JSON enviado |
| El post cambia de idioma pero no se enlaza | Mandaste un solo idioma, o dependías de REST estándar | Usa este endpoint y envía todos los idiomas |
| `401` / `403` | Autenticación o permisos | Usa Application Password / Basic Auth |

---

## Seguridad

El `permission_callback => '__return_true'` del snippet es cómodo para probar, pero **expone un endpoint que modifica contenido** a cualquiera que conozca la URL.

El **plugin** incluido aplica una política más segura por defecto:

1. Si defines la constante `PRLT_SECRET` en `wp-config.php`, exige el header `X-PRLT-Secret` con ese valor.
2. Si no, exige que el usuario esté autenticado con capacidad `edit_posts` (funciona con **Application Passwords**).
3. Puedes desactivar toda la comprobación (modo abierto) con el filtro `prlt_allow_public` — solo para desarrollo.

Ejemplo de `wp-config.php`:

```php
define('PRLT_SECRET', 'un-secreto-largo-y-aleatorio');
```

Ejemplo de request con el secreto:

```bash
curl -X POST "https://TU-SITIO.com/wp-json/custom/v1/link-translation" \
  -H "Content-Type: application/json" \
  -H "X-PRLT-Secret: un-secreto-largo-y-aleatorio" \
  -d '{"translations":{"es":123,"en":456}}'
```

---

## Licencia

[MIT](./LICENSE)
