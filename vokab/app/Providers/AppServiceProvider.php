<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Import Services
use App\Services\Import\CategorizationService;
use App\Services\Import\CleanupService;
use App\Services\Import\CrosslinkService;
use App\Services\Import\ExampleFillerService;
use App\Services\Import\ExampleTranslator;
use App\Services\Import\GarbageCleanupService;
use App\Services\Import\ImportOrchestrator;
use App\Services\Import\PhraseTranslatorService;
use App\Services\Import\SpacyService;
use App\Services\Import\TatoebaParser;
use App\Services\Import\VertexAIService;
use App\Services\Import\WiktionaryDumpParser;
use App\Services\Import\WordSaver;
use App\Services\Import\CefrAssignmentService;
use App\Services\Import\HomonymTranslatorService;
use App\Services\Import\GeminiAIStudioService;
use App\Services\Import\WordSemanticTranslatorService;
use App\Services\Import\LeipzigSentencesParser;
use App\Services\Import\WordTranslationGapFillerService;
use App\Services\Import\CompoundSplitterService;
use App\Services\Import\Contracts\AIProviderInterface;
use App\Services\Import\MistralService;
use App\Services\Import\GroqService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Singletons without dependencies ──────────────────────────────────
        $this->app->singleton(SpacyService::class);
        $this->app->singleton(WiktionaryDumpParser::class);
        $this->app->singleton(WordSaver::class);
        $this->app->singleton(VertexAIService::class);
        $this->app->singleton(TatoebaParser::class);
        $this->app->singleton(ExampleTranslator::class);

        // ── Import pipeline ───────────────────────────────────────────────────
        $this->app->singleton(ImportOrchestrator::class, function ($app) {
            return new ImportOrchestrator(
                hunspell: $app->make(SpacyService::class),
                wiktionary: $app->make(WiktionaryDumpParser::class),
                saver: $app->make(WordSaver::class),
                filePath: config('import.leipzig_file'),
                sessionLimit: 25,
            );
        });

        // ── Post-import cleanup ───────────────────────────────────────────────
        $this->app->singleton(CleanupService::class, function ($app) {
            return new CleanupService(
                spacy: $app->make(SpacyService::class),
                vertex: $app->make(VertexAIService::class),
            );
        });

        $this->app->singleton(GarbageCleanupService::class, function ($app) {
            return new GarbageCleanupService(
                vertex: $app->make(VertexAIService::class),
            );
        });

        $this->app->singleton(CategorizationService::class, function ($app) {
            return new CategorizationService(
                vertex: $app->make(VertexAIService::class),
            );
        });

        $this->app->singleton(CefrAssignmentService::class, function ($app) {
            return new CefrAssignmentService($app->make(VertexAIService::class));
        });

        // ── Stage 2: translation and enrichment ───────────────────────────────
        $this->app->singleton(CrosslinkService::class, function ($app) {
            return new CrosslinkService(
                hunspell: $app->make(SpacyService::class),
            );
        });

        $this->app->singleton(ExampleFillerService::class, function ($app) {
            return new ExampleFillerService(
                tatoeba: $app->make(TatoebaParser::class),
                leipzig: $app->make(LeipzigSentencesParser::class),
                translator: $app->make(ExampleTranslator::class),
                saver: $app->make(WordSaver::class),
                vertex: $app->make(VertexAIService::class),
            );
        });

        $this->app->singleton(AIProviderInterface::class, function ($app) {
            return match (config('import.ai_provider', 'gemini')) {
                'mistral' => $app->make(MistralService::class),
                'groq' => $app->make(GroqService::class),
                default => $app->make(GeminiAIStudioService::class),
            };
        });

        $this->app->singleton(GeminiAIStudioService::class);
        $this->app->singleton(MistralService::class);
        $this->app->singleton(GroqService::class);

        $this->app->singleton(CompoundSplitterService::class);

        $this->app->singleton(WordSemanticTranslatorService::class, function ($app) {
            return new WordSemanticTranslatorService(
                ai: $app->make(GeminiAIStudioService::class),
                spacy: $app->make(SpacyService::class),
                splitter: $app->make(CompoundSplitterService::class),
                translator: $app->make(ExampleTranslator::class),
            );
        });

        $this->app->singleton(PhraseTranslatorService::class, function ($app) {
            return new PhraseTranslatorService(
                $app->make(ExampleTranslator::class),
                $app->make(GeminiAIStudioService::class),
            );
        });

        $this->app->singleton(HomonymTranslatorService::class, function ($app) {
            return new HomonymTranslatorService($app->make(GeminiAIStudioService::class));
        });

        $this->app->singleton(LeipzigSentencesParser::class);

        $this->app->singleton(GeminiAIStudioService::class);

        $this->app->singleton(WordTranslationGapFillerService::class, function ($app) {
            return new WordTranslationGapFillerService(
                ai: $app->make(GeminiAIStudioService::class),
                translator: $app->make(ExampleTranslator::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
