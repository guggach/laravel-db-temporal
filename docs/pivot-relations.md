# Temporal Pivot Relations

Many-to-many relations whose pivot table is temporal. The relation keeps the
usual Laravel API (`attach`, `detach`, `sync`, `updateExistingPivot`, `toggle`,
`$model->relation`, eager loading) but versions the pivot table instead of
mutating it in place: reads return the current state only, writes create new
versions.

- Uni-temporal: `known_from` / `known_to`
- Bi-temporal: `valid_from` / `valid_to` + `known_from` / `known_to`
- Optional soft delete via `deleted_at`

The premise is that a temporal pivot behaves like a normal Laravel pivot table
from the caller's point of view. Extra rules only apply where the time axis
requires them.

## Enabling

Add `HasTemporalPivotRelations` to the model that declares the relation:

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

`belongsToMany()` and `morphToMany()` now return the temporal relation classes.
Non-temporal pivot tables keep their exact previous behaviour.

## Detection

The pivot temporal mode is resolved in this order:

1. **Marker trait on the `using` model** — `IsUniTemporalPivot` or
   `IsBiTemporalPivot` (also `IsUniTemporal` / `IsBiTemporal`). Optional
   `SoftDeletes` on the pivot model enables soft delete.
2. **Connection config** — the table is listed in
   `database.connections.temporal['uni-temporal'|'bi-temporal']['tables']`.
3. **Schema** — the pivot table has `known_from` + `known_to` (uni-temporal),
   or additionally `valid_from` + `valid_to` (bi-temporal).

Registering a pivot table in the connection config is required only when you
access it through the `DB` facade directly. For Eloquent-only usage the schema
or the marker trait is enough. Non-temporal tables are never touched.

## Uni-temporal semantics

| Method | Behaviour |
| --- | --- |
| read (`$model->relation`, eager load, `count()`) | `known_to = max` (+ `deleted_at IS NULL`) |
| `attach` | inserts a new version with `known_from = now`, `known_to = max` |
| `detach` | closes `known_to`; also sets `deleted_at` when the column exists |
| `sync` | uses the scoped current state, writes only differences (no version churn) |
| `updateExistingPivot` | closes the current version and inserts a new one |

The temporal columns are automatically selected, so they are available on the
pivot object:

```php
$company->contactPersons->first()->pivot->known_to; // '9999-12-31 23:59:59'
```

Duplicate prevention: put a database unique index on
`(foreign_key, related_key, known_to)` (both current rows share `known_to = max`,
so at most one current row per pair is possible). A duplicate `attach` then
throws a `QueryException`, just like a normal Laravel pivot.

## Bi-temporal semantics

| Method | Behaviour |
| --- | --- |
| read | `valid_from <= today <= valid_to` ∧ `known_to = max` (+ `deleted_at IS NULL`) |
| `attach` | `valid_from = today`, `valid_to = sentinel`, `known_from = now`, `known_to = max`; explicit `valid_from`/`valid_to` are respected |
| `detach` | closes the validity: new version with `valid_to` on the last valid boundary (yesterday / now−1s), no right remainder |
| `sync` | current state = valid today ∧ known now ∧ not deleted; removal closes validity |
| `updateExistingPivot` | uses the existing valid-time split logic when `valid_from`/`valid_to` change, otherwise pure transaction-time versioning |

A `valid_from` in the future is stored but not returned by the current read
(the filter is inclusive). It can be read with `validAsOf()` / `asOf()`.

### As-of reads

```php
// valid at a business date, as known now
$company->contactPersons()->validAsOf('2024-06-01')->get();

// valid at a business date, as known at a given point in time
$company->contactPersons()->asOf('2024-06-01', '2024-12-31')->get();

// state at a knowledge date, without a validity filter
$company->contactPersons()->knownAsOf('2024-12-31')->get();
```

> Note: `asOf()` rebuilds the relation query from the base query captured at
> relation construction. Additional constraints defined in the relation method
> (e.g. `->where('active', 1)`) are not carried over.

## Pivot models (`->using()`)

A custom pivot model can mark the table and expose attributes/casts:

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

// relation
return $this->belongsToMany(Person::class, 'company_contact_persons')
    ->using(ContactPersonPivot::class);
```

With `->using()`, `SoftDeletes` on the pivot model enables soft delete even if
the table is not detected as such. Attribute casts are applied on `attach` and
`updateExistingPivot`. The actual versioning is always done by the relation —
do not rely on `$pivot->save()`/`$pivot->delete()` for versioning.

## Pivot table requirements

- Temporal columns, defaults `known_from`/`known_to` (and `valid_from`/`valid_to`
  for bi-temporal).
- Either a composite primary key `(foreign, related, <to>[, known_to])` (no
  surrogate id) or a surrogate `id` plus temporal columns. With a surrogate id
  the relation assigns the next id on `attach`.
- A unique index on `(foreign, related, known_to)` for uni-temporal, or
  `(foreign, related, valid_to, known_to)` for bi-temporal, to prevent duplicate
  current rows.
- Optional `deleted_at` for soft delete.

Example migration (uni-temporal, composite key):

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

Example migration (bi-temporal, surrogate id):

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

## API reference

| Method | Description |
| --- | --- |
| `attach($ids, $attributes = [])` | `$ids` is the related side; the foreign side comes from the parent. Additional columns go in `$attributes`. |
| `detach($ids = null)` | Removes/closes the current link(s). `null` removes all of the parent's links. |
| `sync($ids)` | Keeps only the given related ids as current; diffs only. |
| `syncWithoutDetaching($ids)` | Adds missing links without removing existing ones. |
| `updateExistingPivot($id, $attributes)` | Versions the pivot row of the given related id. |
| `toggle($ids)` | Attaches detached ids and detaches attached ones. |
| `validAsOf($valid)` | Bi-temporal: valid state at `$valid`, as known now. |
| `asOf($valid, $known = null)` | Bi-temporal: state at `$valid`/`$known`. |
| `knownAsOf($known)` | Bi-temporal: state at the knowledge date, without validity filter. |

## Notes and limitations

- `detach` on a bi-temporal pivot closes the validity; a dedicated soft-delete
  variant (`forceDetach`) is not provided yet.
- `asOf()` does not carry over extra constraints from the relation definition.
- Ids are generated with `max(id) + 1`; for high concurrency consider unique
  ids or a dedicated sequence.
- `attach` writes through the query builder (with pivot casts applied); the
  pivot model's `save()` is not used.
- Bi-temporal pivots make sense mainly when extra business-time attributes
  require it. For complex cases a dedicated entity table with a `hasMany`
  relation is often simpler than a bi-temporal pivot.
