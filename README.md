# Laravel DB Temporal

[![Latest Version on Packagist](https://img.shields.io/packagist/v/guggach/laravel-db-temporal.svg?style=flat-square)](https://packagist.org/packages/guggach/laravel-db-temporal)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/guggach/laravel-db-temporal/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/guggach/laravel-db-temporal/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/guggach/laravel-db-temporal.svg?style=flat-square)](https://packagist.org/packages/guggach/laravel-db-temporal)

Automatic versioning for Laravel — every `INSERT`, `UPDATE`, and `DELETE` is preserved so you can query any past state of your data.

The package supports two versioning modes:

| Mode | Time axes | Use when |
|------|-----------|----------|
| **Uni-temporal** | Transaction time only | You need an audit trail: *"what did the system store at time X?"* |
| **Bi-temporal** | Transaction time + Valid time | You also need business-time queries: *"what was true in the real world on date X, as known today?"* |

---

## Installation

```bash
composer require guggach/laravel-db-temporal
```

Wire up the `temporal-proxy` connection in `config/database.php`:

```bash
php artisan temporal:install
```

This reads your current default connection (e.g. `mysql`), sets it as `base`, and switches the default to `temporal`. Non-temporal tables pass through unchanged.

To undo:

```bash
php artisan temporal:uninstall
```

---

## Uni-Temporal — quick start

Every record gains two columns: `known_from` / `known_to`. Updates close the old version and open a new one instead of overwriting.

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

$order->update(['qty' => 10]);   // old version closed, new version opened
$order->delete();                // known_to = now — no physical deletion

Order::allVersions()->where('id', 1)->get();          // complete history
Order::versionAsOf('2024-06-10')->where('id', 1)->first(); // time travel
```

**[→ Full uni-temporal documentation](docs/unitemporal_de.md)**

---

## Bi-Temporal — quick start

Two time axes: **valid time** (when something was true in the world) and **transaction time** (when the system recorded it).

```php
use Guggach\LaravelDbTemporal\Eloquent\IsBiTemporal;
use Guggach\LaravelDbTemporal\Eloquent\BiTemporalModel;

class Address extends Model implements BiTemporalModel
{
    use IsBiTemporal;
    public $incrementing = false;
    const VT_PRECISION = 'day'; // 'day' (default) or 'datetime' for intraday
}
```

```php
// Customer moves on 2024-07-15
Address::create(['id' => 1, 'street' => 'Musterstrasse 10', 'valid_from' => '2024-04-01']);
$address->update(['street' => 'Maierstrasse 2', 'valid_from' => '2024-07-15']);

// Default scope: currently valid address (today's date, today's knowledge)
Address::find(1)->street; // 'Maierstrasse 2'

// Where was the customer on 15 May, as known today?
Address::asOf('2024-05-15')->find(1)->street; // 'Musterstrasse 10'

// Where was the customer on 15 May, as the system knew on 1 June?
Address::asOf('2024-05-15', '2024-06-01')->find(1)->street; // 'Musterstrasse 10'
```

**[→ Full bi-temporal documentation](docs/bitemporal.md)**

---

## Temporal pivot relations

Many-to-many relations whose pivot table is versioned. Add
`HasTemporalPivotRelations` to the model and keep using `belongsToMany()` /
`morphToMany()`; `attach`, `detach`, `sync` and `updateExistingPivot` preserve
the full history (uni- and bi-temporal, optional soft delete).

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

**[→ Full pivot documentation (EN)](docs/pivot-relations.md)** ·
**[Pivot-Dokumentation (DE)](docs/pivot-relations_de.md)**

---

## Testing

```bash
composer test
```

## License

MIT
