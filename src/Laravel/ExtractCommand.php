<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Laravel;

use Fhulufhelo\Rapaport\Exception\RapaportException;
use Fhulufhelo\Rapaport\RapaportParser;
use Illuminate\Console\Command;

class ExtractCommand extends Command
{
    protected $signature = 'rapaport:extract
        {pdf : Path to the Rapaport price list PDF}
        {--o|out= : Write here instead of stdout}
        {--format=csv : csv or json}';

    protected $description = 'Extract the price grids from a Rapaport Diamond Report PDF';

    public function handle(RapaportParser $parser): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['csv', 'json'], true)) {
            $this->error('Format must be csv or json.');

            return self::INVALID;
        }

        try {
            $list = $parser->parse($this->argument('pdf'));
        } catch (RapaportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $out = $this->option('out');
        $body = $format === 'json'
            ? $list->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $list->toCsv();

        if ($out === null) {
            $this->line($body);
        } else {
            file_put_contents($out, $body);
        }

        foreach ($list->issues() as $where => $problems) {
            foreach ($problems as $problem) {
                $this->warn("{$where}: {$problem}");
            }
        }

        $this->components->info(sprintf(
            '%d grids, %d prices%s',
            count($list),
            count($list->prices()),
            $out === null ? '' : ' -> '.$out
        ));

        return self::SUCCESS;
    }
}
