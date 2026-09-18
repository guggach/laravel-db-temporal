# Laravel DB Temporal

[![Latest Version on Packagist](https://img.shields.io/packagist/v/guggach/laravel-db-temporal.svg?style=flat-square)](https://packagist.org/packages/guggach/laravel-db-temporal)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/guggach/laravel-db-temporal/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/guggach/laravel-db-temporal/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/guggach/laravel-db-temporal.svg?style=flat-square)](https://packagist.org/packages/guggach/laravel-db-temporal)

Automatisches Versioning für Laravel — jeder `INSERT`, `UPDATE` und `DELETE` wird aufbewahrt, sodass du jeden vergangenen Zustand deiner Daten abfragen kannst.

Das Paket unterstützt zwei Versionierungsmodi:

| Modus | Zeitachsen | Wann verwenden |
|-------|-----------|----------------|
| **Uni-Temporal** | Nur Transaction Time | Für einen Audit-Trail: *«Was hat das System zu Zeitpunkt X gespeichert?»* |
| **Bi-Temporal** | Transaction Time + Valid Time | Für fachliche Zeitabfragen: *«Was war in der Fachwelt am Datum X wahr, Stand heute?»* |

---

## Installation

```bash
composer require guggach/laravel-db-temporal
```

Die `temporal-proxy`-Connection in `config/database.php` einrichten:

```bash
php artisan temporal:install
```

Der Befehl liest deine aktuelle Default-Connection (z.B. `mysql`), setzt sie als `base` und wechselt den Default auf `temporal`. Alle nicht-temporalen Tabellen passieren unverändert.

Zum Rückgängigmachen:

```bash
php artisan temporal:uninstall
```

---

## Uni-Temporal — Schnellstart

Jeder Record erhält zwei Spalten: `known_from` / `known_to`. Updates schliessen die alte Version und öffnen eine neue, anstatt zu überschreiben.

```php
use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalModel;

class Order extends Model implements UniTemporalModel
{
    use IsUniTemporal;
    public $incrementing = false;
}
```

```php
Order::create(['product' => 'Widget', 'qty' => 5]);

$order->update(['qty' => 10]);   // alte Version geschlossen, neue geöffnet
$order->delete();                // known_to = now — kein physisches Delete

Order::allVersions()->where('id', 1)->get();           // komplette Historie
Order::versionAsOf('2024-06-10')->where('id', 1)->first(); // Zeitreise
```

**[→ Vollständige Uni-Temporal-Dokumentation](docs/unitemporal_de.md)**

---

## Bi-Temporal — Schnellstart

Zwei Zeitachsen: **Valid Time** (wann war etwas in der Fachwelt wahr) und **Transaction Time** (wann hat das System es aufgezeichnet).

```php
use Guggach\LaravelDbTemporal\Eloquent\IsBiTemporal;
use Guggach\LaravelDbTemporal\Eloquent\BiTemporalModel;

class Address extends Model implements BiTemporalModel
{
    use IsBiTemporal;
    public $incrementing = false;
    const VT_PRECISION = 'day'; // 'day' (Standard) oder 'datetime' für Intraday
}
```

```php
// Umzug am 15.07.2024
Address::create(['id' => 1, 'street' => 'Musterstrasse 10', 'valid_from' => '2024-04-01']);
$address->update(['street' => 'Maierstrasse 2', 'valid_from' => '2024-07-15']);

// Default Scope: aktuell gültige Adresse (heutiges Datum, heutiger Wissensstand)
Address::find(1)->street; // 'Maierstrasse 2'

// Wo wohnte der Kunde am 15. Mai, Stand heute?
Address::asOf('2024-05-15')->find(1)->street; // 'Musterstrasse 10'

// Wo wohnte der Kunde am 15. Mai, Stand 1. Juni?
Address::asOf('2024-05-15', '2024-06-01')->find(1)->street; // 'Musterstrasse 10'
```

**[→ Vollständige Bi-Temporal-Dokumentation](docs/bitemporal_de.md)**

---

## Temporale Pivot-Relationen

Many-to-many-Relationen, deren Pivot-Tabelle versioniert wird.
`HasTemporalPivotRelations` am Model ergänzen und `belongsToMany()` /
`morphToMany()` wie gewohnt verwenden; `attach`, `detach`, `sync` und
`updateExistingPivot` erhalten die vollständige Historie (uni- und bi-temporal,
optionales SoftDelete).

```php
use Guggach\LaravelDbTemporal\Eloquent\HasTemporalPivotRelations;

class Company extends Model
{
    use HasTemporalPivotRelations;

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'company_contacts');
    }
}
```

**[→ Vollständige Pivot-Dokumentation (DE)](docs/pivot-relations_de.md)** ·
**[Pivot documentation (EN)](docs/pivot-relations.md)**

---

## Testing

```bash
composer test
```

## Lizenz

MIT
