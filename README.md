# Flights Tracker

Plugin de WordPress para consultar la tabla existente `vuelos_live`, mostrar vuelos en tarjetas optimizadas para móvil y permitir que cada usuario conectado guarde combinaciones de vuelos relacionados por matrícula.

## Instalación

1. Descarga el ZIP desde GitHub.
2. En WordPress, ve a `Plugins > Añadir nuevo > Subir plugin`.
3. Sube el ZIP.
4. Activa el plugin `Flights Tracker`.

## Uso

Página principal del buscador:

```text
[flights_tracker table="flight_data.vuelos_live"]
```

Página privada para vuelos guardados:

```text
[flights_tracker_saved table="flight_data.vuelos_live"]
```

El buscador muestra por defecto vuelos del día actual, permite filtrar por rango de fechas, llegada/salida y busca por matrícula, número de vuelo o compañía. La paginación muestra 25 vuelos por página.

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
