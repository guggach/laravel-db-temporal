<?php

namespace Guggach\LaravelDbTemporal\Configuration;

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
final readonly class BiTemporalConfig
{
    public function __construct(
        public string $columnValidFrom,
        public string $columnValidTo,
        public string $columnKnownFrom,
        public string $columnKnownTo,
        public string $vtPrecision,
        public string $maxDate,
        public string $maxTimestamp,
    ) {}

    public function vtMaxSentinel(): string
    {
        // Use midnight datetime format so all VT values share the same 'Y-m-d H:i:s' storage format.
        // In MySQL (DATE column) the time is truncated on write and ignored on read.
        return $this->vtPrecision === 'datetime' ? $this->maxTimestamp : $this->maxDate.' 00:00:00';
    }

    /**
     * @param  BiTemporalConfigShape|null  $data
     */
    public static function fromArray(?array $data): self
    {
        return new self(
            columnValidFrom: self::stringValue($data['column_valid_from'] ?? null, 'valid_from'),
            columnValidTo: self::stringValue($data['column_valid_to'] ?? null, 'valid_to'),
            columnKnownFrom: self::stringValue($data['column_known_from'] ?? null, 'known_from'),
            columnKnownTo: self::stringValue($data['column_known_to'] ?? null, 'known_to'),
            vtPrecision: self::vtPrecisionValue($data['vt_precision'] ?? null),
            maxDate: self::stringValue($data['max_date'] ?? null, '9999-12-31'),
            maxTimestamp: self::stringValue($data['max_timestamp'] ?? null, '9999-12-31 23:59:59'),
        );
    }

    public function withOverrides(
        ?string $columnValidFrom,
        ?string $columnValidTo,
        ?string $columnKnownFrom,
        ?string $columnKnownTo,
        ?string $vtPrecision,
        ?string $maxDate,
        ?string $maxTimestamp,
    ): self {
        return new self(
            columnValidFrom: $columnValidFrom ?? $this->columnValidFrom,
            columnValidTo: $columnValidTo ?? $this->columnValidTo,
            columnKnownFrom: $columnKnownFrom ?? $this->columnKnownFrom,
            columnKnownTo: $columnKnownTo ?? $this->columnKnownTo,
            vtPrecision: $vtPrecision ?? $this->vtPrecision,
            maxDate: $maxDate ?? $this->maxDate,
            maxTimestamp: $maxTimestamp ?? $this->maxTimestamp,
        );
    }

    private static function stringValue(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function vtPrecisionValue(mixed $value): string
    {
        return $value === 'datetime' ? 'datetime' : 'day';
    }
}
