# Bi-Temporal (Valid Time + Transaction Time Versioning)

## Was ist bi-temporales Storage?

Eine normale Datenbanktabelle speichert nur den **aktuellen Zustand** eines Datensatzes. Wenn du einen Datensatz änderst oder löschst, sind die vorherigen Werte unwiderruflich verloren.

Ein **temporales Speicher** behält dagegen **alle Versionen** eines Datensatzes. Jede Änderung erzeugt einen neuen Eintrag — der alte bleibt für die Nachwelt erhalten.

**Bi-temporal** geht einen Schritt weiter und verfolgt **zwei unabhängige Zeitachsen**:

| Spaltenpaar | Achse | Beantwortete Frage |
|-------------|-------|-------------------|
| `valid_from` / `valid_to` | **Valid Time (VT)** | Wann war dieser Sachverhalt in der Fachwelt wahr? |
| `known_from` / `known_to` | **Transaction Time (TT)** | Wann hat das System dies aufgezeichnet? |

Beide Achsen verwenden einen Max-Sentinel (`9999-12-31` für VT, `9999-12-31 23:59:59` für TT), um ein offenes Ende zu markieren.

---

## Das 2D-Prinzip: Fläche statt Linie

Jeder Record belegt eine **Rechteckfläche** im 2D-Raum VT × TT. Die Grundregel: Rechtecke einer Entity müssen den Raum **lückenlos kacheln** — jeder Punkt (TT-Moment, VT-Moment) muss von genau einem Record abgedeckt sein.

```
TT ∞ │                                              │
     │  Rec 1' (Musterstr.)   │  Rec 2 (Maierstr.) │
     │  VT: Apr 01 → Jul 14   │  VT: Jul 15 → ∞    │
     │  TT: Jul 15 → ∞        │  TT: Jul 15 → ∞    │
Jul 15├──────────────────────────────────────────────┤
     │  Rec 1  (Musterstrasse 10)                    │
     │  VT: Apr 01 → ∞    TT: Jun 01 → Jul 14       │
Jun 01└──────────────────────────────────────────────┘
      Apr 01              Jul 15                  VT ∞
```

Rec 1' (der **Remainder**) und Rec 2 sind beide TT-offen und decken zusammen alle VT-Perioden ab. Damit ist jede historische Frage beantwortbar.

### Warum der naive Ansatz Lücken erzeugt

Ein häufiger Fehler: beim Aktualisieren einer Adresse den alten Record auf **beiden** Achsen gleichzeitig terminieren (VT und TT) und dann einen neuen Record ab dem neuen `valid_from` einfügen.

Query: *«Was war am 15. Mai als Adresse bekannt, Stand August?»*
- Rec 1: `known_to = Jul 14 < August` → nicht sichtbar
- Rec 2: `valid_from = Jul 15 > 15. Mai` → falscher VT-Bereich

**Kein Treffer** — eine Lücke im 2D-Raum. Die korrekte Lösung legt Rec 1' als **Remainder-Kopie** an, die die alte VT-Periode mit aktuellem TT-Wissen abdeckt.

---

## Installation

```bash
composer require guggach/laravel-db-temporal
```

```bash
php artisan temporal:install   # fügt temporal-proxy-Connection in config/database.php ein
php artisan temporal:uninstall # macht es rückgängig
```

Vollständige Installationsdetails im [README](../README_de.md).

---

## Konfiguration

### Connection-basiert (`DB::table()`) — empfohlen

```php
// config/database.php
'default' => env('DB_CONNECTION', 'temporal'),

'connections' => [
    'temporal' => [
        'driver' => 'temporal-proxy',
        'base'   => 'mysql',  // oder: pgsql, sqlite

        'bi-temporal' => [
            'defaults' => [
                'column_valid_from' => 'valid_from',
                'column_valid_to'   => 'valid_to',
                'column_known_from' => 'known_from',
                'column_known_to'   => 'known_to',
                'vt_precision'      => 'day',                  // 'day' oder 'datetime'
                'max_date'          => '9999-12-31',           // VT-Sentinel
                'max_timestamp'     => '9999-12-31 23:59:59',  // TT-Sentinel
            ],
            'tables' => [
                'addresses'   => [],           // verwendet defaults
                'fuel_prices' => [             // Intraday-Genauigkeit
                    'vt_precision' => 'datetime',
                ],
                'contracts'   => [             // abweichende Spaltennamen
                    'column_valid_from' => 'app_from',
                    'column_valid_to'   => 'app_to',
                    'column_known_from' => 'sys_from',
                    'column_known_to'   => 'sys_to',
                ],
            ],
        ],
    ],
],
```

`host`, `port`, `database`, `username` und `password` werden von der `base`-Connection übernommen.

> **Warnung:** Ohne Connection-Config niemals `DB::table()` auf temporalen Tabellen verwenden — die Historie wird stillschweigend korrumpiert. Stattdessen Eloquent-Models verwenden.

### Eloquent-Model

```php
use Guggach\LaravelDbTemporal\Eloquent\IsBiTemporal;
use Guggach\LaravelDbTemporal\Eloquent\BiTemporalModel;

class Address extends Model implements BiTemporalModel
{
    use IsBiTemporal;

    public $incrementing = false;
    const VT_PRECISION = 'day'; // Pflicht ohne Connection-Config
}
```

### VT-Genauigkeit (vt_precision)

| Wert | VT-Spaltentyp | Grenze | Anwendungsfall |
|------|--------------|--------|----------------|
| `'day'` (Standard) | `date` | ±1 Tag | Adressen, Verträge, Tagespreise |
| `'datetime'` | `dateTime` | ±1 Sekunde | Benzinpreise, Börsenkurse, Schichtpläne |

### Resolver-Kette (erster Treffer gewinnt)

1. Model-Konstanten: `COLUMN_VALID_FROM`, `COLUMN_VALID_TO`, `COLUMN_KNOWN_FROM`, `COLUMN_KNOWN_TO`, `VT_PRECISION`
2. Table-Config: `bi-temporal.tables.<table>.*`
3. Connection-Defaults: `bi-temporal.defaults.*`
4. Hardcoded Fallback: `valid_from` / `valid_to` / `known_from` / `known_to` / `'day'`

```php
class Contract extends Model implements BiTemporalModel
{
    use IsBiTemporal;
    public $incrementing = false;

    const VT_PRECISION      = 'day';
    const COLUMN_VALID_FROM = 'app_from';
    const COLUMN_VALID_TO   = 'app_to';
    const COLUMN_KNOWN_FROM = 'sys_from';
    const COLUMN_KNOWN_TO   = 'sys_to';
}
```

---

## Datenbank-Schema

### Blueprint-Makros

| Makro | Beschreibung |
|-------|-------------|
| `$table->bitemporal()` | Fügt `valid_from`, `valid_to` (`date` oder `dateTime` je nach `vt_precision`), `known_from`, `known_to` (`dateTime`) hinzu |
| `$table->bitempIndexes($pk = 'id')` | Erstellt Primärschlüssel `(pk, valid_to, known_to)` und zwei Composite-Indizes |

```php
Schema::create('addresses', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->bitemporal();    // 4 temporale Spalten
    $table->unsignedBigInteger('customer_id');
    $table->string('street');
    $table->timestamps();

    $table->bitempIndexes(); // PK (id, valid_to, known_to) + Indizes
});
```

> `unsignedBigInteger('id')` statt `id()` verwenden — der 3-spaltige Composite-PK ersetzt den Standard-Auto-Increment-PK.

### Primärschlüssel-Design

Der PK `(id, valid_to, known_to)` ist minimal und gleichzeitig der stärkste Integritäts-Constraint: Zwei Records mit gleichem `(id, valid_to, known_to)` würden sich im 2D-Raum überlappen, was die Kachel-Invariante verletzt. Ein Unique-Constraint-Fehler signalisiert daher direkt einen Logikfehler im Splitting-Algorithmus.

### Manuelle Migration (ohne Makros)

```php
Schema::create('addresses', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->date('valid_from');           // oder dateTime bei vt_precision='datetime'
    $table->date('valid_to');
    $table->dateTime('known_from');
    $table->dateTime('known_to');
    $table->unsignedBigInteger('customer_id');
    $table->string('street');
    $table->timestamps();

    $table->primary(['id', 'valid_to', 'known_to']);
    $table->index(['known_to', 'valid_to', 'id']);
    $table->index(['valid_to', 'known_to', 'id']);
});
```

---

## Szenarien

### Szenario 1 — Normales Update (Adressänderung)

![Szenario 1 – normales Update](./bi-temp_1_normale_update.png)

Ein Kunde (id=1) wohnt seit dem 01.04.2024 an der Musterstrasse 10. Die Daten werden am 01.06.2024 eingetragen. Am 15.07.2024 zieht er an die Maierstrasse 2.

```php
// Ersterfassung (TT = 01.06.)
Address::create(['id' => 1, 'customer_id' => 42, 'street' => 'Musterstrasse 10',
    'valid_from' => '2024-04-01']);

// Umzug (VT und TT = 15.07.)
$address->update(['street' => 'Maierstrasse 2', 'valid_from' => '2024-07-15']);
```

Der Builder führt intern drei Operationen aus:

1. **TT-terminiert** Rec 1 (`known_to = 14.07. 23:59:59`)
2. **Linker Remainder** (Rec 1'): Kopie von Rec 1 mit `valid_to = 14.07.`, `known_from = 15.07.`, `known_to = MAX`
3. **Neuer Record** (Rec 2): neue Adresse, `valid_from = 15.07.`, `known_from = 15.07.`, beide `*_to = MAX`

| street | valid_from | valid_to | known_from | known_to | Bemerkung |
|--------|-----------|----------|------------|----------|-----------|
| Musterstrasse 10 | Apr 01 | 9999-12-31 | Jun 01 | **Jul 14** | TT-Archiv |
| Musterstrasse 10 | Apr 01 | **Jul 14** | Jul 15 | 9999-12-31 | Remainder (Rec 1') |
| Maierstrasse 2 | **Jul 15** | 9999-12-31 | Jul 15 | 9999-12-31 | aktuell |

Beantwortbare Fragen:
- *«Wo wohnte der Kunde am 15. Mai, Stand heute?»* → Rec 1' → Musterstrasse 10 ✓
- *«Was ist die aktuelle Adresse?»* → Rec 2 → Maierstrasse 2 ✓
- *«Was zeigte das System für den 15. Mai, Stand 30. Juni?»* → Rec 1 → Musterstrasse 10 ✓

### Szenario 2 — Korrektur (gleicher valid_from)

![Szenario 2 – Korrektur](./bi-temp_2_correction.png)

Die Hausnummer wurde falsch erfasst: nicht Nr. 2, sondern Nr. 4. Das Umzugsdatum (15.07.) ist korrekt. Die Korrektur erfolgt am 20.07. (TT).

```php
// Nur die VT-Periode ab 15.07. korrigieren — keine neue Adresse
Address::where('id', 1)
    ->where('valid_from', '2024-07-15')
    ->update(['street' => 'Maierstrasse 4']);
```

Da `old_rec.valid_from == new_vt_from`, ist kein Platz für einen linken Remainder.

1. **TT-terminiert** Rec 2 (`known_to = 19.07.`)
2. **Kein Remainder** (identischer `valid_from`)
3. **Neuer Record** (Rec 3): korrigierte Strasse, `known_from = 20.07.`

Die TT-Achse dokumentiert: bis 19.07. glaubte das System, die Nummer sei 2 — ab 20.07. ist die Korrektur auf 4 bekannt.

> **Hinweis:** `$model->save()` mit unverändertem `valid_from` löst pure TT-Versionierung aus (kein VT-Split) und aktualisiert **alle** aktuell offenen VT-Perioden der Entity. Eine spezifische `WHERE valid_from = ...`-Klausel verwenden, wenn nur eine VT-Periode korrigiert werden soll.

### Szenario 3 — Einschub zwischen bestehende Records (Discount)

![Szenario 3 – Discount-Einschub](./bi-temp_3_correction_between.png)

Drei Preise existieren: 110 (Apr–14.Jul), 120 (15.Jul–31.Aug), 130 (01.Sep–∞). Am 13.08. wird ein Sommer-Discount definiert: Preis 100 für 15.08.–14.09.

```php
// allVersions() umgeht den VT=heute-Global-Scope,
// der für Änderungen historischer VT-Daten notwendig ist
Price::allVersions()->where('product_id', 7)->update([
    'price'      => 100.00,
    'valid_from' => '2024-08-15',
    'valid_to'   => '2024-09-14',
]);
```

Der Builder findet zwei TT-offene Records, die mit `[15.Aug, 14.Sep]` überlappen:

**Rec 2'** (Preis 120, VT 15.Jul–31.Aug):
- `old.valid_from 15.Jul < new.valid_from 15.Aug` → **linker Remainder (Rec 2'')**: VT 15.Jul–14.Aug
- `old.valid_to 31.Aug ≤ new.valid_to 14.Sep` → kein rechter Remainder

**Rec 3** (Preis 130, VT 01.Sep–∞):
- `old.valid_from 01.Sep ≥ new.valid_from 15.Aug` → kein linker Remainder
- `old.valid_to ∞ > new.valid_to 14.Sep` → **rechter Remainder (Rec 3')**: VT 15.Sep–∞

Ergebnis (nur TT-offene Records):

| price | valid_from | valid_to | Bemerkung |
|-------|-----------|----------|-----------|
| 110 | Apr 01 | Jul 14 | Preis 1 Remainder |
| 120 | Jul 15 | **Aug 14** | Rec 2'' (linker Remainder) |
| 100 | **Aug 15** | **Sep 14** | Rec 4 (Discount) |
| 130 | **Sep 15** | 9999-12-31 | Rec 3' (rechter Remainder) |

Lückenlos — jeder VT-Zeitpunkt ist von genau einem TT-offenen Record abgedeckt.

---

## Abfragen

### Default Scope — heutige Sicht

Der Global Scope wird automatisch angewendet:
`WHERE known_to = MAX AND valid_from ≤ heute AND valid_to ≥ heute`

`Address::find(1)` gibt genau einen Record zurück: die heute gültige Adresse, Stand heute. Historisch gültige, aber abgelaufene VT-Perioden sind standardmässig ausgeblendet.

```php
// Gibt die einzige aktuell gültige Adresse zurück (oder null wenn gelöscht)
Address::where('customer_id', 42)->first();
```

### Alle TT-aktuellen VT-Perioden

`currentVersion()` stellt nur den TT-Filter (`known_to = MAX`) wieder her und zeigt alle VT-Perioden, die das System aktuell kennt — inklusive historischer.

```php
// Gibt ALLE TT-offenen Records zurück: Rec 1' (alte Adresse) + Rec 2 (aktuelle)
Address::currentVersion()->where('id', 1)->get();
```

### Vollständige Historie

```php
Address::allVersions()->where('id', 1)->get();
// Alle Records inklusive TT-archivierter (Rec 1, Rec 1', Rec 2, ...)
```

### Fachliche Zeitabfrage (VT-Punkt)

```php
// Wo wohnte der Kunde am 15. Mai? (Stand heute)
Address::asOf('2024-05-15')->where('id', 1)->first();

// Wo wohnte der Kunde am 15. Mai, Stand 1. Juni?
Address::asOf('2024-05-15', '2024-06-01')->where('id', 1)->first();
```

### Systemzeit-Abfrage (TT-Punkt)

```php
// Alle VT-Perioden, die am 10. Aug bekannt waren
Price::versionAsOf('2024-08-10')->where('product_id', 7)->get();
// → Rec 2' (Preis 120) und Rec 3 (Preis 130) — Discount noch nicht erfasst
```

### Aktuell gültige VT-Abdeckung

```php
// Preis am 20. Aug (Stand heute)
Price::validAsOf('2024-08-20')->where('product_id', 7)->first(); // 100 (Discount)
```

### VT-Bereichsabfragen

```php
// VT vollständig im Fenster (TT-aktuell)
Price::versionsInValidRange('2024-08-01', '2024-09-30')->where('product_id', 7)->get();

// VT überlappt das Fenster (TT-aktuell)
Price::versionsTouchingValidRange('2024-08-01', '2024-09-30')->where('product_id', 7)->get();
```

### Gelöschte Entities

```php
// Entities ohne verbleibenden TT-offenen Record
Address::deletedSince()->get();

// Seit einem bestimmten Datum gelöscht
Address::deletedSince(now()->subDay())->get();
```

---

## API-Referenz

### Eloquent Scope-Methoden

| Methode | Angewendeter Filter |
|---------|-------------------|
| *(Default Scope)* | `known_to = MAX AND valid_from ≤ heute AND valid_to ≥ heute` |
| `currentVersion()` | `known_to = MAX` (alle TT-offenen, alle VT-Perioden) |
| `allVersions()` | kein Filter — vollständige TT-Historie, alle VT-Perioden |
| `firstVersion()` | `allVersions()` + `ORDER BY known_from ASC` |
| `latestVersion()` | `allVersions()` + `ORDER BY known_from DESC` |
| `asOf($vt, $tt = now())` | `known_from ≤ tt ≤ known_to AND valid_from ≤ vt ≤ valid_to` |
| `validAsOf($vt)` | `known_to = MAX AND valid_from ≤ vt ≤ valid_to` |
| `versionAsOf($tt)` | `known_from ≤ tt ≤ known_to` (alle VT-Perioden zum TT-Zeitpunkt) |
| `versionsInValidRange($from, $to)` | VT vollständig im Fenster, `known_to = MAX` |
| `versionsTouchingValidRange($from, $to)` | VT überlappt das Fenster, `known_to = MAX` |
| `deletedSince($datetime = null)` | kein TT-offener Record vorhanden |
| `skipVersioning()` | deaktiviert den Splitting-Algorithmus für diesen Query |
| `resumeVersioning()` | reaktiviert den Splitting-Algorithmus |

### `asOf($vt, $tt = null)`

VT ist der primäre Parameter — du navigierst die fachliche Zeitlinie. TT hat den Default `now()`, sodass du immer den heutigen Wissensstand siehst.

```php
Address::asOf('2024-05-15')               // Stand heute, VT = 15. Mai
Address::asOf('2024-05-15', '2024-06-01') // Stand 1. Juni, VT = 15. Mai
```

### Builder-Methoden (`DB::table()`)

```php
// Insert — valid_from/valid_to optional; Standard: now/MAX
DB::table('prices')->insert(['product_id' => 7, 'price' => 110,
    'valid_from' => '2024-04-01']);

// Update — löst VT-Splitting aus wenn valid_from/valid_to vorhanden
DB::table('addresses')->where('id', 1)->update([
    'street' => 'Maierstrasse 2', 'valid_from' => '2024-07-15',
]);

// Update ohne VT-Änderung — reine TT-Versionierung (wie uni-temporal)
DB::table('addresses')->where('id', 1)->update(['street' => 'Maierstrasse 4']);

// Delete — schliesst known_to aller TT-offenen Records, kein physisches DELETE
DB::table('addresses')->where('id', 1)->delete();

// Admin: Versionierung umgehen für direkte Historienkorrekturen
DB::table('prices')->skipVersioning()->where('id', 1)->update(['price' => 99]);

// Builder-Zeitreise
DB::table('prices')->asOf('2024-08-15')->where('product_id', 7)->get();
DB::table('prices')->validAsOf('2024-08-20')->where('product_id', 7)->get();
```

> **`insertGetId`**: verwendet `MAX(id) + 1` — bei hohem Concurrency-Aufkommen sind Race Conditions möglich. In Produktion ULIDs oder UUIDs verwenden.

---

## SoftDeletes

`SoftDeletes` und bi-temporales Versioning funktionieren ohne Trait-Konflikte zusammen — `IsBiTemporal` überschreibt `performDeleteOnModel` nicht, `SoftDeletes` übernimmt es direkt.

```php
class Address extends Model implements BiTemporalModel
{
    use IsBiTemporal;
    use SoftDeletes;  // kein insteadof notwendig
}
```

```php
Schema::create('addresses', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->bitemporal();
    $table->string('street');
    $table->softDeletes();
    $table->timestamps();
    $table->bitempIndexes();
});
```

Bei `$address->delete()`:

1. `SoftDeletes` setzt `deleted_at = now()` auf dem Model
2. `save()` läuft → `BiTemporalBuilder::update()` → neue TT-Version mit `deleted_at` wird geöffnet
3. Die alte TT-Version wird geschlossen

Zwei Scopes wirken zusammen:
- `BiTemporalScope`: `WHERE known_to = MAX AND valid_from ≤ heute AND valid_to ≥ heute` → aktueller Record
- `SoftDeletes`: `WHERE deleted_at IS NULL` → versteckt den gelöschten Record

```php
Address::find(1);               // null — von beiden Scopes ausgeblendet
Address::withTrashed()->find(1); // gefunden — TT-aktuelle Version mit deleted_at
Address::allVersions()->where('id', 1)->get(); // vollständige Historie
```

---

## Best Practices

1. **`temporal` als Default-Connection setzen** — nicht-temporale Tabellen passieren unverändert; temporale sind geschützt.
2. **`valid_from` bei `update()` immer explizit angeben** — ohne Angabe wird `now()` verwendet, was für fachliche Korrekturen selten richtig ist.
3. **`allVersions()` bei Änderungen historischer VT-Daten verwenden** — der Default Scope filtert auf VT=heute und schützt vor versehentlichem Ändern vergangener Records.
4. **ULIDs oder UUIDs** statt `insertGetId` bei hohem Concurrency-Aufkommen verwenden.
5. **`skipVersioning()` nur für Admin-Korrekturen** — nie in der normalen Geschäftslogik.
6. **Auf zwei Achsen denken**: VT = *«wann war es in der Fachwelt wahr?»*, TT = *«wann hat das System es gewusst?»*
