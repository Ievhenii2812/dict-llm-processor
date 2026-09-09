<?php

namespace App\Services\Import\Contracts;

/**
 * Common interface for all AI providers used across the import pipeline
 * (Gemini AI Studio, Mistral, Groq). Lets every consuming service depend
 * on this interface instead of a concrete provider class, so switching
 * providers is a one-line config change (AI_PROVIDER in .env) rather
 * than a code change in every service.
 *
 * "Flash" naming is kept for the fast/cheap tier across providers
 * (Gemini Flash, Mistral Small, Groq Llama-8B), "Pro" for the
 * higher-quality tier (Gemini Pro, Mistral Medium/Large, Groq larger models).
 */
interface AIProviderInterface
{
    /**
     * Fast/cheap tier — bulk classification, translation, simple tasks.
     */
    public function askFlashJson(string $systemPrompt, string $userPrompt): ?array;

    /**
     * Higher-quality tier — semantic tasks needing more reasoning
     * (categorization, idiom analogues).
     */
    public function askProJson(string $systemPrompt, string $userPrompt): ?array;
}
