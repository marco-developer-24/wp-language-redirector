<div align="center">

# WP Language Redirector

**WordPress language destination resolver for WP Multilang-compatible selectors.**

<img src="https://img.shields.io/badge/Platform-WordPress-21759B?logo=wordpress&logoColor=white" alt="WordPress" />
<img src="https://img.shields.io/badge/Integration-WP%20Multilang-4B5563" alt="WP Multilang integration" />
<img src="https://img.shields.io/badge/Status-Specific%20integration-64748B" alt="Specific integration" />

</div>

Plugin de WordPress para resolver la URL de destino al cambiar de idioma desde un selector compatible con WP Multilang. Permite definir mappings manuales y, cuando se cumplen las condiciones necesarias, utiliza `wpm_translate_object_id()` para resolver objetos traducidos.

> Proyecto específico de integración. Revisá los selectores, idiomas y estructura de URLs de tu sitio antes de instalarlo en producción.

## Contenido

- [Qué hace](#qué-hace)
- [Requisitos e integración](#requisitos-e-integración)
- [Instalación](#instalación)
- [Configurar mappings manuales](#configurar-mappings-manuales)
- [Comportamiento cuando no se encuentra un destino](#comportamiento-cuando-no-se-encuentra-un-destino)
- [Checklist de validación](#checklist-de-validación)
- [Limitaciones conocidas](#limitaciones-conocidas)
- [Seguridad y producción](#seguridad-y-producción)
- [Licencia](#licencia)

## Qué hace

Cuando un visitante hace clic en un enlace de idioma compatible, el JavaScript del plugin consulta WordPress mediante AJAX para obtener el destino. La resolución del servidor sigue este orden:

1. **Mapping manual por objeto:** busca un mapping activo que coincida con el ID de objeto, el idioma de origen y el idioma destino.
2. **Mapping manual por URL:** si no encuentra uno por ID, intenta una coincidencia de URL de origen.
3. **WP Multilang:** si la función `wpm_translate_object_id()` está disponible y se recibió un ID de objeto, intenta resolver el objeto traducido. Para categorías de producto utiliza `get_term_link()`; para los demás objetos utiliza `get_permalink()`.

Los mappings manuales tienen prioridad sobre la resolución automática mediante WP Multilang.

## Requisitos e integración

- WordPress.
- jQuery, incluido por WordPress.
- Un selector de idiomas cuyos enlaces coincidan con alguno de estos selectores:
  - `#wpm-languages a`
  - `a[data-wpm-lang]`
- Para la resolución automática de objetos traducidos, WP Multilang y su función `wpm_translate_object_id()`.

### Detección de idiomas y contenido

El JavaScript obtiene el idioma destino desde el atributo `data-lang` del enlace o, si no está disponible, desde la URL del enlace. Para esta última detección contempla el parámetro `lang` y los prefijos `/es/`, `/en/` y `/fr/`. Si no puede determinar el idioma destino, no intercepta el enlace y deja que el navegador lo siga normalmente.

El servidor determina el idioma de origen a partir de la URL actual enviada por el navegador. Reconoce el parámetro `lang` o el primer segmento de ruta cuando es `es`, `en` o `fr`. **No consulta el idioma mediante `wpm_get_language()` ni asigna `es` como valor predeterminado.** Si no puede identificar el idioma de origen, la solicitud de resolución falla y el frontend conserva el enlace original.

El ID y el tipo del contenido actual son opcionales: el script intenta leerlos desde `data-post-id` y `data-post-type` del elemento `body`. Sin un ID de objeto, siguen disponibles los mappings manuales por URL; la resolución automática mediante `wpm_translate_object_id()` no se ejecuta.

## Instalación

1. Descargá o cloná este repositorio.
2. Copiá la carpeta del plugin en `wp-content/plugins/`.
3. Activá **WP Language Redirector** desde el administrador de WordPress.
4. Confirmá que el selector de idiomas utiliza uno de los selectores compatibles.
5. Probá el cambio de idioma en páginas, entradas y categorías que tengan traducciones.

Al activarse, el plugin crea la tabla `{$wpdb->prefix}slr_lang_map` (con el prefijo real de la instalación). Al desactivarse, la tabla se conserva; no se elimina automáticamente.

## Configurar mappings manuales

La pantalla de administración está en **Ajustes → SLR Mappings**. Para crear un mapping, completá:

| Campo | Descripción |
|---|---|
| Source URL | URL completa de la página actual en el idioma de origen |
| Source Lang | Código del idioma de origen, por ejemplo `es` |
| Target Lang | Código del idioma destino, por ejemplo `en` |
| Target URL | URL completa de destino |
| Redirect Type | Selector disponible en el formulario: 301 o 302 |

Los mappings se guardan como activos y pueden eliminarse desde la tabla de mappings existentes. La pantalla muestra hasta 200 registros recientes.

**Nota de implementación:** aunque el formulario guarda el campo `redirect_type`, el flujo actual de frontend navega mediante JavaScript y no aplica ese código HTTP como respuesta de redirección. No interpretes la selección 301/302 como una redirección HTTP real.

## Comportamiento cuando no se encuentra un destino

Si no se encuentra un mapping ni una traducción mediante WP Multilang, el servidor registra el evento `translation_not_found` y responde con un error. En ese caso, el frontend muestra una advertencia en la consola del navegador y navega al `href` original del enlace seleccionado.

Si la solicitud AJAX falla —por ejemplo, por un error de red o una respuesta no válida—, el frontend también registra una advertencia en la consola y conserva el enlace original.

El plugin **no redirige automáticamente a la página de inicio del idioma destino** cuando no encuentra una traducción. Tampoco sustituye un idioma de origen no identificado por un idioma predeterminado.

## Checklist de validación

- [ ] El selector de idiomas coincide con `#wpm-languages a` o `a[data-wpm-lang]`.
- [ ] El idioma destino se detecta correctamente desde `data-lang` o la URL del enlace.
- [ ] El idioma de origen se detecta correctamente desde el parámetro `lang` o el prefijo de la URL actual.
- [ ] Un mapping manual por URL lleva al destino esperado.
- [ ] En contenidos con ID disponible, se verifica la prioridad del mapping por objeto.
- [ ] Con WP Multilang activo y un ID de objeto disponible, se prueba la resolución de una traducción existente.
- [ ] Sin mapping ni traducción, se conserva el enlace original del selector.
- [ ] Si no se puede detectar el idioma de origen, se conserva el enlace original.
- [ ] Si AJAX falla, se conserva el enlace original.
- [ ] Se prueba una página sin traducción y una categoría de producto.
- [ ] Se revisan la consola del navegador y los logs de PHP.

## Limitaciones conocidas

- Los selectores del frontend están definidos en el JavaScript; otros selectores requieren adaptar el código.
- La detección de idiomas contempla actualmente `es`, `en` y `fr`.
- El idioma de origen se detecta desde la URL enviada por el navegador; no se consulta el contexto de idioma de WP Multilang ni se utiliza un idioma predeterminado.
- La resolución automática mediante WP Multilang requiere que `wpm_translate_object_id()` esté disponible y que el frontend proporcione un ID de objeto.
- Si no se encuentra un destino, el comportamiento es conservar el enlace original; no existe un fallback automático a la página de inicio del idioma.
- La interfaz permite crear y eliminar mappings; no incluye edición de registros.
- El campo 301/302 se almacena, pero no controla el tipo de respuesta HTTP en la implementación actual.

## Seguridad y producción

Probá primero en staging. Verificá que los destinos sean URLs correctas del mismo sitio o destinos externos expresamente autorizados, que los códigos de idioma coincidan con la configuración real y que el selector no intercepte enlaces que deban navegar normalmente.

## Licencia

Este repositorio no especifica una licencia. No asumas que el código puede reutilizarse, modificarse o redistribuirse libremente hasta que se agregue una licencia explícita.
