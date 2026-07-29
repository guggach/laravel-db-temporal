<?php

namespace Guggach\LaravelDbTemporal\Commands;

use Illuminate\Console\Command;

class InstallTemporalCommand extends Command
{
    public $signature = 'temporal:install {--path= : Path to config/database.php}';

    public $description = 'Install the temporal-proxy connection into config/database.php';

    public function handle(): int
    {
        $path = $this->option('path') ?? config_path('database.php');

        if (! file_exists($path)) {
            $this->components->error('config/database.php not found.');

            return self::FAILURE;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            $this->components->error('Unable to read config/database.php.');

            return self::FAILURE;
        }

        if (str_contains($content, "'temporal' => [")) {
            $this->components->warn('The temporal connection already exists in config/database.php.');

            return self::SUCCESS;
        }

        $base = $this->extractDefaultConnection($content);

        if ($base === null) {
            $this->components->error('Could not determine the default database connection.');

            return self::FAILURE;
        }

        $stub = file_get_contents(__DIR__.'/../../stubs/config/database-temporal.stub');

        if ($stub === false) {
            $this->components->error('Unable to read temporal stub file.');

            return self::FAILURE;
        }

        $stub = str_replace('${BASE_CONNECTION}', $base, $stub);

        $content = $this->updateDefault($content, $base, 'temporal');
        $content = $this->addConnection($content, $stub);

        file_put_contents($path, $content);

        $this->components->info(sprintf(
            'Temporal connection installed. Default connection set to "temporal" (base: "%s").',
            $base
        ));

        return self::SUCCESS;
    }

    private function extractDefaultConnection(string $content): ?string
    {
        if (preg_match("/'default'\s*=>\s*env\s*\(\s*'DB_CONNECTION'\s*,\s*'([^']+)'\s*\)/", $content, $matches)) {
            return $matches[1];
        }

        if (preg_match("/'default'\s*=>\s*'([^']+)'/", $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function updateDefault(string $content, string $oldDefault, string $newDefault): string
    {
        $pattern = "/('default'\s*=>\s*env\s*\(\s*'DB_CONNECTION'\s*,\s*)'[^']*'(\s*\)\s*,)/";

        return preg_replace($pattern, "$1'{$newDefault}'$2", $content) ?? $content;
    }

    private function addConnection(string $content, string $connectionBlock): string
    {
        $pos = strpos($content, "'connections' => [");

        if ($pos === false) {
            $this->components->error("Could not find 'connections' array in config/database.php.");

            return $content;
        }

        $cursor = $pos + strlen("'connections' => [");
        $length = strlen($content);
        $depth = 1;

        while ($cursor < $length && $depth > 0) {
            $char = $content[$cursor];

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
            }

            $cursor++;
        }

        $insertPos = $cursor - 1;

        $before = substr($content, 0, $insertPos);
        $after = substr($content, $insertPos);

        $indent = $this->detectInnerIndent($content, $pos);

        $indentedBlock = '';
        foreach (explode("\n", $connectionBlock) as $line) {
            $indentedBlock .= $indent.$line."\n";
        }

        return $before."\n".$indentedBlock.$after;
    }

    private function detectInnerIndent(string $content, int $connectionsPos): string
    {
        $searchStart = $connectionsPos + strlen("'connections' => [");
        $snippet = substr($content, $searchStart, 200);

        if (preg_match('/\n(\s+)\S/', $snippet, $matches)) {
            return $matches[1];
        }

        return '        ';
    }
}
