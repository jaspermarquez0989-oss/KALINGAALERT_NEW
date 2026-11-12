<?php

namespace App\Console\Commands;

use App\Services\PagasaWarningImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pagasa:sync-warnings')]
#[Description('Import active PAGASA warnings covering Bongabong into source records')]
class SyncPagasaWarnings extends Command
{
    public function handle(PagasaWarningImporter $importer): int
    {
        $importedCount = $importer->sync();

        $this->components->info("Imported {$importedCount} new PAGASA warning record(s).");

        return self::SUCCESS;
    }
}
