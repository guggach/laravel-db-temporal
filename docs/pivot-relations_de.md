# Temporale Pivot-Relationen

Many-to-many-Relationen, deren Pivot-Tabelle temporal ist. Die Relation behält
die gewohnte Laravel-API (`attach`, `detach`, `sync`, `updateExistingPivot`,
`toggle`, `$model->relation`, Eager Loading), versioniert aber die Pivot-Tabelle,
statt sie in-place zu verändern: Reads liefern nur den aktuellen Zustand,
Schreibzugriffe erzeugen neue Versionen.

- Uni-temporal: `known_from` / `known_to`
- Bi-temporal: `valid_from` / `valid_to` + `known_from` / `known_to`
- Optionales SoftDelete via `deleted_at`

Die Prämisse: Ein temporales Pivot verhält sich aus Sicht des Aufrufers wie ein
normales Laravel-Pivot. Zusätzliche Regeln gelten nur dort, wo die Zeitachse es
erfordert.

## Aktivierung

`HasTemporalPivotRelations` am Model verwenden, das die Relation deklariert:

```php
use Guggach\LaravelDbTemporal\Eloquent\HasTemporalPivotRelations;

class Company extends Model
{
    use HasTemporalPivotRelations;

    public function contactPersons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'company_contact_persons')
            ->withPivot('role_at_company', 'is_primary');
    }
}
```

`belongsToMany()` und `morphToMany()` liefern nun die temporalen
Relation-Klassen. Nicht-temporale Pivot-Tabellen verhalten sich exakt wie
bisher.

## Erkennung

Der temporale Modus der Pivot-Tabelle wird in dieser Reihenfolge ermittelt:

1. **Marker-Trait am `using`-Model** — `IsUniTemporalPivot` oder
   `IsBiTemporalPivot` (auch `IsUniTemporal` / `IsBiTemporal`). Optionales
   `SoftDeletes` am Pivot-Model aktiviert SoftDelete.
2. **Connection-Config** — die Tabelle steht in
   `database.connections.temporal['uni-temporal'|'bi-temporal']['tables']`.
3. **Schema** — die Pivot-Tabelle hat `known_from` + `known_to` (uni-temporal)
   oder zusätzlich `valid_from` + `valid_to` (bi-temporal).

Die Registrierung in der Connection-Config ist nur nötig, wenn direkt über die
`DB`-Facade zugegriffen wird. Für reinen Eloquent-Einsatz genügen Schema oder
Marker-Trait. Nicht-temporale Tabellen werden nie angefasst.

## Uni-temporale Semantik

| Methode | Verhalten |
| --- | --- |
| Read (`$model->relation`, Eager Load, `count()`) | `known_to = max` (+ `deleted_at IS NULL`) |
| `attach` | fügt eine neue Version ein (`known_from = now`, `known_to = max`) |
| `detach` | schliesst `known_to`; setzt zusätzlich `deleted_at`, wenn die Spalte existiert |
| `sync` | nutzt den gescopten Current-State, schreibt nur Differenzen (kein Versions-Churn) |
| `updateExistingPivot` | schliesst die aktuelle Version und fügt eine neue ein |

Die temporalen Spalten werden automatisch selektiert und stehen am Pivot zur
Verfügung:

```php
$company->contactPersons->first()->pivot->known_to; // '9999-12-31 23:59:59'
```

Duplikat-Schutz: ein Datenbank-Unique-Index auf
`(foreign_key, related_key, known_to)` (beide aktuellen Zeilen teilen
`known_to = max`, dadurch ist höchstens eine aktuelle Zeile pro Paar möglich).
Ein doppeltes `attach` wirft dann eine `QueryException` — wie bei einem normalen
Laravel-Pivot.

## Bi-temporale Semantik

| Methode | Verhalten |
| --- | --- |
| Read | `valid_from <= heute <= valid_to` ∧ `known_to = max` (+ `deleted_at IS NULL`) |
| `attach` | `valid_from = heute`, `valid_to = Sentinel`, `known_from = now`, `known_to = max`; explizite `valid_from`/`valid_to` werden übernommen |
| `detach` | schliesst die Gültigkeit: neue Version mit `valid_to` auf der letzten gültigen Grenze (gestern / jetzt−1s), ohne Recht-Remainder |
| `sync` | Current-State = gültig heute ∧ bekannt jetzt ∧ nicht gelöscht; Entfernen schliesst die Gültigkeit |
| `updateExistingPivot` | nutzt die bestehende Valid-Time-Split-Logik, wenn sich `valid_from`/`valid_to` ändern, sonst reine Transaktionszeit-Versionierung |

Ein `valid_from` in der Zukunft wird gespeichert, aber vom Current-Read nicht
geliefert (der Filter ist inklusiv). Lesbar ist es über `validAsOf()` /
`asOf()`.

### As-of-Reads

```php
// gültig zum fachlichen Stichtag, bekannt jetzt
$company->contactPersons()->validAsOf('2024-06-01')->get();

// gültig zum Stichtag, bekannt zu einem bestimmten Zeitpunkt
$company->contactPersons()->asOf('2024-06-01', '2024-12-31')->get();

// Zustand zum Wissensstand, ohne Gültigkeitsfilter
$company->contactPersons()->knownAsOf('2024-12-31')->get();
```

> Hinweis: `asOf()` baut die Relation-Query aus der bei der Relationserzeugung
> gesicherten Basis-Query neu auf. Zusätzliche Constraints aus der
> Relation-Definition (z.B. `->where('active', 1)`) werden nicht übernommen.

## Pivot-Models (`->using()`)

Ein eigenes Pivot-Model kann die Tabelle markieren und Attribute/Casts
bereitstellen:

```php
use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporalPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ContactPersonPivot extends Pivot
{
    use IsUniTemporalPivot;

    protected $table = 'company_contact_persons';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}

// Relation
return $this->belongsToMany(Person::class, 'company_contact_persons')
    ->using(ContactPersonPivot::class);
```

Mit `->using()` aktiviert `SoftDeletes` am Pivot-Model das SoftDelete auch dann,
wenn die Tabelle nicht als solche erkannt wird. Attribut-Casts werden bei
`attach` und `updateExistingPivot` angewandt. Die eigentliche Versionierung
macht immer die Relation — für die Versionierung nicht auf
`$pivot->save()`/`$pivot->delete()` verlassen.

## Anforderungen an die Pivot-Tabelle

- Temporale Spalten, Defaults `known_from`/`known_to` (bi-temporal zusätzlich
  `valid_from`/`valid_to`).
- Entweder ein kombinierter Primary Key
  `(foreign, related, <to>[, known_to])` (kein Surrogat-`id`) oder ein
  Surrogat-`id` plus temporale Spalten. Bei Surrogat-`id` vergibt die Relation
  beim `attach` die nächste ID.
- Ein Unique-Index auf `(foreign, related, known_to)` (uni-temporal) bzw.
  `(foreign, related, valid_to, known_to)` (bi-temporal), um doppelte aktuelle
  Zeilen zu verhindern.
- Optional `deleted_at` für SoftDelete.

Beispiel-Migration (uni-temporal, kombinierter Key):

```php
Schema::create('company_contact_persons', function (Blueprint $table) {
    $table->unsignedBigInteger('company_id');
    $table->unsignedBigInteger('person_id');
    $table->unitemporal();
    $table->string('role_at_company')->nullable();
    $table->boolean('is_primary')->default(false);
    $table->softDeletes();
    $table->primary(['company_id', 'person_id', 'known_from', 'known_to']);
    $table->unique(['company_id', 'person_id', 'known_to']);
});
```

Beispiel-Migration (bi-temporal, Surrogat-`id`):

```php
Schema::create('company_contact_persons', function (Blueprint $table) {
    $table->unsignedBigInteger('id');
    $table->unsignedBigInteger('company_id');
    $table->unsignedBigInteger('person_id');
    $table->bitemporal();
    $table->string('role_at_company')->nullable();
    $table->softDeletes();
    $table->primary(['id', 'valid_to', 'known_to']);
    $table->unique(['company_id', 'person_id', 'valid_to', 'known_to']);
});
```

## API-Referenz

| Methode | Beschreibung |
| --- | --- |
| `attach($ids, $attributes = [])` | `$ids` ist die Related-Seite; die foreign-Seite kommt aus dem Parent. Weitere Spalten gehören in `$attributes`. |
| `detach($ids = null)` | Entfernt/schliesst die aktuellen Links. `null` entfernt alle Links des Parents. |
| `sync($ids)` | Behält nur die angegebenen Related-IDs als aktuell; nur Differenzen. |
| `syncWithoutDetaching($ids)` | Fügt fehlende Links hinzu, ohne bestehende zu entfernen. |
| `updateExistingPivot($id, $attributes)` | Versioniert die Pivot-Zeile der angegebenen Related-ID. |
| `toggle($ids)` | Hängt gelöste IDs an und löst angehängte. |
| `validAsOf($valid)` | Bi-temporal: gültiger Zustand zum Stichtag, bekannt jetzt. |
| `asOf($valid, $known = null)` | Bi-temporal: Zustand zu `$valid`/`$known`. |
| `knownAsOf($known)` | Bi-temporal: Zustand zum Wissensstand, ohne Gültigkeitsfilter. |

## Hinweise und Einschränkungen

- `detach` schliesst bei einem bi-temporalen Pivot die Gültigkeit; eine eigene
  SoftDelete-Variante (`forceDetach`) gibt es noch nicht.
- `asOf()` übernimmt keine zusätzlichen Constraints aus der Relation-Definition.
- IDs werden mit `max(id) + 1` vergeben; bei hoher Nebenläufigkeit eigene IDs
  oder eine Sequenz verwenden.
- `attach` schreibt über den Query-Builder (mit angewandten Pivot-Casts); das
  `save()` des Pivot-Models wird nicht verwendet.
- Bi-temporale Pivots sind vor allem sinnvoll, wenn zusätzliche Business-Zeit-
  Attribute es erfordern. Für komplexe Fälle ist eine eigene Entitätstabelle mit
  `hasMany` oft einfacher als ein bi-temporales Pivot.
