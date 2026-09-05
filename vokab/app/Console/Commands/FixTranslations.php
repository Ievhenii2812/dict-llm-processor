<?php

namespace App\Console\Commands;

use App\Models\Example;
use App\Services\Import\ExampleTranslator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:fix-translations')]
#[Description('Fill missing example translations via the CT2 translator service, batched')]
class FixTranslations extends Command
{
    private const int BATCH_SIZE = 200;

    public function handle(): int
    {
        $this->info('Searching for examples missing translations...');

        $translator = app(ExampleTranslator::class);

        $query = Example::where(function ($q) {
            $q->whereNull('translation_en')
                ->orWhereNull('translation_ru')
                ->orWhereNull('translation_uk');
        });

        $total = $query->count();
        $this->info("Found: {$total} examples missing translations");

        if ($total === 0) {
            $this->info('All translations are already filled.');
            return Command::SUCCESS;
        }

        $processed = 0;

        $query->chunkById(self::BATCH_SIZE, function ($examples) use ($translator, &$processed) {
            $this->processBatch($examples->all(), $translator);
            $processed += count($examples);

            if ($processed % 1000 === 0) {
                $this->info("Processed: {$processed}");
            }
        });

        $this->info("Done. Processed: {$processed}");
        $this->info('Details: storage/logs/import/');

        return Command::SUCCESS;
    }

    private function processBatch(array $examples, ExampleTranslator $translator): void
    {
        // Only re-translate what's actually missing, per field,
        // but the CT2 chain (de→en→ru/uk) needs the German sentence
        // regardless of which specific field is missing, so we just
        // batch-translate everything and only overwrite null fields.
        $sentences = array_map(fn($e) => $e->sentence, $examples);
        $results   = $translator->translateAllBatch($sentences);

        foreach ($examples as $i => $example) {
            $translation = $results[$i] ?? ['en' => null, 'ru' => null, 'uk' => null];

            $updates = [];

            if ($example->translation_en === null && !empty($translation['en'])) {
                $updates['translation_en'] = $translation['en'];
            }
            if ($example->translation_ru === null && !empty($translation['ru'])) {
                $updates['translation_ru'] = $translation['ru'];
            }
            if ($example->translation_uk === null && !empty($translation['uk'])) {
                $updates['translation_uk'] = $translation['uk'];
            }

            if (!empty($updates)) {
                $example->update($updates);
            }
        }
    }
}
