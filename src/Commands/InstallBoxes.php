<?php

namespace Goldnead\StatamicOffers\Commands;

use Goldnead\StatamicOffers\Support\ContentBoxes;
use Illuminate\Console\Command;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;

/**
 * Legt die Sammlung der Inhalts-Kaesten samt Blueprint an.
 *
 * Nur anlegend: eine vorhandene Sammlung oder ein vorhandenes Blueprint bleibt
 * unberuehrt (Statamic schreibt beim Speichern die ganze Datei neu und liesse
 * Felder fallen, die jemand von Hand ergaenzt hat). `--force` ueberschreibt das
 * Blueprint. Das Blueprint kommt auf Deutsch, wenn `app.locale` Deutsch ist.
 */
class InstallBoxes extends Command
{
    protected $signature = 'offers:install-boxes {--force : Overwrite the blueprint if it exists}';

    protected $description = 'Create the content boxes collection (a small invitation under a lesson or at the end of an article) with its blueprint.';

    public function handle(): int
    {
        $handle = ContentBoxes::collection();
        $german = str_starts_with((string) config('app.locale', 'en'), 'de');

        if (Collection::find($handle)) {
            $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'exists, kept');
        } else {
            Collection::make($handle)->title($german ? 'Kästen' : 'Content boxes')->routes(null)->save();
            $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'created');
        }

        $existing = Blueprint::find("collections.{$handle}.content_box");

        if ($existing && ! $this->option('force')) {
            $this->components->twoColumnDetail('Blueprint <comment>content_box</comment>', 'exists, kept');

            return self::SUCCESS;
        }

        $file = __DIR__.'/../../resources/blueprints/content_box'.($german ? '.de' : '').'.yaml';

        Blueprint::make('content_box')
            ->setNamespace("collections.{$handle}")
            ->setContents(YAML::parse((string) file_get_contents($file)))
            ->save();

        $this->components->twoColumnDetail('Blueprint <comment>content_box</comment>', 'written');

        return self::SUCCESS;
    }
}
