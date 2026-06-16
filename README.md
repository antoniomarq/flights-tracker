# Flights Tracker

Plugin de WordPress para consultar la tabla existente `vuelos_live`, mostrar vuelos en tarjetas optimizadas para móvil y permitir que cada usuario conectado guarde combinaciones de vuelos relacionados por matrícula.

## Instalación

1. Descarga `flights-tracker-mobile-0.3.5-wordpress.zip` desde este repositorio.
2. En WordPress, ve a `Plugins > Añadir nuevo > Subir plugin`.
3. Sube el ZIP.
4. Activa el plugin `Flights Tracker`.

## Descarga

El archivo listo para instalar en WordPress es:

```text
flights-tracker-mobile-0.3.5-wordpress.zip
```

No uses el ZIP automático de GitHub si quieres instalarlo directamente en WordPress; usa el ZIP anterior, que ya contiene la carpeta del plugin con la estructura correcta.

## Uso

Página principal del buscador:

```text
[flights_tracker table="flight_data.vuelos_live"]
```

Página privada para vuelos guardados:

```text
[flights_tracker_saved table="flight_data.vuelos_live"]
```

El buscador muestra por defecto vuelos del día actual empezando desde 30 minutos antes de la hora configurada en WordPress, permite filtrar por rango de fechas, llegada/salida y busca por matrícula, número de vuelo o compañía. La paginación muestra 25 vuelos por página y ofrece controles arriba y abajo de la lista.

Los vuelos guardados se muestran plegados por defecto con hora de guardado, compañía, número de llegada-salida y hora real de llegada. Al desplegarlos, la llegada aparece arriba y la salida abajo; si se actualizan mientras están abiertos, permanecen desplegados. El botón `Realizado` marca el bloque en verde claro y registra la hora local configurada en WordPress. Esa hora aparece en pantalla y queda incluida en el PDF descargable de `Mis vuelos`.

El botón `Descargar PDF` genera un archivo con los vuelos guardados del usuario conectado, indicando cuáles están pendientes y cuáles están realizados con su hora local de realización. El PDF se presenta en formato tabla e incluye hora programada y hora real para facilitar el registro.

## Permisos MySQL

Si WordPress y los vuelos están en bases independientes dentro del mismo servidor MariaDB/MySQL, el usuario de WordPress necesita permiso de lectura sobre la tabla de vuelos.

Ejemplo Docker habitual:

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

Sin este permiso, el plugin puede cargar visualmente, pero mostrará `0 vuelos` o dará error SQL porque WordPress no puede leer la segunda base de datos.

## Diagnóstico

Para comprobar si WordPress puede leer la tabla, crea una página visible solo para administradores o pega temporalmente este shortcode:

```text
[flights_tracker_debug table="flight_data.vuelos_live"]
```

El diagnóstico muestra:

- base de datos actual de WordPress
- tabla consultada
- total de filas encontradas
- total de vuelos de `AGP`
- último error SQL, si existe

## Tabla Leída

Campos usados:

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

## Notas

El plugin asume que las horas de `vuelos_live` están guardadas en UTC y las muestra en la zona horaria configurada en WordPress. Si tu tabla guarda hora local directamente, se puede desactivar con este filtro:

```php
add_filter('flights_tracker_times_are_utc', '__return_false');
```

Si prefieres fijar la tabla desde código en vez de usar el atributo `table`, puedes usar:

```php
add_filter('flights_tracker_live_table', function () {
    return 'flight_data.vuelos_live';
});
```
