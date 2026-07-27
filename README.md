# Laravel DB Temporal

[![Latest Version on Packagist](https://img.shields.io/packagist/v/guggach/laravel-db-temporal.svg?style=flat-square)](https://packagist.org/packages/guggach/laravel-db-temporal)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/guggach/laravel-db-temporal/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/guggach/laravel-db-temporal/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/guggach/laravel-db-temporal.svg?style=flat-square)](https://packagist.org/packages/guggach/laravel-db-temporal)

**Transaction Time Versioning für Laravel.**  
Jeder `INSERT`, `UPDATE` und `DELETE` wird automatisch versioniert – alte Zustände bleiben erhalten.

```php
// Einfügen → bekannte Historie wird automatisch gesetzt
Order::create(['product' => 'Widget', 'quantity' => 5]);

// Ändern → alte Version schliessen, neue Version erzeugen
$order->update(['quantity' => 10]);

// Löschen → nur temporales Löschen (known_to = now)
$order->delete();

// Historie abfragen
Order::allVersions()->where('id', 1)->get();           // alle Versionen
Order::versionAsOf('2024-06-10')->where('id', 1)->get(); // Stand zu einem Zeitpunkt
```

**Zwei Ebenen:** Eloquent-Trait `IsUniTemporal` für Models, `temporal-proxy`-Driver für `DB::table()`.

## Dokumentation

**[docs/unitemporal.md](docs/unitemporal.md)** – Theorie, Szenarien, Beispiel-Abfragen und alle Details.

## Installation

```bash
composer require guggach/laravel-db-temporal
php artisan vendor:publish --tag="laravel-db-temporal-config"
```

## Testing

```bash
composer test
```

## Lizenz

MIT
