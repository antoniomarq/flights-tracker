# Flights Tracker

Plugin de WordPress para consultar una tabla externa de vuelos, mostrar tarjetas optimizadas para móvil y permitir que cada usuario conectado guarde combinaciones de llegada/salida por matrícula.

El plugin está pensado para operaciones en aeropuerto base, por ejemplo `AGP`, donde una misma matrícula puede hacer varios vuelos en el mismo día.

## Funciones Principales

- Buscador móvil de vuelos desde la tabla `vuelos_live`.
- Guardado privado por usuario de pares de vuelos relacionados por matrícula.
- Tarjeta comprimida con mini-tabla de vuelo y horario para evitar confusiones.
- Uso de hora real cuando existe y hora programada como respaldo.
- Archivado automático una hora después de la salida real, o programada si no hay real.
- PDF descargable desde `Vuelos archivados`, pensado para impresión limpia.
- Diagnóstico para comprobar permisos de lectura sobre la tabla de vuelos.

## Instalación En WordPress Limpio

### Opción Recomendada

1. Descarga el ZIP instalable `flights-tracker-mobile-0.3.14-wordpress.zip`.
2. En WordPress, entra en `Plugins > Añadir nuevo > Subir plugin`.
3. Sube `flights-tracker-mobile-0.3.14-wordpress.zip`.
4. Activa el plugin `Flights Tracker`.
5. Crea las páginas indicadas en la sección `Páginas Recomendadas`.

### Si Descargas Desde GitHub

Si usas `Code > Download ZIP` en GitHub, asegúrate de que dentro del primer nivel del ZIP esté el archivo principal del plugin:

```text
flights-tracker/flights-tracker.php
```

Si el ZIP contiene carpetas extra por encima, por ejemplo `repo-main/flights-tracker/...`, reempaqueta la carpeta `flights-tracker` antes de subirlo a WordPress.

## Páginas Recomendadas

Estas URLs son sugeridas. Puedes cambiar los nombres, pero mantenerlas así ayuda a que el plugin sea fácil de entender y documentar.

| Página | Slug recomendado | URL recomendada | Shortcode |
| --- | --- | --- | --- |
| Dashboard | `dashboard` | `/dashboard/` | `[flights_tracker_user_greeting]` |
| Buscador de vuelos | `vuelos` | `/vuelos/` | `[flights_tracker table="flight_data.vuelos_live"]` |
| Mis vuelos | `mis-vuelos` | `/mis-vuelos/` | `[flights_tracker_saved table="flight_data.vuelos_live"]` |
| Diagnóstico | `diagnostico-vuelos` | `/diagnostico-vuelos/` | `[flights_tracker_debug table="flight_data.vuelos_live"]` |

La página de diagnóstico solo muestra contenido a usuarios administradores.

## Shortcodes

### `[flights_tracker_user_greeting]`

Muestra un saludo para el usuario conectado.

Ejemplo recomendado para la página `/dashboard/`, debajo del título `Dashboard`:

```text
[flights_tracker_user_greeting]
```

Ejemplo cambiando el texto:

```text
[flights_tracker_user_greeting text="Hola"]
```

Qué hace:

- Muestra `Bienvenido Usuario`.
- Usa el nombre del usuario conectado.
- Si el nombre está en minúsculas, lo muestra con la primera letra en mayúscula.
- Si el usuario no está conectado, no muestra nada.

### `[flights_tracker]`

Muestra el buscador principal de vuelos.

Ejemplo recomendado:

```text
[flights_tracker table="flight_data.vuelos_live"]
```

Ejemplo con todos los atributos:

```text
[flights_tracker base="AGP" table="flight_data.vuelos_live" refresh="60" per_page="25"]
```

Atributos:

| Atributo | Valor por defecto | Descripción |
| --- | --- | --- |
| `base` | `AGP` | Código IATA del aeropuerto base. |
| `table` | `vuelos_live` | Tabla de vuelos. Puede incluir base de datos, por ejemplo `flight_data.vuelos_live`. |
| `refresh` | `60` | Segundos entre actualizaciones automáticas. Mínimo `15`. |
| `per_page` | `25` | Vuelos por página. Mínimo `5`, máximo `50`. |

Qué hace:

- Carga vuelos del rango de fechas seleccionado.
- Permite filtrar por llegada, salida o ambos.
- Permite buscar por matrícula, número de vuelo o compañía.
- Permite guardar una combinación de vuelos relacionados por matrícula.

### `[flights_tracker_saved]`

Muestra la zona privada del usuario conectado.

Ejemplo recomendado:

```text
[flights_tracker_saved table="flight_data.vuelos_live"]
```

Atributos:

| Atributo | Valor por defecto | Descripción |
| --- | --- | --- |
| `table` | `vuelos_live` | Tabla de vuelos usada para seguir actualizando los vuelos guardados mientras siguen vivos. |

Qué hace:

- Muestra `Mis vuelos`, con los vuelos guardados que todavía se sincronizan con la tabla live.
- Muestra `Vuelos archivados`, con vuelos ya fijados y sin sincronización posterior.
- Permite marcar un vuelo como `Realizado`.
- Permite eliminar vuelos activos o archivados.
- Permite descargar PDF únicamente desde `Vuelos archivados`.

### `[flights_tracker_debug]`

Muestra información técnica para comprobar que WordPress puede leer la tabla de vuelos.

Ejemplo recomendado:

```text
[flights_tracker_debug table="flight_data.vuelos_live"]
```

Ejemplo con aeropuerto base:

```text
[flights_tracker_debug base="AGP" table="flight_data.vuelos_live"]
```

Atributos:

| Atributo | Valor por defecto | Descripción |
| --- | --- | --- |
| `base` | `AGP` | Código IATA usado para contar vuelos del aeropuerto base. |
| `table` | `vuelos_live` | Tabla que se quiere comprobar. |

Qué muestra:

- Base de datos actual de WordPress.
- Tabla consultada.
- Total de filas encontradas.
- Total de vuelos del aeropuerto base.
- Llegadas y salidas disponibles.
- Último error SQL, si existe.

## Funcionamiento De Mis Vuelos Y Archivados

Cuando un usuario guarda un vuelo, el plugin guarda una copia interna con:

- matrícula
- compañía
- número de vuelo de llegada
- número de vuelo de salida
- hora programada de llegada
- hora real de llegada
- hora programada de salida
- hora real de salida
- estado de llegada y salida
- fecha/hora de guardado
- fecha/hora de marcado como realizado

Mientras el vuelo sigue en `Mis vuelos`, el plugin continúa sincronizando las horas con la tabla live.

Una hora después de la salida real, el vuelo pasa automáticamente a `Vuelos archivados`. Si no existe hora real de salida, usa la hora programada como respaldo.

En `Vuelos archivados`, el vuelo ya no se actualiza. Queda fijo hasta que el usuario decida eliminarlo.

Esto evita que los vuelos desaparezcan cuando la tabla live borra o rota datos antiguos.

## PDF De Vuelos Archivados

El PDF se descarga desde la sección `Vuelos archivados`.

Incluye columnas limpias para impresión:

```text
Fecha
Realizado
Matrícula
Compañía
Llegada
Llegada/real
Salida
Salida/real
Ruta
Archivado
```

Las columnas `Llegada/real` y `Salida/real` muestran:

```text
hora programada / hora real
```

Ejemplo:

```text
Llegada/real: 07:35/07:48
Salida/real: 09:35/09:49
```

Si no existe hora real, se muestra `-`.

## Permisos MySQL

Si WordPress y la tabla de vuelos están en bases de datos distintas dentro del mismo servidor MariaDB/MySQL, el usuario de WordPress necesita permiso de lectura sobre la tabla de vuelos.

Ejemplo habitual:

```text
Base WordPress: wordpress
Usuario WordPress: wpuser
Base vuelos: flight_data
Tabla vuelos: vuelos_live
```

En phpMyAdmin entra como `root`, abre la pestaña SQL y ejecuta:

```sql
GRANT SELECT ON `flight_data`.`vuelos_live` TO 'wpuser'@'%';
FLUSH PRIVILEGES;
```

Para comprobarlo:

```sql
SHOW GRANTS FOR 'wpuser'@'%';
```

Debe aparecer un permiso parecido a:

```sql
GRANT SELECT ON `flight_data`.`vuelos_live` TO `wpuser`@`%`
```

Sin este permiso, el plugin puede cargar visualmente, pero mostrará `0 vuelos` o dará error SQL porque WordPress no puede leer la tabla externa.

## Compatibilidad Con Temas

El plugin funciona mediante shortcodes y no depende de GeneratePress Premium, GenerateBlocks ni de un tema concreto.

Está preparado para usarse con temas estándar de WordPress como Twenty Twenty-Five.

Desde la versión `0.3.13`, cuando el plugin está activo también aplica una capa visual para Twenty Twenty-Five:

- Oculta la navegación del encabezado, incluido el menú hamburguesa móvil.
- Oculta el título superior del sitio en el encabezado.
- Oculta el pie completo del tema con logo, nombre del sitio, menús y créditos originales.
- Añade un pie limpio con `© año actual Antonio Marquez`.
- Reutiliza el logo configurado en WordPress y lo muestra encima del título de cada página.

Si al cambiar de tema el buscador carga visualmente pero aparece un aviso como:

```text
The string did not match the expected pattern.
```

Revisa estos puntos:

1. Instala la versión `0.3.11` o superior del plugin.
2. Limpia cachés del navegador, caché de WordPress y caché del servidor si existe.
3. Inserta el shortcode dentro de un bloque `Shortcode` o un bloque de contenido normal.
4. Comprueba que el shortcode mantiene la tabla correcta:

```text
[flights_tracker table="flight_data.vuelos_live"]
```

5. Si sigue fallando, crea una página privada con el diagnóstico:

```text
[flights_tracker_debug table="flight_data.vuelos_live"]
```

El error anterior suele indicar que el navegador no pudo construir correctamente la URL de conexión interna con WordPress. Desde la versión `0.3.11`, el plugin imprime esa URL directamente dentro del shortcode para evitar depender del tema activo.

## Tabla De Vuelos Leída

El plugin lee estos campos de la tabla live:

```text
id
base_iata
direction
numero_vuelo
aerolinea
origen
destino
hora_programada
hora_real
estado
aircraft_type
registration
last_seen_at
```

Valores esperados:

- `direction`: `arrival` o `departure`.
- `hora_programada`: fecha/hora programada.
- `hora_real`: fecha/hora real cuando esté disponible.
- `registration`: matrícula del avión.

## Tablas Internas Creadas

El plugin crea sus propias tablas internas en WordPress:

```text
wp_flights_tracker_saved
wp_flights_tracker_archived
```

El prefijo `wp_` cambia si tu WordPress usa otro prefijo de tablas.

Estas tablas guardan los vuelos de cada usuario y los vuelos archivados.

## Horas Y Zona Horaria

Por defecto, el plugin asume que las horas de `vuelos_live` están guardadas en UTC y las muestra en la zona horaria configurada en WordPress.

Si tu tabla guarda hora local directamente, añade este filtro en WordPress:

```php
add_filter('flights_tracker_times_are_utc', '__return_false');
```

## Fijar La Tabla Desde Código

Si prefieres no escribir el atributo `table` en cada shortcode, puedes fijar la tabla desde código:

```php
add_filter('flights_tracker_live_table', function () {
    return 'flight_data.vuelos_live';
});
```

Con ese filtro, puedes usar:

```text
[flights_tracker]
[flights_tracker_saved]
[flights_tracker_debug]
[flights_tracker_user_greeting]
```

## Recomendación Final

Para una instalación limpia, crea como mínimo estas dos páginas:

1. `/dashboard/` con `[flights_tracker_user_greeting]`
2. `/vuelos/` con `[flights_tracker table="flight_data.vuelos_live"]`
3. `/mis-vuelos/` con `[flights_tracker_saved table="flight_data.vuelos_live"]`

Y crea esta página solo para administradores si necesitas comprobar la conexión:

```text
/diagnostico-vuelos/
[flights_tracker_debug table="flight_data.vuelos_live"]
```
