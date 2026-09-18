# Entscheidungsvorlage: Pivot-Relationen für temporale Tabellen

**Paket:** `guggach/laravel-db-temporal`
**Datum:** 2026-09-17
**Status:** Entschieden (2026-09-17) — API eingefroren, Grundlage für die Implementierung
**Grundlage:** `docs/temp/pivot-relations-ticket.md`
**Prämisse:** Wenn möglich soll sich das Verhalten **wie in einem normalen Laravel-Datenmodell**
anfühlen. Sonderregeln nur, wo die Zeitachse es zwingend erfordert.

Legende: **E** = Empfehlung, **A** = Auswirkung/Risiko. „Offen" markiert Punkte, die eine echte
Abwägung sind und nicht nur eine technische Wahl.

## Finale Entscheide (Abweichungen/Ergänzungen zu den Empfehlungen)

- **#5 (Config optional):** Registrierung in `uni-/bi-temporal.tables` ist **nicht Pflicht**. Sie ist
  nur für den direkten `DB::`/Proxy-Pfad nötig (wie dokumentiert). Für Eloquent-only genügt es,
  dass die erwarteten Spalten existieren.
- **#6 (SoftDelete):** Offizieller Weg: `->using(Pivot::class)` mit `SoftDeletes`-Trait. Zusätzlich
  erkennt das Package `deleted_at` als Short-Cut.
- **#9 (Duplikat-Schutz):** Ein doppelter **aktueller** Eintrag `(fk1, fk2)` wird über einen
  **DB-Unique inkl. `known_to`** verhindert — `UNIQUE (fk1, fk2, known_to)` bzw.
  `UNIQUE (fk1, fk2, valid_to, known_to)`. `attach` wirft dann wie ein normales Laravel-Pivot
  (`QueryException`); kein App-Check, kein stilles Überspringen. Der Migrations-Helfer legt den
  Unique-Index mit an.
- **#15 (bi-temporal detach):** Default schliesst die Gültigkeit (`valid_to = heute`) + TT-Version;
  separater Weg (`forceDetach`/Flag) für „falsch erfasst" = reines SoftDelete.
- **#18 (As-of):** `asOf` / `validAsOf` / `knownAsOf` werden in Phase 2 implementiert.
- **Bi-temporal §4 (Use Cases):** Doku ergänzen und Use Cases prüfen. Empfehlung: Werden zusätzliche
  Attribute mit Business-Zeit-Kontext benötigt, ist eine **eigene Entitätstabelle (hasMany)**
  einem bi-temporalen Pivot vorzuziehen. Bi-temporale Pivots bleiben trotzdem crash-sicher und
  vollständig unterstützt.
- **#28 (Doku):** Abschnitt „Temporal Pivots" in `docs/` **plus** Hinweis in der `README`.

### Klärungen (Rückfragen beantwortet)

- **Pivot-Erkennung:** Offizieller Weg ist `->using(TemporalPivot)` mit Trait. Ohne `using`
  erkennt die Relation `known_from`/`known_to` (bzw. `valid_*`) automatisch per Schema und nutzt
  das Package-`TemporalPivot`.
- **Duplikat-Schutz:** DB-Unique inkl. `known_to` (siehe #9).
- **Phase 2 Umfang:** As-of vollständig + Doku-Empfehlung zur eigenen Entitätstabelle.
- **`attach`/`detach` `$ids`:** immer die **Related-Seite** (eine Keyspalte, z.B. `target_id`); die
  foreign-Seite (z.B. `owner_id`) kommt automatisch aus dem Parent-Model. Eine Composite-Identität
  `(foreign, related)` wird damit über Parent + `$ids` gebildet. Zusätzliche Pivot-Spalten gehören
  in `$attributes`; eine related-seitige Composite-ID unterstützt Eloquent nicht.

---

## 1. API & Scope

| # | Frage | Empfehlung (E) | Auswirkung (A) |
| --- | --- | --- | --- |
| 1 | Welche Relations? | `BelongsToMany`, `MorphToMany`, inverse `morphedByMany`. Durch-Through-Relations nicht (keine Pivot-Tabelle). | Vollständig für alle Pivot-Fälle; kein Zusatzaufwand. |
| 2 | API-Form | Model-Trait `HasTemporalPivotRelations` überschreibt `newBelongsToMany`/`newMorphToMany` → `belongsToMany()` funktioniert unverändert. | Erfüllt die Prämisse am besten. Opt-in pro Model. |
| 3 | Namensschema | `HasTemporalPivotRelations` (Model), `TemporalBelongsToMany`, `TemporalMorphToMany`, Pivot-Trait `IsUniTemporalPivot`/`IsBiTemporalPivot`, Package-`TemporalPivot`. | Klar, konsistent mit bestehenden `IsUni/BiTemporal`. |
| 4 | Versionierung des Pakets | Vor 1.0 als Feature/Minor; API danach stabil halten. | Kein Bruch für Bestandsnutzer (nicht-temporale Pivots unverändert). |

Einverstanden, so umsetzen.

## 2. Erkennung & Config

| # | Frage | Empfehlung (E) | Auswirkung (A) | Entscheidung oder Rückfrage |
| --- | --- | --- | --- | --- |
| 5 | Registrierung Pflicht? | Ja: Pivot-Tabellen in `uni-/bi-temporal.tables`. Nicht registriert = Standard (unverändert). Ist die Tabelle registriert, aber Spalten fehlen → klare Exception. | Fail-fast statt stiller Fehler. | Bis jetzt war eine Config Registrierung nur notwendig, wenn man die DB:: Funktionen direkt nutzen möchte. (ist so dokumentiert). Wenn jemand nur mit Eloquent arbeitet, ist die Registrierung optional, solange die Pivot-Tabelle die erwarteten Spalten hat. |
| 6 | SoftDelete-Erkennung | Über `using`-Pivot-Model (`class_uses_recursive`) **oder** vorhandene `deleted_at`-Spalte; pro Tabelle überschreibbar. | Deckt `using`- und Standard-Pfad ab. | Der offizielle Weg wär über die Class "Pivot" und dem Trait `SoftDeletes` und using in der Relation. Über `deleted_at`wäre ein ein eleganter Short-Cut. |
| 7 | Default-Pivot-Model | Package-`TemporalPivot` automatisch, wenn kein `using` gesetzt ist. | Verhalten identisch mit/ohne `using`. | OK |
| 8 | Spalten/Präzision/Sentinels | Aus der Config (`uni/bi-temporal.defaults` + Tabellen-Override), wie bei Models. | Konsistent; keine hardcodierten Namen. | OK |



## 3. Uni-temporale Semantik

| # | Frage | Empfehlung (E) | Auswirkung (A) | Entscheidung oder Rückfrage |
| --- | --- | --- | --- | --- |
| 9 | `attach` ID | Pro Record einfügen. Existiert genau **ein** Surrogat-Key → `insertGetId`; sonst reiner `insert` (Composite-PK). Vom User gesetzte PK-Spalten nie überschreiben. | Deckt Spatie-Stil (kein id) und id-Cluster ab. | OK, aber es darf auf der Composite nicht zu einer Duplette kommen. Das ist der Grund, warum Laravel per Standard dort auf eine Id verzichtet. |
| 10 | `detach` | `known_to` schliessen; wenn `deleted_at` vorhanden zusätzlich SoftDelete-Version (wie Model-`delete()`). | Entspricht Laravels `delete()`-Semantik inkl. Scopes. | OK |
| 11 | `sync` | Current-State über **gescopete** Query ermitteln; nur Diffs schreiben (Dirty-Gate), keine Geister-Versionen. | Weniger Versionen; gleiches Endergebnis wie Laravel. | OK |
| 12 | `updateExistingPivot` | Versionierung über den Temporal-Builder (eine neue TT-Version). | Wie Model-Update. | OK |
| 13 | Read-Filter / `withPivot` | Filter (`known_to=max`, `deleted_at IS NULL`) in `newPivotQuery()` **und** Relation-Join; Temporal-Spalten automatisch in `pivotColumns` aufnehmen. | `$model->relation` und `->pivot` verhalten sich normal. | OK |

## 4. Bi-temporale Semantik

| # | Frage | Empfehlung (E) | Auswirkung (A) | Entscheidung oder Rückfrage |
| --- | --- | --- | --- | --- |
| 14 | `attach`-Defaults | `valid_from = heute`, `valid_to = Sentinel`; explizite Werte via Pivot-Attribute erlaubt. | Sinnvoller Standard, überschreibbar. | OK |
| 15 | `detach` | **Offen.** Vorschlag: Default schliesst die Gültigkeit (`valid_to = heute`) + TT-Version; separater Weg (`forceDetach`/Flag) für „falsch erfasst" = reines SoftDelete. | Fachliche vs. Korrektur-Semantik muss bewusst gewählt werden. | OK |
| 16 | `sync`-Current-State | Gültig heute ∧ bekannt jetzt ∧ nicht gelöscht; Entfernen = Gültigkeit schliessen. | Eindeutiges „aktuell". | OK |
| 17 | `updateExistingPivot` | VT-Split-Logik der Models wiederverwenden (wie `PartnerAddress`). | Korrekte Historien bei Gültigkeitsänderung. | OK |
| 18 | As-of-API | `->asOf($valid, $known)`, `->validAsOf($valid)`, `->knownAsOf()`. Eager-Loading = aktueller Zustand. | Mächtig, aber als Phase-2-Teil planbar. | OK |
| 19 | SoftDelete vs. Gültigkeit | Gelöscht ist unsichtbar, unabhängig von der Gültigkeit. | Eindeutige Priorität. | OK |
| 20 | Inserts | `valid_from`/`valid_to`/`known_*` über den Builder-Default setzen; Composite-PK `(id, valid_to, known_to)` respektieren. | Kein NOT-NULL-Crash. | OK |
| 21 | „Aktuell" bei Duplikaten | Aktuell = TT-offene Zeile(n); VT-Überlappungen zulässig, `sync` matcht über aktuelle Gültigkeit. | Klare, testbare Definition. | OK |

Bei diesem Block stelle ich mir Frage, was der Use Case sein könnte, um Many to Many Beziehungen bi-temporal zu gestalten. Das würde heissen, dass man die Beziehung rückwirkend oder in der Zukunft ändern kann, während gleichzeitig die Kenntnis über diese Änderungen ebenfalls zeitlich verfolgt wird. Einzig, wenn weitere Attribute die einen Business zeitlichen Kontext abbilden (wie bei der Adresse) dies notwendig machen, ergibt ein bi-temporales Pivot Sinn. Aber da müsste man sich überlegen, ob eine weitere Tabelle nicht empfohlen ist, um die Komplexität zu reduzieren. --> Dokumentieren und Use Cases prüfen.

## 5. Cross-cutting

| # | Frage | Empfehlung (E) | Auswirkung (A) | Entscheidung oder Rückfrage |
| --- | --- | --- | --- |
| 22 | Morph | `TemporalMorphToMany` + inverse `morphedByMany`, `MorphPivot`-Basis. | Vollständige Abdeckung. | OK |
| 23 | Nicht-temporale Pivots | Unverändert; Regressionstest. | Kein Bruch. | OK |
| 24 | DB-Parität | SQLite (Tests) + pgsql (Prod) verpflichtend; MySQL best effort. | CI-Matrix. | OK |
| 25 | Indizes | Uni `(known_to, pk)`, bi `(known_to, valid_to, pk)` + `(valid_to, known_to, pk)`; im Ticket dokumentiert. | Performance der Filter/Joins. | OK |
| 26 | Migrationshelfer | Command/Stub zum Ergänzen der Temporal-Spalten bei bestehenden Pivot-Tabellen. | Einfacher Umstieg. | OK |
| 27 | Fehlerfälle | Klare Exceptions bei fehlenden Spalten/Config. | Debuggbarkeit. | OK |
| 28 | Doku | Abschnitt „Temporal Pivots" (uni/bi, attach/detach/sync, as-of) in `docs/`. | Nutzbarkeit. | OK und Hinweis in readme |
| 29 | `wherePivot` etc. | Mitfiltern/ordnen weiter möglich; `withTimestamps` bleibt, ist aber unabhängig von `known_*`. | Dokumentieren. | OK |

---

## Empfohlene Zielarchitektur (bestätigt in Grundzügen)

```
src/
  Eloquent/
    HasTemporalPivotRelations.php      # überschreibt newBelongsToMany / newMorphToMany
    IsUniTemporalPivot.php             # Pivot-Trait (nutzt IsUniTemporal-Helfer)
    IsBiTemporalPivot.php
  Relations/
    TemporalBelongsToMany.php          # extends BelongsToMany
    TemporalMorphToMany.php            # extends MorphToMany
  Database/Query/
    (bestehende Uni-/BiTemporalBuilder wiederverwenden)
```

Kern der Relation:

- `newPivotStatement()`: Builder aus der **Pivot-Tabellen-Config** (nicht related Model).
- `newPivotQuery()` / `addConstraints()` / `performJoin()`: Scope-Filter (`known_to`, Gültigkeit,
  `deleted_at`).
- `attach()`: pro Record einfügen (siehe 9/20), `detach()`/`updateExistingPivot()`: scoped +
  Version (siehe 10/15/17), `sync()`: gescopeter Current-State (siehe 11/16).

## Vorgehen

1. Diese Vorlage durchgehen, offene Punkte (v.a. 15, 18) entscheiden.
2. API einfrieren, Implementierung Phase 1 (uni) → Phase 2 (bi) → Phase 3 (Morph/Doku).
3. Erst danach im `apartment-manager` weiterfahren und die Sonder-Syncs ablösen.

Sehr gut, so machen.

---

## Umsetzungsstand

**Phase 1 (uni-temporal) — umgesetzt (2026-09-17)**

Neue Dateien:

- `src/Eloquent/HasTemporalPivotRelations.php` — Model-Trait; `belongsToMany()`/`morphToMany()`
  liefern temporale Relation-Klassen.
- `src/Relations/TemporalBelongsToMany.php`, `src/Relations/TemporalMorphToMany.php`.
- `src/Relations/Concerns/InteractsWithTemporalPivot.php` — Erkennung, Filter, attach/detach/
  updateExistingPivot.
- `src/Eloquent/IsUniTemporalPivot.php`, `src/Eloquent/IsBiTemporalPivot.php` — Marker-Traits.

Verhalten (uni-temporal):

- Reads (`$model->relation`, Eager-Loading) filtern auf `known_to = max` (+ `deleted_at IS NULL`);
  temporale Spalten sind über `->pivot` verfügbar.
- `attach` fügt eine Version ein, vergibt bei Surrogat-`id` die nächste ID (`insertGetId`), sonst
  reiner Insert (Composite-PK). Duplikate wirft die DB (Unique inkl. `known_to`).
- `detach` schliesst `known_to` (und setzt SoftDelete, wenn `deleted_at` existiert).
- `sync` nutzt den gescopeten Current-State (kein Versions-Churn bei unverändertem Sync).
- `updateExistingPivot` versioniert.
- Erkennung: `using`-Marker → Connection-Config → Schema. Nicht-temporale Pivots unverändert.

Tests (alle grün): `tests/Feature/TemporalPivotUniTest.php` (7),
`tests/Feature/TemporalPivotCompatibilityTest.php` (2). Gesamtsuite 72 passed, phpstan max ohne
Fehler, Pint sauber.

**Phase 2 (bi-temporal) — umgesetzt (2026-09-18)**

- Reads filtern `valid_from <= heute <= valid_to` ∧ `known_to = max` ∧ `deleted_at IS NULL`.
- `attach` ohne Attribute setzt `valid_from = heute`, `valid_to = Sentinel` → aktuell. Explizite
  Werte sind erlaubt: `valid_from` in der Vergangenheit/heute ist aktuell; ein **zukünftiges**
  `valid_from` wird vom Current-Read (inklusiver Filter `valid_from <= heute`) nicht geliefert,
  ist aber via `validAsOf()`/`asOf()` mit passendem Stichtag lesbar.
- `detach` schliesst die Gültigkeit über `BiTemporalBuilder::closeValidityAt()`: TT-Version
  terminieren + neue Version mit `valid_to` auf der **letzten gültigen Grenze** — **Präzisierung zu
  #15:** nicht `heute`, sondern `gestern` (bzw. jetzt−1s), weil der Read-Filter inklusiv ist und
  `valid_to = heute` den Link heute noch anzeigen würde.
- `sync` arbeitet über den gescopten Current-State (`gültig ∧ bekannt ∧ nicht gelöscht`); Entfernen
  schliesst die Gültigkeit.
- `updateExistingPivot` mit `valid_from`/`valid_to` nutzt die bestehende VT-Split-Logik, sonst TT.
- `asOf($valid, $known)`, `validAsOf($valid)`, `knownAsOf($known)` über eine gesicherte Basis-Query.
- Tests: `tests/Feature/TemporalPivotBiTest.php` (6).

**Offen / Einschränkungen**

- `forceDetach` (reines SoftDelete statt Gültigkeitsschluss) noch nicht als eigene API.
- `asOf()` baut auf der bei der Relationserzeugung gesicherten Basis-Query auf — zusätzliche
  Constraints aus der Relation-Definition (z.B. `->where('active', 1)`) werden dabei nicht
  übernommen. Im Doku-Abschnitt vermerken.
- Doku-Empfehlung zur eigenen Entitätstabelle bei komplexer Business-Zeit (Use Cases prüfen).
- **Phase 3:** Morph-Härtung, Migrations-Helfer (Temporal-Spalten + Unique inkl. `known_to`),
  Doku „Temporal Pivots" + README-Hinweis.
- `attach` mit `using` läuft direkt über den Builder (Casts via `castAttributes`), nicht über
  `$pivot->save()` — im Doku-Abschnitt vermerken.