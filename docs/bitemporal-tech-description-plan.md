# Technischer Plan: Bi-Temporal Implementierung

## 1. Grundkonzept: Der 2D-Raum

Bi-temporal bedeutet, dass jeder Datensatz eine **Rechteckfläche im 2D-Raum** belegt:

```
TT ∞ ┤                                                 │
     │                                                 │
     │    ┌──────────────┐   ┌──────────────────────── │
     │    │   Rec 1'     │   │        Rec 2            │
     │    │ (alte Adresse│   │    (neue Adresse)        │
     │    │  VT: Apr–Jul │   │  VT: Jul15 → ∞          │
TT   │    │  TT: Jul15→∞)│   │  TT: Jul15 → ∞          │
now ─┼────┴──────────────┴───┴──────────────────────── ┤
     │    ┌─────────────────────────────────────────── │
     │    │             Rec 1 (original)                │
     │    │    VT: Apr01 → ∞,  TT: Jun01 → Jul14       │
TT   │    └──────────────────────────────────────────── │
start┘    │Apr01        │Jul15                        VT ∞
```

**Invariante**: Die Rechtecke einer ID müssen die bisher bekannte Fläche **lückenlos** abdecken. Für jeden Punkt `(TT, VT)` im bereits betretenen Bereich muss genau ein Record gefunden werden.

### Warum zwei Achsen?

| Achse | Spalten | Frage |
|-------|---------|-------|
| **VT** (Valid Time) | `valid_from` / `valid_to` | Wann war es in der Fachwelt wahr? |
| **TT** (Transaction Time) | `known_from` / `known_to` | Wann wusste das System davon? |

Die TT-Achse läuft streng vorwärts (Systemzeit, nie editierbar). Die VT-Achse ist frei setzbar (Vergangenheit, Gegenwart, Zukunft).

---

## 2. Das Problem im bestehenden Ansatz: Lücken durch doppelte Terminierung

Der bisherige einfache Ansatz bei `update()`:
1. Alten Record **beidseitig** terminieren: `valid_to = now - 1s`, `known_to = now - 1s`
2. Neuen Record mit neuem VT-Bereich einfügen: `valid_from = neues_Datum`, `known_from = now`

**Beispiel: Adressänderung – einfacher Ansatz (FALSCH)**

```sql
-- Alt-Record wird BEIDSEITIG abterminiert
UPDATE addresses SET known_to = '2024-07-14 23:59:59', valid_to = '2024-07-14 23:59:59'
WHERE id = 1 AND known_to = '9999-12-31 23:59:59';

-- Neuer Record
INSERT INTO addresses (id, street, valid_from, valid_to, known_from, known_to)
VALUES (1, 'Maierstrasse 2', '2024-07-15', '9999-12-31 23:59:59', '2024-07-15', '9999-12-31 23:59:59');
```

Danach existieren in der Tabelle:

| id | street | valid_from | valid_to | known_from | known_to |
|----|--------|-----------|----------|------------|----------|
| 1 | Musterstrasse 10 | 2024-04-01 | **2024-07-14** | 2024-06-01 | **2024-07-14** |
| 1 | Maierstrasse 2 | 2024-07-15 | 9999 | 2024-07-15 | 9999 |

**Lücke**: Query `asOf(TT='2024-08-01', VT='2024-05-15')` – «Was war am 15. Mai als Adresse bekannt (aus Sicht August)?»

- Rec 1: `known_to = 2024-07-14 < 2024-08-01` → nicht sichtbar ✗
- Rec 2: `valid_from = 2024-07-15 > 2024-05-15` → VT stimmt nicht ✗

→ **Kein Record gefunden, obwohl es eine korrekte Antwort gibt** (Musterstrasse 10 war bis Juli 14 die bekannte Adresse).

---

## 3. Die korrekte Lösung: Splitting durch Remainder-Kopien

Beim Update wird statt beidseitiger Terminierung das folgende Verfahren angewendet:

1. Alten Record nur **TT-seitig** terminieren (VT bleibt unberührt)
2. **Linken Remainder** einfügen: Kopie mit gekürztem VT für den Bereich *vor* dem neuen `valid_from`
3. **Rechten Remainder** einfügen: Kopie mit gekürztem VT für den Bereich *nach* dem neuen `valid_to` (wenn vorhanden)
4. Neuen Record mit den geänderten Werten einfügen

**Adressänderung – korrektes Vorgehen**:

```sql
-- 1. Nur TT-Terminierung (VT bleibt ∞ im alten Record)
UPDATE addresses SET known_to = '2024-07-14 23:59:59'
WHERE id = 1 AND known_to = '9999-12-31 23:59:59';

-- 2. Linker Remainder: alte Daten für VT Apr–Jul14, ab jetzt TT-offen
INSERT INTO addresses (id, street, valid_from, valid_to, known_from, known_to)
VALUES (1, 'Musterstrasse 10', '2024-04-01', '2024-07-14 23:59:59', '2024-07-15', '9999-12-31 23:59:59');

-- 3. Kein rechter Remainder (Rec 1 hatte valid_to = ∞, Rec 2 auch → kein Überhang)

-- 4. Neuer Record
INSERT INTO addresses (id, street, valid_from, valid_to, known_from, known_to)
VALUES (1, 'Maierstrasse 2', '2024-07-15', '9999-12-31 23:59:59', '2024-07-15', '9999-12-31 23:59:59');
```

Ergebnis:

| id | street | valid_from | valid_to | known_from | known_to |
|----|--------|-----------|----------|------------|----------|
| 1 | Musterstrasse 10 | 2024-04-01 | ∞ | 2024-06-01 | **2024-07-14** | ← TT-terminiert |
| 1 | Musterstrasse 10 | 2024-04-01 | **2024-07-14** | **2024-07-15** | ∞ | ← Rec 1' (linker Remainder) |
| 1 | Maierstrasse 2 | **2024-07-15** | ∞ | 2024-07-15 | ∞ | ← Rec 2 (neu) |

Query `asOf(TT='2024-08-01', VT='2024-05-15')` findet jetzt Rec 1' → **Musterstrasse 10** ✓

---

## 4. Der vollständige Update-Algorithmus

### Pseudocode

```
function biTemporalUpdate(wheres, values, now):

  new_vt_from = values.valid_from ?? null
  new_vt_to   = values.valid_to   ?? null

  // TT-offene Records suchen, die mit dem neuen VT-Bereich überlappen
  overlapping = query
    .where(known_to, MAX)
    .where(wheres)
    .when(new_vt_to != null,   add: valid_from <= new_vt_to)
    .when(new_vt_from != null, add: valid_to >= new_vt_from)
    .get()

  FOR EACH old_rec IN overlapping:

    // Linker Remainder: VT-Bereich VOR dem neuen valid_from
    IF new_vt_from != null AND old_rec.valid_from < new_vt_from:
      INSERT copy(old_rec) WITH
        valid_to   = new_vt_from - 1 Tag   // VT ist date → -1 day
        known_from = now
        known_to   = MAX

    // Rechter Remainder: VT-Bereich NACH dem neuen valid_to
    IF new_vt_to != null AND new_vt_to != MAX AND old_rec.valid_to > new_vt_to:
      INSERT copy(old_rec) WITH
        valid_from = new_vt_to + 1 Tag     // VT ist date → +1 day
        known_from = now
        known_to   = MAX

    // Original TT-terminieren
    UPDATE old_rec SET known_to = now - 1 Sekunde  // TT ist dateTime → -1 second

  // Neuen Record einfügen
  INSERT values WITH
    valid_from = new_vt_from ?? now
    valid_to   = new_vt_to   ?? MAX
    known_from = now
    known_to   = MAX
```

### Sonderfälle

| Situation | Verhalten |
|-----------|-----------|
| `valid_from` und `valid_to` nicht in `values` | Kein VT-Wechsel → keine Remainders → pure TT-Versionierung (wie uni-temporal) |
| `old_rec.valid_from == new_vt_from` | Kein linker Remainder (gleiche VT-Startposition, kein Platz) |
| `old_rec.valid_to == new_vt_to` | Kein rechter Remainder (gleiche VT-Endposition) |
| Mehrere TT-offene Records überlappen mit neuem VT-Bereich | Alle werden einzeln gesplittet (Graphic 3: Rec 2' UND Rec 3) |

### Granularität der Zeitgrenzen: `vt_precision`

VT kann je nach Anwendungsfall tagesgranular oder sekundengenau sein:

| Anwendungsfall | Beispiel | Granularität |
|----------------|----------|-------------|
| Adressen, Verträge, Zuständigkeiten | Umzug «am 15.07.» | **day** (Standard) |
| Tagespreise, Fahrpläne | Preis «ab 01.09.» | **day** |
| Intraday-Preise, Börsenkurse | Benzinpreis «15:00–17:00 Uhr» | **datetime** |
| Schichtpläne, SLA-Fenster | Rufbereitschaft «von 22:00 bis 06:00» | **datetime** |

Die Granularität wird pro Tabelle über `vt_precision` in der Config festgelegt (Default: `'day'`).

**Verhalten nach Granularität**:

| | `vt_precision: 'day'` | `vt_precision: 'datetime'` |
|--|----------------------|---------------------------|
| VT-Spaltentyp | `date` | `dateTime` |
| VT Max-Sentinel | `'9999-12-31'` | `'9999-12-31 23:59:59'` |
| VT-Grenze | ±1 Tag (`Carbon::addDay()`) | ±1 Sekunde (`Carbon::addSecond()`) |
| Normalisierung | keine | `valid_to` ohne Zeit → `23:59:59` |
| GUI | zeigt nur Datum | zeigt Datum + Uhrzeit |

TT ist immer `dateTime` mit ±1 Sekunde — unabhängig von `vt_precision`.

**Algorithmus-Grenzen je nach Modus**:

```
// vt_precision = 'day'
Linker Remainder:  valid_to   = new_vt_from - 1 Tag
Rechter Remainder: valid_from = new_vt_to   + 1 Tag

// vt_precision = 'datetime'  (mit Normalisierung: valid_to-Eingabe ohne Zeit → 23:59:59)
Linker Remainder:  valid_to   = new_vt_from - 1 Sekunde
Rechter Remainder: valid_from = new_vt_to   + 1 Sekunde

// TT immer gleich
TT-Terminierung:   known_to   = now - 1 Sekunde
```

**Normalisierung bei `'datetime'`**:

Gibt der User `valid_to = '2024-09-14'` ein (ohne Zeit), wird daraus `'2024-09-14 23:59:59'` — erst dann liefert `+ 1 Sekunde` korrekt `2024-09-15 00:00:00`. Gibt er `'2024-09-14 17:00:00'` ein, bleibt die Zeit erhalten. Die Normalisierung findet im `BiTemporalBuilder` statt.

---

## 5. Delete-Algorithmus

Bi-temporal löschen terminiert **nur die TT-Achse**. Die VT-Fläche bleibt bestehen (historisch nachvollziehbar):

```
function biTemporalDelete(wheres, now):
  UPDATE WHERE [wheres] AND known_to = MAX
  SET known_to = now - 1 Sekunde
```

Kein neuer Record wird eingefügt. Kein VT wird geändert.

---

## 6. Insert-Algorithmus

```
function biTemporalInsert(values, now):
  INSERT values WITH
    valid_from = values.valid_from ?? now
    valid_to   = values.valid_to   ?? MAX
    known_from = now
    known_to   = MAX
```

Keine Überlappungsprüfung beim Insert. Die erste Erfassung setzt die Ausgangsfläche.

---

## 7. Neue Komponenten

### 7.1 `BiTemporalConfig`

Neue readonly Klasse (analog `TemporalConfig`, aber 4 Spalten):

```php
final readonly class BiTemporalConfig
{
    public function __construct(
        public string $columnValidFrom,
        public string $columnValidTo,
        public string $columnKnownFrom,
        public string $columnKnownTo,
        public string $vtPrecision,    // 'day' | 'datetime'
        public string $maxDate,        // VT-Sentinel bei precision='day'
        public string $maxTimestamp,   // TT-Sentinel (und VT bei precision='datetime')
    ) {}

    public function vtMaxSentinel(): string
    {
        return $this->vtPrecision === 'datetime' ? $this->maxTimestamp : $this->maxDate;
    }

    public static function fromArray(?array $data): self { ... }
    public function withOverrides(...): self { ... }
}
```

PHPStan-Shape:
```php
/**
 * @phpstan-type BiTemporalConfigShape array{
 *     column_valid_from?: string,
 *     column_valid_to?: string,
 *     column_known_from?: string,
 *     column_known_to?: string,
 *     vt_precision?: 'day'|'datetime',
 *     max_date?: string,
 *     max_timestamp?: string,
 * }
 * @phpstan-type BiTemporalTablesShape array<string, BiTemporalConfigShape>
 */
```

Fallback-Werte:
- `column_valid_from` → `'valid_from'`
- `column_valid_to`   → `'valid_to'`
- `column_known_from` → `'known_from'`
- `column_known_to`   → `'known_to'`
- `vt_precision`      → `'day'`
- `max_date`          → `'9999-12-31'`
- `max_timestamp`     → `'9999-12-31 23:59:59'`

### 7.2 `BiTemporalBuilder`

Extends `Illuminate\Database\Query\Builder` (eigenständige Klasse, nicht von `UniTemporalBuilder` abgeleitet – zu unterschiedliche Logik).

```php
class BiTemporalBuilder extends Builder
{
    // Konfiguration
    private string $columnValidFrom;
    private string $columnValidTo;
    private string $columnKnownFrom;
    private string $columnKnownTo;
    private string $vtPrecision;   // 'day' | 'datetime'
    private string $maxDate;
    private string $maxTimestamp;
    private bool $calledByEloquent = false;
    private bool $skipVersioning = false;

    // Public API
    public function insert(array $values): bool;
    public function insertGetId(array $values, $sequence = 'id'): int;
    public function update(array $values): int;   // Splitting-Algorithmus
    public function delete($id = null): int;
    public function skipVersioning(): void;
    public function resumeVersioning(): void;
    public function setTemporalConfig(BiTemporalConfig $config, bool $calledByEloquent = false): void;

    // Interne Hilfsmethoden (private)
    private function findOverlappingRecords(?string $vtFrom, ?string $vtTo): Collection;
    private function insertRemainderRecord(array $oldRecord, string $validFrom, string $validTo, Carbon $now): void;
    private function terminateTt(object $oldRecord, Carbon $now): void;
    private function setTemporalTimestamps(array $values, Carbon $now, ?string $vtFrom, ?string $vtTo): array;
}
```

**Wichtig für `update()`**: `copy()` eines Records bedeutet alle Spalten übernehmen, nur `valid_from`, `valid_to`, `known_from`, `known_to` anpassen. Der Rest der Nutzlast bleibt wie im Original.

### 7.3 `BiTemporalModel` Interface

```php
interface BiTemporalModel
{
    public function getColumnValidFrom(): string;
    public function getColumnValidTo(): string;
    public function getColumnKnownFrom(): string;
    public function getColumnKnownTo(): string;
    public function getVtPrecision(): string;      // 'day' | 'datetime'
    public function getVtMaxSentinel(): string;    // '9999-12-31' oder '9999-12-31 23:59:59' je nach Precision
    public function getMaxTimestamp(): string;     // '9999-12-31 23:59:59' — TT immer dateTime
}
```

### 7.4 `IsBiTemporal` Trait

Analog zu `IsUniTemporal`:

```php
/**
 * @phpstan-require-extends Model
 * @phpstan-require-implements BiTemporalModel
 */
trait IsBiTemporal
{
    public static function bootIsBiTemporal(): void;
    public function initializeIsBiTemporal(): void;   // Casts für 4 Spalten

    public function getColumnValidFrom(): string;
    public function getColumnValidTo(): string;
    public function getColumnKnownFrom(): string;
    public function getColumnKnownTo(): string;
    public function getVtPrecision(): string;
    public function getVtMaxSentinel(): string;
    public function getMaxTimestamp(): string;

    protected function newBaseQueryBuilder(): BiTemporalBuilder;
    protected function performInsert(Builder $query): bool;  // setzt 4 Timestamps
    private function resolveTemporalConfig(): BiTemporalConfig;
    private function loadBaseTemporalConfig(): BiTemporalConfig;
    private function constantIfDefined(string $name): ?string;
}
```

Modell-Konstanten (nur Eloquent, ohne Connection-Config):

| Konstante | Typ | Bedeutung |
|-----------|-----|-----------|
| `COLUMN_VALID_FROM` | string | VT-from Spaltenname |
| `COLUMN_VALID_TO` | string | VT-to Spaltenname |
| `COLUMN_KNOWN_FROM` | string | TT-from Spaltenname |
| `COLUMN_KNOWN_TO` | string | TT-to Spaltenname |
| `VT_PRECISION` | `'day'\|'datetime'` | **Pflicht wenn kein Connection-Config** — steuert Spaltentyp, Sentinel und Grenzenberechnung |
| `MAX_DATE` | string | Override VT-Sentinel bei `precision='day'` — Standard `'9999-12-31'` |
| `MAX_TIMESTAMP` | string | Override TT-Sentinel (und VT-Sentinel bei `precision='datetime'`) — Standard `'9999-12-31 23:59:59'` |

**Wichtig**: `MAX_DATE` und `MAX_TIMESTAMP` sind reine Override-Konstanten für den seltenen Fall eines nicht-standard Sentinels. `VT_PRECISION` allein reicht für den Normalfall — der Trait leitet den korrekten Sentinel daraus ab:
- `VT_PRECISION = 'day'` → VT-Sentinel = `'9999-12-31'`
- `VT_PRECISION = 'datetime'` → VT-Sentinel = `'9999-12-31 23:59:59'`

```php
// Minimal: nur Pflicht-Konstante für day-Precision
class Address extends Model implements BiTemporalModel
{
    use IsBiTemporal;
    const VT_PRECISION = 'day';   // VT ist date, Grenzen ±1 Tag
}

// Intraday-Preise
class FuelPrice extends Model implements BiTemporalModel
{
    use IsBiTemporal;
    const VT_PRECISION = 'datetime';   // VT ist dateTime, Grenzen ±1 Sekunde
}

// Mit abweichenden Spaltennamen
class Contract extends Model implements BiTemporalModel
{
    use IsBiTemporal;
    const VT_PRECISION    = 'day';
    const COLUMN_VALID_FROM = 'app_from';
    const COLUMN_VALID_TO   = 'app_to';
    const COLUMN_KNOWN_FROM = 'sys_from';
    const COLUMN_KNOWN_TO   = 'sys_to';
}
```

### 7.5 `BiTemporalScope`

Analog zu `UniTemporalScope`. Global Scope = `WHERE known_to = MAX` (**nur TT-Achse**).

```php
class BiTemporalScope implements Scope
{
    protected array $extensions = [
        'CurrentVersion',
        'AllVersions',
        'FirstVersion',
        'LatestVersion',
        'VersionAsOf',        // TT-Zeitpunkt-Abfrage
        'ValidAsOf',          // VT-Zeitpunkt-Abfrage (TT-aktuell)
        'AsOf',               // TT + VT kombiniert
        'VersionsInValidRange',       // VT vollständig im Fenster
        'VersionsTouchingValidRange', // VT überschneidet Fenster
    ];

    public function apply(Builder $builder, Model $model): void
    {
        // Global Scope: nur aktuelle TT-Version
        $builder->where($model->getColumnKnownTo(), $model->getMaxTimestamp());
    }
}
```

**Scope-Implementierungen**:

| Extension | Bedingung |
|-----------|-----------|
| `currentVersion()` | `WHERE known_to = MAX` (Scope wiederherstellen) |
| `allVersions()` | kein Filter (alle TT-Versionen, alle VT-Bereiche) |
| `firstVersion()` | `allVersions()` + `ORDER BY known_from ASC` |
| `latestVersion()` | `allVersions()` + `ORDER BY known_from DESC` |
| `versionAsOf($tt)` | `WHERE known_from <= tt AND known_to >= tt` |
| `validAsOf($vt)` | `WHERE valid_from <= vt AND valid_to >= vt AND known_to = MAX` |
| `asOf($tt, $vt)` | `WHERE known_from <= tt AND known_to >= tt AND valid_from <= vt AND valid_to >= vt` |
| `versionsInValidRange($from, $to)` | `WHERE valid_from >= from AND valid_to <= to AND known_to = MAX` |
| `versionsTouchingValidRange($from, $to)` | `WHERE valid_to >= from AND valid_from <= to AND known_to = MAX` |

**Bedeutung des Global Scope**: `known_to = MAX` zeigt alle aktuell bekannten VT-Perioden (mehrere Records pro ID sind normal, z.B. historische Adresse + aktuelle Adresse). Wer nur die heute-gültige VT-Periode will, nutzt `validAsOf(now())`.

---

## 8. Erweiterungen bestehender Klassen

### `TemporalConnection`

Erweiterung um bi-temporale Tabellenconfig:

```php
// Konstruktor: zusätzlich bi-temporal config einlesen
$this->biTemporalDefaults = BiTemporalConfig::fromArray($config['bi-temporal']['defaults'] ?? null);
$this->biTemporalTableConfigs = $config['bi-temporal']['tables'] ?? [];

// Neue Methoden
public function getBiTemporalTableConfig(string $table): ?BiTemporalConfig;
public function getBiTemporalDefaults(): BiTemporalConfig;
```

In `table()`: wenn Tabelle in `bi-temporal.tables` → `BiTemporalBuilder` zurückgeben; uni-temporal hat Vorrang (Tabellen können nicht gleichzeitig in beiden stehen).

### `TemporalConnectionConfigShape` (PHPStan)

```php
/**
 * @phpstan-type TemporalConnectionConfigShape array{
 *     base?: string,
 *     driver?: string,
 *     uni-temporal?: array{ defaults?: ..., tables?: ... },
 *     bi-temporal?: array{
 *         defaults?: BiTemporalConfigShape,
 *         tables?: BiTemporalTablesShape,
 *     },
 * }
 */
```

### `LaravelDbTemporalServiceProvider`

Neue Schema-Makros:

```php
Blueprint::macro('bitemporal', function (): void {
    $config = static::resolveBiTemporalDefaults();
    $vtColumn = $config->vtPrecision === 'datetime' ? 'dateTime' : 'date';
    $this->{$vtColumn}($config->columnValidFrom);  // date oder dateTime je nach vt_precision
    $this->{$vtColumn}($config->columnValidTo);
    $this->dateTime($config->columnKnownFrom);      // TT: immer dateTime
    $this->dateTime($config->columnKnownTo);
});

Blueprint::macro('bitempIndexes', function (string $pk = 'id'): void {
    $config = static::resolveBiTemporalDefaults();
    $this->primary([$pk, $config->columnValidTo, $config->columnKnownTo]);
    $this->index([$config->columnKnownTo, $config->columnValidTo, $pk]);
    $this->index([$config->columnValidTo, $config->columnKnownTo, $pk]);
});

public static function resolveBiTemporalDefaults(): BiTemporalConfig
{
    // analog resolveTemporalDefaults(), liest 'bi-temporal.defaults'
}
```

---

## 9. Connection-Config-Struktur

```php
'temporal' => [
    'driver' => 'temporal-proxy',
    'base'   => 'mysql',

    'uni-temporal' => [          // bestehend, unverändert
        'defaults' => [...],
        'tables'   => [...],
    ],

    'bi-temporal' => [           // neu
        'defaults' => [
            'column_valid_from' => 'valid_from',
            'column_valid_to'   => 'valid_to',
            'column_known_from' => 'known_from',
            'column_known_to'   => 'known_to',
            'vt_precision'      => 'day',                  // Standard: date-Granularität
            'max_date'          => '9999-12-31',           // VT-Sentinel (precision='day')
            'max_timestamp'     => '9999-12-31 23:59:59',  // TT-Sentinel + VT-Sentinel (precision='datetime')
        ],
        'tables' => [
            'addresses'   => [],              // day-precision (Default)
            'fuel_prices' => [               // Intraday-Preise: datetime-precision
                'vt_precision' => 'datetime',
            ],
            'contracts' => [                 // abweichende Spaltennamen, day-precision
                'column_valid_from' => 'app_from',
                'column_valid_to'   => 'app_to',
                'column_known_from' => 'sys_from',
                'column_known_to'   => 'sys_to',
            ],
        ],
    ],
],
```

---

## 10. Primärschlüssel und Indizes

### Analyse: Wie viele Spalten braucht der PK?

`(id, valid_to, known_to)` ist **theoretisch ausreichend** für Eindeutigkeit. Zwei Rechtecke mit identischer rechter oberer Ecke (`valid_to`, `known_to`) würden sich im 2D-Raum zwingend überlappen (eines enthält das andere) — das verletzt die Tiling-Invariante. Ein korrektes bi-temporales System kann diesen Zustand nicht erzeugen.

Dasselbe gilt für `(id, valid_from, known_from)`, `(id, valid_from, known_to)` usw. — jede Ecke des Rechtecks ist ein gültiger 3-Spalten-PK.

### Empfehlung: 3-Spalten-PK `(id, valid_to, known_to)`

| Typ | Zusammensetzung | Begründung |
|-----|----------------|-----------|
| **Primary Key** | `(id, valid_to, known_to)` | Minimal, theoretisch vollständig, deckt häufigste Queries ab |
| Index 1 | `(known_to, id)` | Schneller Current-State-Scan (`known_to = MAX`) |
| Index 2 | `(valid_to, known_to, id)` | VT-Punkt- und Bereichsqueries mit TT-Filter |
| Index 3 | `(known_to, valid_to, id)` | TT-Punkt-Queries mit VT-Filter |

Der 3-Spalten-PK ist der **stärkere** Integritätsschutz — nicht der schwächere. Ein Fehler im Splitting-Algorithmus, der zwei Rechtecke mit identischer rechter oberer Ecke erzeugt (Overlap-Verletzung), löst sofort einen Unique-Constraint-Fehler aus. Genau das will man: der Fehler ist sichtbar und erzwingt eine Ursachensuche.

Der 5-Spalten-PK `(id, valid_from, valid_to, known_from, known_to)` würde denselben fehlerhaften Zustand stillschweigend akzeptieren, weil sich `valid_from` oder `known_from` unterscheiden können — die Datenverfälschung bleibt unbemerkt.

Besonders kritisch: Zwei TT-offene Records `(id, valid_to, known_to=MAX)` können nie gleichzeitig korrekt sein. Der 3-Spalten-PK macht genau das zur Datenbankregel.

Das `bitempIndexes()`-Makro legt den 3-Spalten-PK an. Der 5-Spalten-PK ist keine empfohlene Alternative.

**Zusätzliche Unique-Constraints (theoretisch möglich, nicht empfohlen)**

In uni-temporal wären `UNIQUE(id, known_from)` und `UNIQUE(id, known_to)` theoretisch sinnvoll — jede TT-Grenze ist für eine ID eindeutig. In bi-temporal gilt das nicht mehr: im Split-Algorithmus erhalten mehrere neue Records denselben `known_from = now` (Remainder + neuer Record), und mehrere TT-terminierte Records erhalten denselben `known_to = now - 1s`. Solche Constraints wären also falsch für bi-temporal. Da der Code durch Tests abgedeckt ist, brauchen wir diese Constraints nicht.

---

## 11. Resolver-Kette (Column-Namen)

Analog uni-temporal, erster Treffer gewinnt:

1. Model-Konstanten (`COLUMN_VALID_FROM`, `COLUMN_VALID_TO`, `COLUMN_KNOWN_FROM`, `COLUMN_KNOWN_TO`, `VT_PRECISION`, `MAX_DATE`, `MAX_TIMESTAMP`)
2. Table-Config (`bi-temporal.tables.<table>.*`)
3. Connection-Defaults (`bi-temporal.defaults.*`)
4. Hardcoded Fallback (`valid_from`, `valid_to`, `known_from`, `known_to`, `'day'`, `'9999-12-31'`, `'9999-12-31 23:59:59'`)

---

## 12. Test-Strategie

### Strukturtests (Lückenlosigkeit)

Für jeden der drei Grafik-Szenarien:
```php
// Nach jeder Operation: Query auf jeden relevanten (TT, VT)-Punkt muss genau einen Record finden
$rec = Table::asOf($tt, $vt)->where('id', $id)->first();
expect($rec)->not->toBeNull();
```

### Szenario-Tests (analog Grafiken)

**Grafik 1 – Normales Update (Adressänderung)**:
- `insert` → 1 Record
- `update` mit neuem `valid_from` → 3 Records total (Rec1 TT-terminiert, Rec1' Remainder, Rec2 neu)
- Verify: `asOf(TT=nach_update, VT=vor_vt_from)` → alten Wert finden (Rec1')
- Verify: `asOf(TT=nach_update, VT=nach_vt_from)` → neuen Wert finden (Rec2)

**Grafik 2 – Korrektur (gleicher VT-Start)**:
- Nach Grafik-1-Zustand: `update` mit gleichem `valid_from` → kein linker Remainder
- Verify: Rec2 TT-terminiert, Rec3 mit korrigierten Daten offen
- Verify: `asOf(TT=vor_korrektur, VT=irgendwo)` → alten (falschen) Wert finden

**Grafik 3 – Einschub zwischen bestehende Records**:
- Ausgangslage: 3 Preisperioden
- `update` mit `valid_from=2024-08-15, valid_to=2024-09-14` → 2 Remainders + 1 neuer Record
- Verify: linker Remainder (Rec2''), rechter Remainder (Rec3'), neuer Record (Rec4)
- Verify: keine Lücken in der 2D-Fläche

### Unit-Tests

- `BiTemporalBuilder::update()` mit verschiedenen VT-Kombinationen
- `BiTemporalScope`-Methoden
- `BiTemporalConfig::fromArray()` und `withOverrides()`
- Schema-Makros: `bitemporal()` + `bitempIndexes()`
- `TemporalConnection::getBiTemporalTableConfig()`

### Negativ-Tests

- `skipVersioning()` deaktiviert das Splitting
- `insert()` ohne explizite VT → `valid_from = now`, `valid_to = MAX`
- `delete()` ändert nur `known_to`, nie `valid_to`

---

## 13. Abgrenzung zu Uni-Temporal

| | Uni-Temporal | Bi-Temporal |
|--|-------------|-------------|
| Spalten | 2 | 4 |
| Update-Kern | TT-close + reopen | TT-close + Remainder-Kopien + reopen |
| Delete | `known_to = now - 1s` | `known_to = now - 1s` (identisch) |
| Global Scope | `known_to = MAX` | `known_to = MAX` (nur TT!) |
| Primärschlüssel | `(id, known_from, known_to)` | `(id, valid_from, valid_to, known_from, known_to)` |
| Trait | `IsUniTemporal` | `IsBiTemporal` |
| Interface | `UniTemporalModel` | `BiTemporalModel` |
| Scope | `UniTemporalScope` | `BiTemporalScope` |
| Builder | `UniTemporalBuilder` | `BiTemporalBuilder` |
| Config | `TemporalConfig` | `BiTemporalConfig` |
| Makros | `unitemporal()`, `unitempIndexes()` | `bitemporal()`, `bitempIndexes()` |
