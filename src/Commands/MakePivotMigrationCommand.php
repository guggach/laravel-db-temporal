<?php

namespace Guggach\LaravelDbTemporal\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakePivotMigrationCommand extends Command
{
    public $signature = 'temporal:make-pivot
        {table : The pivot table name}
        {--keys= : Comma-separated foreign/related key columns, e.g. company_id,person_id}
        {--bi-temporal : Generate a bi-temporal pivot table}
        {--soft-deletes : Include a deleted_at column}
        {--surrogate-id : Use a surrogate id instead of a composite key}';

    public $description = 'Generate a migration for a temporal pivot table';

    public function handle(): int
    {
        $table = (string) $this->argument('table');

        $keys = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('keys')),
        )));

        if ($table === '' || $keys === []) {
            $this->components->error('A table name and --keys (foreign/related columns) are required.');

            return self::FAILURE;
        }

        $content = $this->buildMigration(
            $table,
            $keys,
            (bool) $this->option('bi-temporal'),
            (bool) $this->option('soft-deletes'),
            (bool) $this->option('surrogate-id'),
        );

        $path = database_path('migrations/'.date('Y_m_d_His').'_create_'.$table.'_table.php');

        if (File::exists($path)) {
            $this->components->error("Migration already exists: {$path}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        $this->components->info("Migration created: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function buildMigration(string $table, array $keys, bool $biTemporal, bool $softDeletes, bool $surrogateId): string
    {
        $primaryTemporal = $biTemporal ? ['valid_to', 'known_to'] : ['known_from', 'known_to'];
        $uniqueTemporal = $biTemporal ? ['valid_to', 'known_to'] : ['known_to'];

        $primaryColumns = $surrogateId
            ? array_merge(['id'], $primaryTemporal)
            : array_merge($keys, $primaryTemporal);

        $uniqueColumns = array_merge($keys, $uniqueTemporal);

        $lines = [];

        if ($surrogateId) {
            $lines[] = "            \$table->unsignedBigInteger('id');";
        }

        foreach ($keys as $key) {
            $lines[] = "            \$table->unsignedBigInteger('{$key}');";
        }

        $lines[] = $biTemporal ? '            $table->bitemporal();' : '            $table->unitemporal();';

        if ($softDeletes) {
            $lines[] = '            $table->softDeletes();';
        }

        $lines[] = '            $table->timestamps();';
        $lines[] = '';
        $lines[] = '            $table->primary('.$this->arrayLiteral($primaryColumns).');';
        $lines[] = '            $table->unique('.$this->arrayLiteral($uniqueColumns).');';

        $columns = implode("\n", $lines);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
{$columns}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};

PHP;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function arrayLiteral(array $columns): string
    {
        return "['".implode("', '", $columns)."']";
    }
}
