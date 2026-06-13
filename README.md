# Flights Tracker

Plugin de WordPress para consultar la tabla existente `vuelos_live`, mostrar vuelos en tarjetas y permitir que cada usuario conectado guarde combinaciones de vuelos relacionados por matricula.

## Instalacion desde GitHub

1. En GitHub, abre el boton `Code`.
2. Pulsa `Download ZIP`.
3. En WordPress, ve a `Plugins > Anadir nuevo > Subir plugin`.
4. Sube el ZIP descargado.
5. Activa el plugin `Flights Tracker`.

## Uso

Crea una pagina publica o privada con este shortcode:

```text
[flights_tracker]
```

Crea una pagina privada para que cada usuario vea sus vuelos guardados:

```text
[flights_tracker_saved]
```

## Tabla leida

El plugin consulta directamente la tabla:

```text
vuelos_live
```

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

## Funcionamiento

- Por defecto muestra vuelos de `AGP`.
- El buscador filtra por matricula, numero de vuelo y compania.
- El listado se refresca automaticamente cada 60 segundos.
- Las llegadas se muestran en celeste suave.
- Las salidas se muestran en blanco.
- Al pulsar `Guardar`, el plugin busca vuelos con la misma matricula:
  - Si el vuelo actual es una llegada, muestra salidas posteriores.
  - Si el vuelo actual es una salida, muestra llegadas anteriores.
- Cada usuario de WordPress solo ve sus propios vuelos guardados.

## Notas

El plugin asume que las horas de `vuelos_live` estan guardadas en UTC y las muestra en la zona horaria configurada en WordPress. Si tu tabla guarda hora local directamente, se puede desactivar con este filtro:

```php
add_filter('flights_tracker_times_are_utc', '__return_false');
```

Si la tabla real tiene otro nombre, se puede cambiar con:

```php
add_filter('flights_tracker_live_table', function () {
    return 'vuelos_live';
});
```
