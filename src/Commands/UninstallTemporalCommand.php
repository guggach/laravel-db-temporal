<?php

namespace Guggach\LaravelDbTemporal\Commands;

use Illuminate\Console\Command;

class UninstallTemporalCommand extends Command
{
    public $signature = 'temporal:uninstall {--path= : Path to config/database.php}';

    public $description = 'Remove the temporal-proxy connection from config/database.php and restore the default';

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

        if (! str_contains($content, "'temporal' => [")) {
            $this->components->warn('No temporal connection found in config/database.php.');

            return self::SUCCESS;
        }

        $base = $this->extractBaseConnection($content);

        if ($base === null) {
            $this->components->error('Could not extract base connection from temporal config.');

            return self::FAILURE;
        }

        $content = $this->removeTemporalBlock($content);
        $content = $this->updateDefault($content, 'temporal', $base);

        file_put_contents($path, $content);

        $this->components->info(sprintf(
            'Temporal connection removed. Default connection restored to "%s".',
            $base
        ));

        return self::SUCCESS;
    }

    private function extractBaseConnection(string $content): ?string
    {
        $temporalPos = strpos($content, "'temporal' => [");

        if ($temporalPos === false) {
            return null;
        }

        $snippet = substr($content, $temporalPos, 500);

        if (preg_match("/'base'\s*=>\s*'([^']+)'/", $snippet, $matches)) {
            return $matches[1];
        }

        if (preg_match("/'base'\s*=>\s*env\s*\(\s*'[^']+'\s*,\s*'([^']+)'\s*\)/", $snippet, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function removeTemporalBlock(string $content): string
    {
        $temporalPos = strpos($content, "'temporal' => [");

        if ($temporalPos === false) {
            return $content;
        }

        $cursor = $temporalPos + strlen("'temporal' => [");
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

        $lineStart = strrpos(substr($content, 0, $temporalPos), "\n") ?: 0;
        $lineStart = $lineStart === 0 ? 0 : $lineStart + 1;

        $beforeLine = substr($content, 0, $lineStart);
        $after = substr($content, $cursor);

        if (str_starts_with(ltrim($after), ',')) {
            $commaPos = strpos($after, ',');
            $after = substr($after, $commaPos + 1);
        } elseif (str_starts_with(ltrim($after), "\n")) {
            $after = ltrim($after, "\n\r ");
            if (str_starts_with($after, ',')) {
                $after = substr($after, 1);
            }
            $after = "\n".$after;
        }

        return $beforeLine.$after;
    }

    private function updateDefault(string $content, string $oldDefault, string $newDefault): string
    {
        $pattern = "/('default'\s*=>\s*env\s*\(\s*'DB_CONNECTION'\s*,\s*)'[^']*'(\s*\)\s*,)/";

        return preg_replace($pattern, "$1'{$newDefault}'$2", $content) ?? $content;
    }
}
