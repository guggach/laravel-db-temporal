<?php

namespace Guggach\LaravelDbTemporal\Configuration;

use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;

/**
 * @phpstan-type TemporalConfigShape array{
 *     column_from?: string,
 *     column_to?: string,
 *     max_timestamp?: string,
 * }
 *
 * @phpstan-type TemporalTablesShape array<string, TemporalConfigShape>
 *
 * @phpstan-type TemporalDefaultsShape array{
 *     column_from?: string,
 *     column_to?: string,
 *     max_timestamp?: string,
 * }
 *
 * @phpstan-type TemporalConnectionConfigShape array{
 *     base?: string,
 *     driver?: string,
 *     uni-temporal?: array{
 *         defaults?: TemporalDefaultsShape,
 *         tables?: TemporalTablesShape,
 *     },
 *     bi-temporal?: array<string, mixed>,
 * }
 */
final readonly class TemporalConfig
{
    public function __construct(
        public string $columnFrom,
        public string $columnTo,
        public string $maxTimestamp,
    ) {}

    /**
     * @param  TemporalConfigShape|null  $data
     */
    public static function fromArray(?array $data): self
    {
        return new self(
            columnFrom: self::stringValue(($data['column_from'] ?? null), 'known_from'),
            columnTo: self::stringValue(($data['column_to'] ?? null), 'known_to'),
            maxTimestamp: self::stringValue(($data['max_timestamp'] ?? null), '9999-12-31 23:59:59'),
        );
    }

    public function applyToBuilder(UniTemporalBuilder $builder, bool $calledByEloquent = false): void
    {
        $builder->setTemporalColumnNames($this->columnFrom, $this->columnTo, $this->maxTimestamp, $calledByEloquent);
    }

    public function withOverrides(?string $columnFrom, ?string $columnTo, ?string $maxTimestamp): self
    {
        return new self(
            columnFrom: $columnFrom ?? $this->columnFrom,
            columnTo: $columnTo ?? $this->columnTo,
            maxTimestamp: $maxTimestamp ?? $this->maxTimestamp,
        );
    }

    private static function stringValue(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }
}
