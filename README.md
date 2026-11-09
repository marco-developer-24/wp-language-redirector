<div align="center">

# Schefferlii Language Redirector

**WordPress language destination resolver for WP Multilang-compatible selectors.**

<img src="https://img.shields.io/badge/Platform-WordPress-21759B?logo=wordpress&logoColor=white" alt="WordPress" />
<img src="https://img.shields.io/badge/Integration-WP%20Multilang-4B5563" alt="WP Multilang integration" />
<img src="https://img.shields.io/badge/Status-Specific%20integration-64748B" alt="Specific integration" />

</div>

Plugin de WordPress para resolver la URL de destino al cambiar de idioma desde un selector compatible con WP Multilang. Permite definir mappings manuales y utiliza helpers de WP Multilang cuando están disponibles.

> Proyecto específico de integración. Revisá los selectores, idiomas y estructura de URLs de tu sitio antes de instalarlo en producción.

## Contenido

- [Qué hace](#qué-hace)
- [Requisitos e integración](#requisitos-e-integración)
- [Instalación](#instalación)
- [Configurar mappings manuales](#configurar-mappings-manuales)
- [Checklist de validación](#checklist-de-validación)
- [Limitaciones conocidas](#limitaciones-conocidas)
- [Seguridad y producción](#seguridad-y-producción)
- [Licencia](#licencia)

## Qué hace

Cuando un visitante hace clic en un enlace de idioma compatible, el JavaScript del plugin consulta WordPress mediante AJAX para obtener el destino. La resolución sigue este orden:

1. **Mapping manual por objeto:** busca un mapping activo que coincida con el ID de objeto, el idioma de origen y el idioma destino.
2. **Mapping manual por URL:** si no encuentra uno por ID, intenta una coincidencia de URL de origen.
3. **WP Multilang:** si está disponible y se recibió un ID de objeto, intenta resolver el objeto traducido con `wpm_translate_object_id()`.
4. **Fallback de idioma:** si no hay coincidencia, utiliza la URL de inicio del idioma, mediante `wpm_get_language_url()` cuando está disponible o el prefijo habitual `/<idioma>/`.

Los mappings manuales tienen prioridad sobre la traducción automática.

## Requisitos e integración

- WordPress.
- jQuery, incluido por WordPress.
- Un selector de idiomas cuyos enlaces coincidan con alguno de estos selectores:
  - `#wpm-languages a`
  - `a[data-wpm-lang]`
- Para la resolución automática de objetos traducidos, WP Multilang y sus funciones correspondientes.

El script intenta detectar el idioma desde `data-lang`, el parámetro `lang` de la URL o el primer segmento de ruta cuando es `es`, `en` o `fr`. Si no puede detectar el idioma, deja que el enlace funcione normalmente.

El ID y el tipo del contenido actual son opcionales: el script intenta leerlos desde `data-post-id` y `data-post-type` del elemento `body`.

## Instalación

1. Descargá o cloná este repositorio.
2. Copiá la carpeta del plugin en `wp-content/plugins/`.
3. Activá **Schefferlii Language Redirector** desde el administrador de WordPress.
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

## Checklist de validación

- [ ] El selector de idiomas coincide con `#wpm-languages a` o `a[data-wpm-lang]`.
- [ ] El idioma se detecta correctamente desde el atributo o la URL.
- [ ] Un mapping manual por URL lleva al destino esperado.
- [ ] En contenidos con ID disponible, se verifica la prioridad del mapping por objeto.
- [ ] Con WP Multilang activo, se prueba la resolución de una traducción existente.
- [ ] Sin mapping ni traducción, se comprueba el fallback del idioma.
- [ ] Si AJAX falla, se comprueba el fallback simple del frontend.
- [ ] Se prueba una página sin traducción y una categoría de producto.
- [ ] Se revisan la consola del navegador y los logs de PHP.

## Limitaciones conocidas

- Los selectores del frontend están definidos en el JavaScript; otros selectores requieren adaptar el código.
- La detección por prefijo contempla actualmente `es`, `en` y `fr`.
- La detección del idioma de origen depende de WP Multilang o del prefijo de URL; si no puede determinarlo, utiliza `es` como valor por defecto.
- El fallback presupone una estructura de idioma por prefijo, salvo que WP Multilang proporcione su helper.
- La interfaz permite crear y eliminar mappings; no incluye edición de registros.
- El campo 301/302 se almacena, pero no controla el tipo de respuesta HTTP en la implementación actual.

## Seguridad y producción

Probá primero en staging. Verificá que los destinos sean URLs correctas del mismo sitio o destinos externos expresamente autorizados, que los códigos de idioma coincidan con la configuración real y que el selector no intercepte enlaces que deban navegar normalmente.

## Licencia

Este repositorio no especifica una licencia. No asumas que el código puede reutilizarse, modificarse o redistribuirse libremente hasta que se agregue una licencia explícita.
