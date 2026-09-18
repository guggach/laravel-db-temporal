# Ticket: Pivot-Relationen für temporale Tabellen (`belongsToMany` / `morphToMany`)

**Paket:** `guggach/laravel-db-temporal`
**Datum:** 2026-09-17
**Status:** entschieden — offene Fragen 1–36 geklärt; Umsetzung gem. `pivot-relations-decisions.md`
**Betroffen:** Laravel 13.30.1, uni- **und** bi-temporale Tabellen

---

## 1. Problem

Auf temporalen Tabellen funktionieren die Eloquent-Pivot-Funktionen nicht korrekt, sobald man
`belongsToMany` / `morphToMany` mit `attach()` / `detach()` / `sync()` / `updateExistingPivot()`
oder Pivot-Reads nutzt. Die Fehlerbilder reichen von hartem Crash (NOT NULL `id`) bis zu stillen
Datenfehlern (historische und soft-deleted Versionen erscheinen als „aktuell"). Das betrifft
**uni- und bi-temporale** Tabellen.

**Ziel:** Alle Laravel-Pivot-Funktionen arbeiten out-of-the-box korrekt. Nicht-temporale
Pivot-Tabellen verhalten sich unverändert.

---

## 2. Root Causes (verifiziert am installierten Code)

1. **Die Builder-Herkunft des Pivot-Statements ist zufällig.**
   `InteractsWithPivotTable::newPivotStatement()` (`vendor/laravel/framework/.../Relations/Concerns/InteractsWithPivotTable.php:648`):

   ```php
   return $this->query->getQuery()->newQuery()->from($this->table);
   ```

   `$this->query` ist der Eloquent-Builder des **related Models** (`Relation::__construct`,
   `Relations/Relation.php:99`). `getQuery()` ist dessen Base-Builder; `newQuery()`
   (`Illuminate/Database/Query/Builder.php:4602`) liefert `new static(...)` — also die
   Base-Builder-**Klasse des related Models**, nicht die der Pivot-Tabelle. Ohne Pivot-Config,
   ohne Eloquent-Global-Scopes.

2. **`attach()` ohne `using` verliert die ID.**
   `attach()` (`InteractsWithPivotTable.php:344`) → `newPivotStatement()->insert($records)`.
   `baseAttachRecord()` (`:453`) schreibt nur `relatedPivotKey`, `foreignPivotKey`, Timestamps
   und Pivot-Werte — **keine `id`**. `UniTemporalBuilder::insert()` (`src/Database/Query/UniTemporalBuilder.php:76`)
   bzw. `BiTemporalBuilder::insert()` (`src/Database/Query/BiTemporalBuilder.php:107`) setzen nur
   Zeitfelder. Nur `insertGetId()` (`UniTemporalBuilder.php:111`, `BiTemporalBuilder.php:123`)
   vergibt `id`; bi-temporal setzt dort zusätzlich `valid_from`/`valid_to`.
   → Crash auf `(id, known_from, known_to)` bzw. `(id, valid_to, known_to)` mit NOT NULL.

3. **Current-State-Reads ohne temporale/SoftDelete-Scopes.**
   `getCurrentlyAttachedPivotsForIds()` (`InteractsWithPivotTable.php:594`) nutzt
   `newPivotQuery()` (`:669`) → Base-Builder. Es fehlen `known_to = max` (UniTemporalScope)
   und `deleted_at IS NULL` (SoftDeletes). Kaputt dadurch: `sync()`, `detachUsingCustomClass()`
   (`:572`), `updateExistingPivotUsingCustomClass()` (`:311`).

4. **Relation-Reads und Eager-Loads sind ungefiltert.**
   `BelongsToMany::performJoin()` (`Relations/BelongsToMany.php:225`) und `addConstraints()`
   (`:210`) joinen die Pivot-Tabelle ohne Versions- und SoftDelete-Filter. Historische und
   gelöschte Zeilen erscheinen im Ergebnis (inkl. Duplikate).

5. **`detach()` ohne `using` soft-deletet nicht.**
   `detach()` (`:519`, `:542`) → `$query->delete()` auf einem Base-Builder. Es gibt kein Model,
   also greift `SoftDeletes::performDeleteOnModel()` (`Database/Eloquent/SoftDeletes.php:121`)
   nicht. `UniTemporalBuilder::delete()` (`:188`) schliesst nur `known_to`.
   (Im `using`-Pfad `$record->delete()` greift SoftDeletes dagegen korrekt.)

### 2a. Hintergrund: Laravels Pivot-Identität (Kontext)

Laravel erzwingt **kein** Surrogat-`id`; die Konvention ist eine flache Tabelle mit
**kombiniertem Schlüssel der beiden Fremdschlüssel** (z.B. Spatie `role_has_permissions`:
`primary([$permission, $role])`). `Pivot` setzt `$incrementing = false` und
`$guarded = []` (`Relations/Pivot.php`) und erzeugt nie selbst eine ID.

`AsPivot` unterstützt beide Varianten explizit:

- `setKeysForSelectQuery()` (`AsPivot.php:100`) / `setKeysForSaveQuery()` (`:121`):
  mit `id`-Attribut → Parent (id-basiert), sonst `where foreignKey = … AND relatedKey = …`.
- `delete()` (`AsPivot.php:131`): mit `id` → `parent::delete()` (SoftDeletes greift), ohne `id`
  → `getDeleteQuery()->delete()` (hard delete per FK-Paar).

**Konsequenz für temporal:** Die eingebauten Helfer identifizieren eine Zuordnung über das
**FK-Paar** (bzw. den related Key), nicht über `id` — `detach()` (`InteractsWithPivotTable.php:519`),
`updateExistingPivot()` (`:267`), `newPivotStatementForId()` (`:659`). Ein zusätzlicher
`id`-Cluster allein behebt das nicht: bei mehreren Versionen pro Paar würden diese Methoden
weiterhin alle Versionen treffen. „Aktuell" muss über die Scopes (`known_to = max`,
`deleted_at IS NULL`, bi-temporal zusätzlich Gültigkeit) definiert werden — nicht über einen
Surrogat-Schlüssel. Das ist der Unterschied zu einer flachen Pivot-Tabelle und der Grund, warum
die Standard-Helfer hier strukturell nicht ausreichen.

### 2b. Composite-Key-Fähigkeit (Ist-Stand, empirisch belegt)

Geprüft mit `tests/Feature/CompositeKeyCharacterizationTest.php` an einer reinen
Composite-Key-Tabelle `[a_id, b_id, known_from, known_to]` (**kein `id`**):

| Pfad | Aufruf | Ergebnis |
| --- | --- | --- |
| Query-Builder | `insert([...])` | ✅ funktioniert (ergänzt `known_*`) |
| Query-Builder | `update([...])` + composite WHERE | ✅ versioniert (schliesst `known_to`, neue Zeile) |
| Query-Builder | `delete()` + composite WHERE | ✅ schliesst `known_to` |
| Query-Builder | `insertGetId([...])` | ❌ erzeugt Surrogat-`id` → „no column named id" |
| Query-Builder | `delete($id)` | ❌ hardcodiert `table.id` → „no such column" |
| Query-Builder | `max('id')` ohne `id`-Spalte | ⚠️ liefert `null` (kein Fehler) → `performInsert` schreibt still `id = 1` |
| Eloquent | `Model::create([...])` | ❌ injiziert `id` → „no column named id" |
| Eloquent | `$primaryKey = 'a_id'` + Key gesetzt | ⚠️ läuft, behandelt aber nur **eine** Spalte als Key (falsch für echte Composite-Keys) |

Statische Befunde ergänzend:

- `UniTemporalScope::apply()` / `BiTemporalScope` filtern nur `known_to = max` — key-agnostisch,
  Reads funktionieren also auch für Composite. `deletedSince` und die korrelierten Subqueries
  nutzen aber `$model->getKeyName()` (eine Spalte) → falsch für Composite.
- `IsUniTemporal::performInsert()` / `IsBiTemporal::performInsert()` erzeugen immer einen
  Surrogat-Key über `getKeyName()` (Eloquent kennt keine echten Composite-PKs).
- Migrationsmakros `unitempIndexes(string $pk = 'id')` / `bitempIndexes(string $pk = 'id')`
  akzeptieren nur **eine** Key-Spalte; ein 4-spaltiger PK muss manuell über
  `$table->primary([...])` gesetzt werden.
- Bi-temporal: `insert()`/`insertGetId()` setzen `valid_*` ebenfalls nur über den
  Surrogat-Pfad; echte Composite-Validität ist nicht abgedeckt (siehe offene Fragen 5.4/5.6).

**Konsequenz:** Am DB-/Query-Level ist der Kern (insert/update/delete) composite-fähig, aber die
Single-Key-Helfer (`insertGetId`, `delete($id)`) und der gesamte Eloquent-Pfad setzen einen
einzelnen Key voraus. Für Pivot-Relationen (Spatie-Stil ohne `id`) ist das der Haupt-Gap.

### 2c. Eloquent-Primary-Key-Constraint — Design-Konsequenz

Laravel-Doku „Eloquent › Primary Keys › Composite Primary Keys": Eloquent braucht pro Model
genau eine eindeutige „ID"; **echte Composite-PKs werden nicht unterstützt**. Pivot-Tabellen
sind die Ausnahme, weil Laravel sie speziell behandelt (Identität über das FK-Paar).

Daraus folgt für das Package:

1. **Composite-PK gehört in die Pivot-Funktionen**, nicht in den Eloquent-Model-Key. Der Proxy
   muss `attach`/`detach`/`sync`/`updateExistingPivot` und die Pivot-Reads so bauen, dass sie
   das FK-Paar (+ Versionsspalten) als Identität verstehen — unabhängig von einer Surrogat-`id`.
2. **`id`-Nuance:** `AsPivot` nutzt eine vorhandene `id` für `setKeysForSelectQuery` / `save` /
   `delete` (dort greift SoftDeletes). Die Many-to-Many-Helfer in `InteractsWithPivotTable`
   nutzen sie **nicht**. Für den Pivot-Fix ist die `id` daher irrelevant; ein optionaler
   `id`-Cluster bleibt nur für den `using`-Model-Pfad nützlich.
3. **Kein Verlass auf das related Model:** `newPivotStatement()` leitet sich vom *related* Model
   ab (siehe Root Cause 1). Deshalb hing das Verhalten bisher davon ab, welche Seite
   `belongsToMany` deklariert und ob diese Seite temporal ist. Der Fix muss die
   **Pivot-Tabellen-Config** nutzen, nicht das related Model.
4. **Pivot-Trait/-Model bereitstellen:** Für den `using`-Pfad braucht das Pivot-Model selbst
   Temporalität + SoftDeletes (z.B. `IsUniTemporalPivot` / `IsBiTemporalPivot` bzw. ein
   Package-Pivot). Ohne `using` muss die Relation dasselbe intern leisten.
5. **Beide Seiten:** Da `belongsToMany` auf einer Seite (oder beiden) deklariert wird, hängt es
   bisher vom related Model ab. Der Fix soll beide Seiten korrekt bedienen, ohne dass beide
   Models zwingend temporal sein müssen — massgebend ist die Pivot-Tabelle.

---

## 3. Zielbild / Requirements

- `belongsToMany`, `morphToMany`, `morphedByMany` sowie `attach`, `detach`, `sync`,
  `syncWithoutDetaching`, `updateExistingPivot`, `toggle` funktionieren korrekt für
  **uni-** und **bi-temporale** Pivots.
- Pivot-Reads und `withPivot` liefern ausschliesslich den aktuellen Zustand:
  - uni-temporal: `known_to = max`, Soft-Deleted ausgeblendet
  - bi-temporal: gültig heute ∧ bekannt jetzt, Soft-Deleted ausgeblendet
- Keine stille Historien-Korruption, kein Crash; Attach vergibt eine ID.
- Nicht-temporale Pivot-Tabellen bleiben byte-für-byte unverändert.
- Opt-in ohne Bruch bestehender Apps.

---

## 4. Design-Optionen (mit Trade-offs)

- **A (empfohlen): Model-Trait + eigene Relation-Klassen.**
  Ein Trait überschreibt `HasRelationships::newBelongsToMany()` (`Concerns/HasRelationships.php:753`)
  und `newMorphToMany()` (`:847`) und liefert `TemporalBelongsToMany` / `TemporalMorphToMany`.
  Vorteil: `belongsToMany()` funktioniert ohne API-Änderung. Nachteil: Trait muss am Model stehen.
- **B: Builder-Macro** `->temporalBelongsToMany(...)`. Explizit, aber die normale
  `belongsToMany()`-API bleibt kaputt → widerspricht „alle Laravel-Funktionen".
- **C: nur Relation-Klasse**, manuelle Instanziierung. Maximal explizit, minimaler Komfort.

Gemeinsamer Kern von A/B/C:

- `newPivotStatement()` aus der **Pivot-Tabellen-Config** bauen
  (`TemporalConnection::getUniTemporalTableConfig()` / `getBiTemporalTableConfig()`,
  `src/Connections/TemporalConnection.php:70`/`:106`) statt aus dem related Model.
- `newPivotQuery()` und den Relation-Join um die Scope-Filter ergänzen.
- `attach()` per `insertGetId()` (ID + Zeit-/Validitäts-Defaults).
- `detach()` / `updateExistingPivot()` scoped + SoftDelete/Validitätsschluss.
- Bi-temporale Sonderfälle über die bestehende Split-Logik wiederverwenden.

---

## 5. Offene Fragen — **zuerst klären**

### 5.1 API & Scope

1. Nur `BelongsToMany` + `MorphToMany` (+ inverse `morphedByMany`), oder auch
   `HasManyThrough` / `HasOneThrough`?
2. API-Form: Model-Trait (out-of-the-box) vs. Builder-Macro vs. Relation-Klasse?
   Auto-Apply oder opt-in?
3. Namensschema: `TemporalBelongsToMany`, `TemporalMorphToMany`, `TemporalPivot`,
   `HasTemporalRelations`?
4. Major-Version (Breaking) oder Minor mit opt-in?

### 5.2 Erkennung & Config

5. Müssen Pivot-Tabellen zwingend in `uni-temporal.tables` / `bi-temporal.tables` stehen
   (sonst Exception), oder Fallback auf die Defaults wie beim Model-Trait?
6. SoftDelete-Erkennung: über die Traits des `using`-Pivot-Models, über Spaltenerkennung
   (`deleted_at`), oder über ein Config-Flag pro Tabelle?
7. Wird das Default-`Pivot` automatisch durch ein Package-`TemporalPivot` (mit Trait) ersetzt,
   wenn kein `using` gesetzt ist?
8. Valid-Time-Präzision (`day`/`datetime`), Spaltennamen-Overrides und Sentinel pro Tabelle
   aus der Config ziehen?

### 5.3 Uni-temporale Semantik

9. `attach`: ID via `insertGetId()` oder Model-Pfad? Concurrency (`max+1`-Race) — ULID/UUID-Option?
10. `detach`: nur `known_to` schliessen, nur SoftDelete, oder beides?
11. `sync`: Dirty-Gate (keine Geister-Versionen bei unverändertem Sync) gewünscht?
12. `updateExistingPivot`: reine Transaktionszeit-Versionierung ok?
13. Read-Filter: in `addConstraints()` / `performJoin()`? Auto-Hydration der Temporal-Spalten
    in `withPivot`?

### 5.4 Bi-temporale Semantik

14. `attach`-Defaults: `valid_from = heute`, `valid_to = Sentinel`? Explizite
    `valid_from` / `valid_to` erlaubt?
15. `detach` = Gültigkeit schliessen (`valid_to`) vs. SoftDelete vs. beides — und wer
    entscheidet (API-Parameter)?
16. `sync`-Current-State = gültig heute ∧ bekannt jetzt ∧ nicht gelöscht? Entfernen =
    Gültigkeit schliessen oder SoftDelete?
17. `updateExistingPivot`: Transaktionszeit-Korrektur oder Valid-Time-Split (bestehende
    Bi-Temporal-Split-Logik wiederverwenden)?
18. As-of auf Relationen: `->asOf($valid, $known)`, `->validAt()`, `->knownAt()` — API-Namen und
    Verhalten bei Eager-Loading?
19. Priorität SoftDelete vs. Gültigkeit (gelöscht überschreibt gültig?).
20. Composite PK `(id, valid_to, known_to)`: Inserts müssen `valid_to`-Sentinel setzen —
    `insert()` tut das nicht.
21. Duplikate über Validität: „aktuell" eindeutig definieren; alte `(a,b)`-Versionen zulassen.

### 5.5 Cross-cutting

22. Morph: `type`-Spalte, inverse Relation, `MorphPivot`.
23. Garantie: nicht-temporale Pivot-Tabelle ohne Verhaltensänderung (Regressionstest).
24. DB-Parität: SQLite (Tests nutzen sqlite), pgsql (Prod), ggf. MySQL.
25. Benötigte Indizes für die neuen Filter (vorhanden: `(known_to, id)` bzw.
    `(known_to, valid_to, id)`, `(valid_to, known_to, id)`); Join-Performance?
26. Migrationshelfer/Command für bestehende Pivot-Tabellen (Temporal-Spalten ergänzen)?
27. Fehlermeldungen, wenn eine Tabelle als temporal konfiguriert ist, die Spalten aber fehlen.
28. Doku/Beispiele für uni- und bi-temporal.
29. Interaktion mit `wherePivot`, `orderByPivot`, `withPivotValue`, `withTimestamps`.

### 5.6 Pivot-Identität

**Teil-Entscheidung (2026-09-17):** Composite-Pivot-PKs werden in den **Pivot-Funktionen**
behandelt, nicht über den Eloquent-Model-Key (Eloquent unterstützt keine echten Composite-PKs).
Die Relation darf sich nicht auf das related Model verlassen, sondern nutzt die
Pivot-Tabellen-Config. Die `id` ist für die Many-to-Many-Helfer irrelevant.

30. Bleibt die Identität `(foreign, related)` (Laravel-Konvention, kombiniert) und „aktuell"
    wird über die Scopes definiert — oder erzwingt das Package einen Surrogat-`id`-Cluster
    (wie `partner_contact_persons` in der App)? Auswirkung auf `sync`/`detach`/`updateExistingPivot`.
31. Unterstützt das Package weiterhin Pivots **ohne** `id` (Spatie-Stil), oder wird eine
    `id`-Spalte vorausgesetzt? Falls beides: wie erkennt die Relation den Fall?
32. Composite-PK-Varianten: `(foreign, related)` vs. `(id, known_to)` vs.
    `(id, valid_to, known_to)` — welche DB-Keys unterstützt/empfiehlt das Package je Modus?
33. Soll der `Pivot`-Model-Pfad (`using`) die `id`-Erkennung aus `AsPivot`
    (`Relations/Concerns/AsPivot.php`) respektieren, oder immer den Temporal-Cluster erzwingen?
34. Wie heisst der Pivot-Trait/-Model (`IsUniTemporalPivot`, `IsBiTemporalPivot`,
    `TemporalPivot`) und wie wird er angewandt — explizit am Pivot-Model oder automatisch
    durch das Package, wenn kein `using` gesetzt ist?
35. Muss/kann der Trait auch am **declaring Model** sitzen (analog `IsUniTemporal`), damit
    `belongsToMany()` die eigene Relation-Klasse liefert — oder wird das per Builder-Macro/
    Relation-Factory gelöst?
36. Ohne `using` (Standard-`Pivot`): leistet die Relation die Temporalität intern, oder
    erzwingt das Package `->using(...)` mit einem temporalen Pivot-Model?

---

## 6. Work Breakdown (nach Beantwortung der Fragen)

1. Config-/Detektions-Layer (temporal + SoftDelete je Pivot-Tabelle).
2. `TemporalPivot`-Basis (uni/bi) inklusive ID-Vergabe.
3. `TemporalBelongsToMany` (+ `TemporalMorphToMany`):
   `newPivotStatement`, `newPivotQuery`, `addConstraints`/`performJoin`, `attach`, `detach`,
   `updateExistingPivot`.
4. Model-Trait-Hook `newBelongsToMany` / `newMorphToMany`.
5. Bi-temporale Sonderfälle (Defaults, Sync, As-of).
6. Tests, Doku, Upgrade-/Install-Hinweise.

---

## 7. Testmatrix

- uni/bi × (`using` / ohne) × (`attach`, `detach`, `sync`, `syncWithoutDetaching`,
  `updateExistingPivot`, `toggle`)
- Read und Eager-Loading nach ≥ 2 Versionen sowie nach SoftDelete
- `attach` vergibt ID; kein NOT-NULL-Crash; `sync` ohne Geister-Versionen
- `morphToMany` + `morphedByMany` (invers)
- Nicht-temporale Pivot-Tabelle unverändert
- SQLite + pgsql

---

## 8. Akzeptanzkriterien

- Alle genannten Aufrufe funktionieren ohne Crash und lassen die Historie integer.
- Reads liefern ausschliesslich den aktuellen Zustand (uni/bi), Soft-Deleted ausgeblendet.
- Keine Regression bei nicht-temporalen Pivots; bestehende Tests grün.

---

## 9. Nicht-Ziele / Risiken (Phase 1)

- Kein vollständiges As-of-/Historien-API auf Pivot-Relationen in Phase 1 — mindestens aber
  korrektes „aktuell" und Crash-Sicherheit für bi-temporal.
- `max+1`-Race bei Nebenläufigkeit (separat lösen oder dokumentieren).
- Package-Änderung nötig; konsumierende Apps behalten bis dahin den expliziten Model-Sync.
