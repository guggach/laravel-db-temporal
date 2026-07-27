# Uni-Temporal (Transaction Time Versioning)

## Was ist temporales Storage?

Eine normale Datenbanktabelle speichert nur den **aktuellen Zustand** eines Datensatzes. Wenn du einen Datensatz änderst oder löschst, sind die vorherigen Werte unwiderruflich verloren.

Ein **temporales Speicher** behält dagegen **alle Versionen** eines Datensatzes. Jede Änderung erzeugt einen neuen Eintrag – der alte bleibt für die Nachwelt erhalten.

### Uni-Temporal (eine Zeitachse)

Jeder Datensatz bekommt zwei zusätzliche Spalten:

| Spalte | Bedeutung | Beispiel |
|--------|-----------|----------|
| `known_from` | Zeitpunkt, ab dem diese Version gültig wurde | `2024-06-01 10:00:00` |
| `known_to` | Zeitpunkt, bis zu dem diese Version gültig war | `2024-06-10 14:30:00` |
| Max-Sentinel | Markiert die aktuell gültige Version | `9999-12-31 23:59:59` |

**Aktuelle Version**: `known_to = 9999-12-31 23:59:59`
**Historische Version**: `known_to` ist ein vergangener Zeitpunkt
**Gelöschter Datensatz**: Die letzte Version bekommt `known_to = Löschzeitpunkt - 1 Sekunde` (sofern du nicht SoftDeletes nutzt)

```mermaid
gantt
    title Lebenszyklus von Order #1 – jede Änderung = neue Version
    dateFormat  YYYY-MM-DD
    axisFormat  %Y-%m-%d

    section Versionen (Inhalt)
    Widget (Menge 5)    :v1, 2024-06-01, 5d
    Widget (Menge 10)   :v2, 2024-06-06, 10d
    Gadget (Menge 10)   :v3, 2024-06-16, 5d
    gelöscht            :v3d, 2024-06-21, 1d

    section Zeitliche Gültigkeit
    known_from → known_to :active, 2024-06-01, 20d
```

---

## Tabellen-Schema (Migration)

Temporale Tabellen benötigen einen **zusammengesetzten Primärschlüssel** aus `(id, known_from, known_to)`:

```php
Schema::create('orders', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->dateTime('known_from');
    $table->dateTime('known_to');
    $table->string('product');
    $table->integer('quantity');
    $table->timestamps();

    $table->primary(['id', 'known_from', 'known_to']);
});
```

### Column-Typen

| Typ | Empfehlung | Hinweis |
|-----|-----------|---------|
| `dateTime` | `known_from`, `known_to` | Sekundengenau, Standard |
| `timestamp` | Alternative | MySQL konvertiert in UTC, kann bei max-Wert Probleme geben |
| `dateTimeTz` | Für multi-timezone | Erhöht Komplexität, nur nötig wenn absolute Klarheit |

### Index-Empfehlungen

```php
Schema::create('orders', function (Blueprint $table) {
    // ... columns ...

    // Primärschlüssel (zwingend für temporal)
    $table->primary(['id', 'known_from', 'known_to']);

    // Scope-Performance: WHERE known_to = max
    $table->index('known_to');

    // Zeitraum-Abfragen: versionsInRange / versionsTouchedRange
    $table->index(['known_from', 'known_to']);
});
```

Der `known_to`-Index ist besonders wichtig – jeder normale Query hat ein `WHERE known_to = max` durch den Global Scope.

### SoftDeletes + Temporal

```php
Schema::create('customers', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->dateTime('known_from');
    $table->dateTime('known_to');
    $table->string('name');
    $table->timestamps();
    $table->softDeletes(); // deleted_at

    $table->primary(['id', 'known_from', 'known_to']);
    $table->index('known_to');
});
```

### Benutzerdefinierte Column-Namen

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->dateTime('sys_from');
    $table->dateTime('sys_to');
    $table->string('title');
    $table->timestamps();

    $table->primary(['id', 'sys_from', 'sys_to']);
    $table->index('sys_to');
});
```

---

## Szenario: Ein Auftrag über seinen Lebenszyklus

Nehmen wir einen Webshop-Auftrag, der über die Zeit mehrfach geändert wird.

### 1. Tag 1 – Auftrag wird erfasst

```sql
INSERT INTO orders (id, product, quantity, known_from, known_to)
VALUES (1, 'Widget', 5, '2024-06-01 10:00:00', '9999-12-31 23:59:59');
```

**Tabelle `orders`:**

| id | product | quantity | known_from | known_to |
|----|---------|----------|------------|----------|
| 1  | Widget  | 5        | 2024-06-01 10:00:00 | 9999-12-31 23:59:59 |

Eine Zeile. `known_to = max` → das ist die aktuelle Version.

### 2. Tag 6 – Mengenänderung von 5 auf 10

Normalerweise ein `UPDATE`. Temporal passiert Folgendes:

**Schritt 1:** Die aktuelle Version wird *geschlossen*:
```sql
UPDATE orders SET known_to = '2024-06-06 09:15:00'
WHERE id = 1 AND known_to = '9999-12-31 23:59:59';
```

**Schritt 2:** Eine *neue Version* wird eingefügt:
```sql
INSERT INTO orders (id, product, quantity, known_from, known_to)
VALUES (1, 'Widget', 10, '2024-06-06 09:15:01', '9999-12-31 23:59:59');
```

**Tabelle `orders` nach dem Update:**

| id | product | quantity | known_from | known_to | Status |
|----|---------|----------|------------|----------|--------|
| 1  | Widget  | 5        | 2024-06-01 10:00:00 | **2024-06-06 09:15:00** | historisch |
| 1  | Widget  | **10**   | **2024-06-06 09:15:01** | 9999-12-31 23:59:59 | **aktuell** |

Zwei Zeilen. Die alte Version ist geschlossen, die neue ist aktiv.

### 3. Tag 16 – Produktwechsel von Widget auf Gadget

Gleiches Prinzip: alte Version schliessen, neue Version erzeugen.

| id | product | quantity | known_from | known_to | Status |
|----|---------|----------|------------|----------|--------|
| 1  | Widget  | 5        | 2024-06-01 10:00:00 | 2024-06-06 09:15:00 | historisch |
| 1  | Widget  | 10       | 2024-06-06 09:15:01 | **2024-06-16 11:30:00** | historisch |
| 1  | **Gadget** | **10** | **2024-06-16 11:30:01** | 9999-12-31 23:59:59 | **aktuell** |

Drei Zeilen, drei Versionen desselben Auftrags.

### 4. Tag 21 – Auftrag wird gelöscht

Temporal löschen schliesst die aktuelle Version – kein physisches `DELETE`.

```sql
UPDATE orders SET known_to = '2024-06-21 08:00:00'
WHERE id = 1 AND known_to = '9999-12-31 23:59:59';
```

| id | product | quantity | known_from | known_to | Status |
|----|---------|----------|------------|----------|--------|
| 1  | Widget  | 5        | 2024-06-01 10:00:00 | 2024-06-06 09:15:00 | historisch |
| 1  | Widget  | 10       | 2024-06-06 09:15:01 | 2024-06-16 11:30:00 | historisch |
| 1  | Gadget  | 10       | 2024-06-16 11:30:01 | **2024-06-21 08:00:00** | **gelöscht** |

Jetzt hat Auftrag 1 **keine** Version mehr mit `known_to = max`.
Er ist aus Sicht der Gegenwart nicht mehr sichtbar – aber die gesamte Historie existiert weiter.

---

## Abfragen auf temporalen Daten

### Standard: Nur die aktuelle Version

```php
Order::where('product', 'Gadget')->get();
```

Resultat: **leer** – denn die Gadget-Version ist gelöscht (`known_to` != max).
Der Global Scope fügt automatisch `WHERE known_to = '9999-12-31 23:59:59'` hinzu.

```php
Order::all()->count(); // 0 – keine aktuelle Version
```

### Alle Versionen (Historie)

```php
$history = Order::allVersions()->where('id', 1)->get();
// 3 Datensätze: die ursprüngliche + 2 Änderungen
```

| # | product | quantity | known_from | known_to | Status |
|---|---------|----------|------------|----------|--------|
| 1 | Widget  | 5        | 2024-06-01 | 2024-06-06 | erste Version |
| 2 | Widget  | 10       | 2024-06-06 | 2024-06-16 | erste Änderung |
| 3 | Gadget  | 10       | 2024-06-16 | 2024-06-21 | zweite Änderung (dann gelöscht) |

### Version zu einem bestimmten Zeitpunkt

```php
$version = Order::versionAsOf('2024-06-10')->where('id', 1)->first();
// product = Widget, quantity = 10
// Das war am 10. Juni der aktuelle Stand
```

Am 10. Juni war Version 2 aktiv (Widget, 10 Stück).

### Versionen in einem Zeitfenster

**`versionsInRange(from, to)`** – nur Versionen, die *vollständig* im Fenster liegen:

```php
Order::versionsInRange('2024-06-05', '2024-06-20')->where('id', 1)->get();
```

| Version | from | to | Im Fenster? |
|---------|------|----|------------|
| Widget/5 | 2024-06-01 | 2024-06-06 | ❌ (beginnt vor dem Fenster) |
| Widget/10 | 2024-06-06 | 2024-06-16 | ✅ |
| Gadget/10 | 2024-06-16 | 2024-06-21 | ✅ |

**`versionsTouchedRange(from, to)`** – Versionen, die das Fenster *überlappen*:

```php
Order::versionsTouchedRange('2024-06-05', '2024-06-20')->where('id', 1)->get();
```

| Version | from | to | Überlappt? |
|---------|------|----|------------|
| Widget/5 | 2024-06-01 | 2024-06-06 | ✅ (endet innerhalb) |
| Widget/10 | 2024-06-06 | 2024-06-16 | ✅ |
| Gadget/10 | 2024-06-16 | 2024-06-21 | ✅ (beginnt innerhalb) |

### Erste / Letzte Version

```php
$first = Order::firstVersion()->where('id', 1)->first();
// Widget, 5 Stück – die ursprüngliche Erfassung

$last = Order::latestVersion()->where('id', 1)->first();
// Gadget, 10 Stück – die letzte Version (evtl. gelöscht)
```

---

## SoftDeletes: Zwei Lösch-Konzepte kombiniert

Laravel's `SoftDeletes` und temporales Versioning lassen sich kombinieren:

```php
use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use IsUniTemporal;
    use SoftDeletes;
}
```

**Migration:**

```php
Schema::create('orders', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->dateTime('known_from');
    $table->dateTime('known_to');
    $table->string('product');
    $table->integer('quantity');
    $table->softDeletes(); // deleted_at
    $table->timestamps();

    $table->primary(['id', 'known_from', 'known_to']);
});
```

### Was passiert bei `$order->delete()`?

Der Soft-Delete durchläuft die **normale temporale Versionierung** – es wird eine neue Version erzeugt:

**Vor dem Löschen (ein Record):**

| id | name | known_from | known_to | deleted_at |
|----|------|------------|----------|------------|
| 100 | Muster | 2026-07-25 10:00:00 | 9999-12-31 23:59:59 | null |

**`$customer->delete()` löst aus:**

1. `SoftDeletes` setzt `deleted_at = now()` auf dem Model
2. Der normale `update()`-Weg wird durchlaufen → temporale Versionierung
3. Die alte Version wird geschlossen, eine neue Version mit `deleted_at` wird eingefügt

```mermaid
sequenceDiagram
    participant App
    participant Model
    participant SoftDeletes
    participant Temporal
    participant DB

    App->>Model: delete()
    Model->>SoftDeletes: set deleted_at = now()
    Model->>Temporal: update() (with deleted_at)
    Temporal->>DB: close old version (known_to = now-1s)
    Temporal->>DB: insert new version with deleted_at
    DB-->>Temporal: done
    Temporal-->>Model: OK
    Model-->>App: true
```

**Resultat in der DB:**

| id | name | known_from | known_to | deleted_at |
|----|------|------------|----------|------------|
| 100 | Muster | 2026-07-25 10:00:00 | **2026-08-31 13:59:59** | null |
| 100 | Muster | **2026-08-31 14:00:00** | 9999-12-31 23:59:59 | **2026-08-31 14:00:00** |

**Zwei Scopes wirken zusammen:**
- `UniTemporalScope`: `WHERE known_to = max` → Version 2 ist die aktuelle
- `SoftDeletes`: `WHERE deleted_at IS NULL` → Version 2 ist versteckt

Effekt: Der Kunde ist aus normalen Queries verschwunden, aber die Historie zeigt klar, wann er existierte und wann gelöscht wurde.

```php
Customer::find(100); // null (SoftDeletes versteckt ihn)

Customer::withTrashed()->find(100);
// gefunden – aktuellste Version mit deleted_at

Customer::allVersions()->where('id', 100)->get();
// 2 Records: die aktive Zeit + der gelöschte Zustand
```

---

## Installation

```bash
composer require guggach/laravel-db-temporal
```

Publiziere die Konfiguration:

```bash
php artisan vendor:publish --tag="laravel-db-temporal-config"
```

---

## Konfiguration

Es gibt zwei Wege, temporale Tabellen zu nutzen: **Connection-basiert** (`DB::table()`) und **Eloquent-basiert** (Models).

### Package-Config

`config/db-temporal.php` nach dem Publizieren:

```php
return [
    'defaults' => [
        'columnTrxDateFrom' => 'known_from',
        'columnTrxDateTo'   => 'known_to',
        'maxTimestamp'      => '9999-12-31 23:59:59',
    ],
];
```

### Connection-basiert (`DB::table()`)

**Empfehlung:** Setze die `temporal`-Connection als **Default** – nicht-temporale Tabellen passieren unverändert:

```php
// config/database.php
'default' => env('DB_CONNECTION', 'temporal'),

'connections' => [
    'temporal' => [
        'driver'  => 'temporal-proxy',
        'base'    => 'mysql',          // oder: pgsql, sqlite
        'host'    => env('DB_HOST'),
        'port'    => env('DB_PORT'),
        'database' => env('DB_DATABASE'),
        'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),

        'uni-temporal' => [
            'tables' => [
                'orders' => [
                    'column_from' => 'known_from',
                    'column_to'   => 'known_to',
                ],
                'invoices' => [
                    'column_from' => 'sys_from',
                    'column_to'   => 'sys_to',
                ],
            ],
        ],
    ],
],
```

**Wichtig:** Nicht-temporale Tabellen (`users`, `password_resets`, `migrations`) werden **nicht versioniert** – sie arbeiten wie gewohnt. Die `temporal`-Connection ist ein Vollersatz für die Standard-Connection.

Jetzt werden `INSERT`, `UPDATE`, `DELETE` auf gelisteten Tabellen automatisch versioniert:

```php
DB::connection('temporal')->table('orders')->insert([
    'product' => 'Widget', 'quantity' => 5
]);

DB::connection('temporal')->table('orders')->where('id', 1)->update([
    'quantity' => 10
]);

DB::connection('temporal')->table('orders')->where('id', 1)->delete();
```

### Eloquent-basiert (Model)

Füge den `IsUniTemporal`-Trait zu deinem Model hinzu:

```php
use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;

class Order extends Model
{
    use IsUniTemporal;

    public $incrementing = false;
}
```

### Column-Namen Auflösung (Resolver-Kette)

Der Trait und der Builder lesen Column-Namen aus vier Quellen (erster Treffer gewinnt):

1. **Model-Konstanten**: `COLUMN_TRX_DATE_FROM`, `COLUMN_TRX_DATE_TO`, `MAX_TIMESTAMP`
2. **Connection-Config**: `uni-temporal.tables.<table>.column_from` (nur mit `temporal-proxy`)
3. **Package-Config**: `config/db-temporal.php defaults`
4. **Hardcoded Fallback**: `trx_date_from` / `trx_date_to`

```php
class Order extends Model
{
    use IsUniTemporal;

    const COLUMN_TRX_DATE_FROM = 'sys_from';
    const COLUMN_TRX_DATE_TO   = 'sys_to';
    const MAX_TIMESTAMP        = '9999-12-31 23:59:59';
}
```

---

## API-Referenz

### Eloquent-Methoden (IsUniTemporal Trait)

#### `allVersions()`

Entfernt den Global Scope – alle Versionen (aktuell + historisch) sind sichtbar.

```php
Order::allVersions()->where('id', 1)->get();
// Alle 3 Versionen von Order #1
```

#### `currentVersion()`

Stellt den Global Scope wieder her (nach `withoutGlobalScope`).

```php
Order::allVersions()->currentVersion()->where('id', 1)->get();
// Nur die aktive Version
```

#### `firstVersion()`

Sortiert nach `known_to ASC` → die früheste (ursprünglichste) Version.

```php
Order::firstVersion()->where('id', 1)->first();
// Widget, 5 Stück
```

#### `latestVersion()`

Sortiert nach `known_to DESC` → die letzte Version (kann gelöscht sein).

```php
Order::latestVersion()->where('id', 1)->first();
// Gadget, 10 Stück
```

#### `versionAsOf($datetime)`

Version zu einem bestimmten Zeitpunkt (`known_from <= dt <= known_to`).

```php
Order::versionAsOf('2024-06-10')->where('id', 1)->first();
// Widget, 10 Stück

Order::versionAsOf(Carbon::parse('2024-06-10 12:00:00'))->first();
```

#### `versionsInRange($from, $to)`

Versionen, die **vollständig** innerhalb eines Zeitraums liegen (`known_from >= from AND known_to <= to`).

```php
Order::versionsInRange('2024-06-05', '2024-06-20')->get();
```

#### `versionsTouchedRange($from, $to)`

Versionen, die einen Zeitraum **überlappen** (`known_to >= from AND known_from <= to`).

```php
Order::versionsTouchedRange('2024-06-05', '2024-06-20')->get();
```

### Builder-Methoden (UniTemporalBuilder)

Diese Methoden stehen sowohl auf dem Eloquent-Query-Builder als auch auf `DB::table()` zur Verfügung.

#### `insert(array $values): bool`

Fügt einen neuen Datensatz mit `known_from = now` und `known_to = max` ein.

```php
DB::table('orders')->insert([
    'product' => 'Widget', 'quantity' => 5
]);
// known_from = NOW(), known_to = 9999-12-31 23:59:59
```

Bei Eloquent setzt der Trait die Timestamps selbst – der Builder überspringt dann das Setzen.

#### `insertGetId(array $values, $sequence = 'id'): int`

Wie `insert()`, ermittelt aber die nächste ID via `MAX(id) + 1`.

```php
$id = DB::table('orders')->insertGetId([
    'product' => 'Widget', 'quantity' => 5
]);
// $id = 1
```

**Hinweis:** Bei hohem Concurrency-Aufkommen kann diese Methode Race Conditions verursachen. Verwende in Produktion ULIDs oder UUIDs als Primärschlüssel.

#### `update(array $values): int`

Schliesst die aktuelle Version (`known_to = now - 1s`) und fügt eine neue Version mit den geänderten Werten ein.

```php
DB::table('orders')->where('id', 1)->update([
    'quantity' => 10
]);
```

#### `delete($id = null): int`

Setzt `known_to = now - 1s` auf die aktuelle Version – kein physischer `DELETE`.

```php
DB::table('orders')->where('id', 1)->delete();
// oder
DB::table('orders')->delete(1);
```

#### `skipVersioning()` / `resumeVersioning()`

Deaktiviert/reaktiviert die Versionierung für Admin-Eingriffe (z.B. Korrekturen an der Historie).

```php
DB::table('orders')
    ->skipVersioning()
    ->where('id', 1)
    ->update(['quantity' => 5]);
// Nur ein normales UPDATE – keine Versionierung
```

### Zusammenfassung der Abfrage-Methoden

| Methode | Beschreibung |
|---------|------------|
| `allVersions()` | Entfernt den Global Scope – zeigt die ganze Historie |
| `currentVersion()` | Stellt den Global Scope wieder her (nach `withoutGlobalScope`) |
| `firstVersion()` | Sortiert nach `known_to ASC` → früheste Version |
| `latestVersion()` | Sortiert nach `known_to DESC` → letzte Version (kann gelöscht sein) |
| `versionAsOf($datetime)` | Version zu einem bestimmten Zeitpunkt |
| `versionsInRange($from, $to)` | Versionen vollständig innerhalb eines Zeitraums |
| `versionsTouchedRange($from, $to)` | Versionen, die einen Zeitraum überlappen |
| `skipVersioning()` | Admin: Versionierung für diesen Query deaktivieren |
| `resumeVersioning()` | Admin: Versionierung wieder aktivieren |

---

## Best Practices

1. **Immer `temporal` als Default-Connection** – nicht-temporale Tabellen passieren unverändert, temporale sind geschützt
2. **Primärschlüssel = `(id, known_from, known_to)`** – die Kombination garantiert Eindeutigkeit
3. **Index auf `known_to`** – jeder normale Query hat ein `WHERE known_to = max`
4. **Kein auto-increment bei `insertGetId`** – bei Concurrency-Problemen ULIDs/UUIDs verwenden
5. **`skipVersioning` nur für Admin-Korrekturen** – nie in der normalen Geschäftslogik

## Ausblick: Bi-Temporal (zwei Zeitachsen)

```mermaid
gantt
    title Bi-Temporal: Application Time + Transaction Time
    dateFormat  YYYY-MM-DD
    axisFormat  %Y-%m-%d

    section Transaction Time
    bekannt seit 2024-06-01    :tx, 2024-06-01, 90d

    section Application Time
    gültig ab 2024-07-01       :app1, 2024-07-01, 30d
    gültig ab 2024-08-01       :app2, 2024-08-01, 60d
```

Bi-temporales Storage erweitert uni-temporales um eine **zweite, fachliche Zeitachse** (Application Time / Valid Time). Mehr dazu in einer späteren Version.
