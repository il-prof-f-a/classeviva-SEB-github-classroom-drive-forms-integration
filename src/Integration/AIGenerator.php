<?php

namespace App\Integration;

use GuzzleHttp\Client;
use Exception;

/**
 * AIGenerator - Generazione contenuti con Claude/OpenAI/Gemini
 */
class AIGenerator
{
    private Client $client;
    private array $config;
    private string $provider;
    private ?string $model = null;

    public function __construct(array $config, ?string $provider = null, ?string $model = null)
    {
        $this->config = $config['ai'] ?? [];
        $this->provider = $provider ?: ($this->config['provider'] ?? 'claude');
        $this->model = $model;
        $this->client = new Client(['timeout' => 60]);
    }

    public function setProviderAndModel(?string $provider, ?string $model): void
    {
        if (!empty($provider)) {
            $this->provider = $provider;
        }
        if (!empty($model)) {
            $this->model = $model;
        }
    }

    /**
     * Genera materiale didattico
     */
    public function generateMaterial(array $udaData, string $tipo = 'documento', ?string $provider = null, ?string $model = null): string
    {
        $prompt = $this->loadPromptTemplate('materiale');
        $prompt = $this->replacePlaceholders($prompt, [
            'titolo' => $udaData['titolo'] ?? '',
            'argomento' => $udaData['argomento'] ?? '',
            'obiettivi' => $udaData['obiettivi'] ?? '',
            'tipo' => $tipo
        ]);

        return $this->generate($prompt, $provider, $model);
    }

    /**
     * Genera domande per interrogazione
     */
    public function generateQuestions(array $udaData, int $numQuestions = 10, ?string $provider = null, ?string $model = null): array
    {
        $prompt = $this->loadPromptTemplate('domande');
        $prompt = $this->replacePlaceholders($prompt, [
            'argomento' => $udaData['argomento'] ?? '',
            'materiale' => $udaData['materiale_contenuto'] ?? '',
            'num_domande' => $numQuestions
        ]);

        $response = $this->generate($prompt, $provider, $model);

        // Parse risposta e estrai domande (formato JSON atteso)
        $questions = json_decode($response, true);
        return $questions ?: [];
    }

    /**
     * Genera rubrica di valutazione
     */
    public function generateRubric(array $udaData, ?string $provider = null, ?string $model = null): array
    {
        $prompt = $this->loadPromptTemplate('rubrica');
        $prompt = $this->replacePlaceholders($prompt, [
            'titolo' => $udaData['titolo'] ?? '',
            'obiettivi' => $udaData['obiettivi'] ?? ''
        ]);

        $response = $this->generate($prompt, $provider, $model);
        return json_decode($response, true) ?: [];
    }

    /**
     * Genera schema presentazione
     */
    public function generatePresentationOutline(array $udaData, ?string $provider = null, ?string $model = null): array
    {
        $prompt = $this->loadPromptTemplate('presentazione');
        $prompt = $this->replacePlaceholders($prompt, [
            'titolo' => $udaData['titolo'] ?? '',
            'argomento' => $udaData['argomento'] ?? '',
            'materiale' => $udaData['materiale_contenuto'] ?? ''
        ]);

        $response = $this->generate($prompt, $provider, $model);
        return json_decode($response, true) ?: [];
    }

    /**
     * Chiamata API generica
     */
    private function generate(string $prompt, ?string $providerOverride = null, ?string $modelOverride = null): string
    {
        $provider = $providerOverride ?: $this->provider;
        $model = $modelOverride ?: $this->model;

        if ($provider === 'claude') {
            return $this->generateWithClaude($prompt, $model);
        } elseif ($provider === 'openai') {
            return $this->generateWithOpenAI($prompt, $model);
        } elseif ($provider === 'gemini') {
            return $this->generateWithGemini($prompt, $model);
        }

        throw new Exception("Provider AI non supportato: {$provider}");
    }

    /**
     * Generazione con Claude (Anthropic)
     */
    private function generateWithClaude(string $prompt, ?string $modelOverride = null): string
    {
        $apiKey = getenv('CLAUDE_API_KEY') ?? $this->config['claude']['api_key'] ?? null;
        if (!$apiKey) {
            throw new Exception("Claude API key mancante");
        }

        $response = $this->client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json'
            ],
            'json' => [
                'model' => $modelOverride ?: 'claude-sonnet-4-20250514',
                'max_tokens' => $this->config['claude']['max_tokens'] ?? 4000,
                'temperature' => $this->config['claude']['temperature'] ?? 0.7,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ]
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['content'][0]['text'] ?? '';
    }

    /**
     * Generazione con OpenAI
     */
    private function generateWithOpenAI(string $prompt, ?string $modelOverride = null): string
    {
        $apiKey = getenv('OPENAI_API_KEY') ?? $this->config['openai']['api_key'] ?? null;
        if (!$apiKey) {
            throw new Exception("OpenAI API key mancante");
        }

        $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'model' => $modelOverride ?: 'gpt-4',
                'max_tokens' => $this->config['openai']['max_tokens'] ?? 4000,
                'temperature' => $this->config['openai']['temperature'] ?? 0.7,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ]
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Generazione con Gemini (Google AI)
     */
    private function generateWithGemini(string $prompt, ?string $modelOverride = null): string
    {
        $apiKey = getenv('GEMINI_API_KEY') ?? $this->config['gemini']['api_key'] ?? null;
        if (!$apiKey) {
            throw new Exception("Gemini API key mancante");
        }

        $model = $modelOverride ?: 'gemini-2.5-flash';
        $apiVersion = $this->config['gemini']['api_version'] ?? 'v1beta';
        $maxTokens = $this->config['gemini']['max_output_tokens'] ?? 1024;
        $temperature = $this->config['gemini']['temperature'] ?? 0.7;

        $endpoint = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent";

        $response = $this->client->post($endpoint, [
            'query' => ['key' => $apiKey],
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Carica template prompt
     */
    private function loadPromptTemplate(string $templateName): string
    {
        $templatePath = ROOT_PATH . '/' . ($this->config['prompts'][$templateName . '_generation'] ?? "templates/prompts/{$templateName}.txt");

        if (!file_exists($templatePath)) {
            throw new Exception("Template prompt non trovato: {$templateName}");
        }

        return file_get_contents($templatePath);
    }

    /**
     * Sostituisce placeholder nel prompt
     */
    private function replacePlaceholders(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }
}
