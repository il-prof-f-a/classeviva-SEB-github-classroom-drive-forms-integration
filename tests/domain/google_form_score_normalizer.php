<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\GoogleFormScoreNormalizer;

final class FakeGrading
{
    public function __construct(private readonly float $pointValue)
    {
    }

    public function getPointValue(): float
    {
        return $this->pointValue;
    }
}

final class FakeQuestion
{
    public function __construct(
        private readonly string $questionId,
        private readonly ?FakeGrading $grading
    ) {
    }

    public function getQuestionId(): string
    {
        return $this->questionId;
    }

    public function getGrading(): ?FakeGrading
    {
        return $this->grading;
    }
}

final class FakeQuestionItem
{
    public function __construct(private readonly FakeQuestion $question)
    {
    }

    public function getQuestion(): FakeQuestion
    {
        return $this->question;
    }
}

final class FakeFormItem
{
    public function __construct(
        private readonly string $itemId,
        private readonly ?FakeQuestionItem $questionItem
    ) {
    }

    public static function graded(string $questionId, float $points, ?string $itemId = null): self
    {
        return new self(
            $itemId ?? ('item-' . $questionId),
            new FakeQuestionItem(new FakeQuestion($questionId, new FakeGrading($points)))
        );
    }

    public static function ungraded(string $questionId, ?string $itemId = null): self
    {
        return new self(
            $itemId ?? ('item-' . $questionId),
            new FakeQuestionItem(new FakeQuestion($questionId, null))
        );
    }

    public static function structural(string $itemId): self
    {
        return new self($itemId, null);
    }

    public function getItemId(): string
    {
        return $this->itemId;
    }

    public function getQuestionItem(): ?FakeQuestionItem
    {
        return $this->questionItem;
    }
}

$failures = [];

function checkSame(mixed $expected, mixed $actual, string $label): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures[] = $label . ': atteso ' . var_export($expected, true)
            . ', ottenuto ' . var_export($actual, true);
    }
}

function checkThrows(callable $callback, string $exceptionClass, string $label): void
{
    global $failures;
    try {
        $callback();
        $failures[] = $label . ': eccezione non generata';
    } catch (Throwable $exception) {
        if (!$exception instanceof $exceptionClass) {
            $failures[] = $label . ': eccezione inattesa ' . $exception::class;
        }
    }
}

try {
    $items15 = [];
    for ($i = 1; $i <= 15; $i++) {
        $items15[] = FakeFormItem::graded('q' . $i, 1.0);
    }
    $weights15 = GoogleFormScoreNormalizer::extractQuestionWeights($items15, []);
    checkSame(15.0, GoogleFormScoreNormalizer::totalPoints($weights15), 'totale configurato 15');

    $score15 = GoogleFormScoreNormalizer::normalize(3.0, 3.0, $weights15);
    checkSame(20.0, $score15['classic_percent'], 'punteggio classico 3/15');
    checkSame(20.0, $score15['cbm_percent'], 'punteggio CBM 3/15');

    $weights32 = GoogleFormScoreNormalizer::extractQuestionWeights([
        FakeFormItem::graded('q1', 10.0),
        FakeFormItem::graded('q2', 10.0),
        FakeFormItem::graded('q3', 12.0),
    ], []);
    checkSame(32.0, GoogleFormScoreNormalizer::totalPoints($weights32), 'totale configurato 32');
    checkSame(
        6.25,
        GoogleFormScoreNormalizer::normalize(2.0, 0.0, $weights32)['classic_percent'],
        'punteggio classico 2/32'
    );
    checkSame(
        28.125,
        GoogleFormScoreNormalizer::normalize(9.0, 0.0, $weights32)['classic_percent'],
        'punteggio classico 9/32'
    );

    $withConfidence = [
        FakeFormItem::graded('main', 5.0, 'item-main'),
        FakeFormItem::graded('confidence-question', 1.0, 'item-confidence'),
        FakeFormItem::ungraded('student-name'),
        FakeFormItem::structural('section-break'),
    ];
    checkSame(
        ['main' => 5.0],
        GoogleFormScoreNormalizer::extractQuestionWeights($withConfidence, ['confidence-question']),
        'confidenza esclusa tramite questionId'
    );
    checkSame(
        ['main' => 5.0],
        GoogleFormScoreNormalizer::extractQuestionWeights($withConfidence, ['item-confidence']),
        'confidenza esclusa tramite itemId storico'
    );

    checkSame(
        ['q1' => 1.0, 'q2' => 2.0, 'q3' => 3.0],
        GoogleFormScoreNormalizer::extractPersistedWeights([
            ['form_item_id' => 'q1', 'max_score' => '', 'punteggio_domanda' => '1'],
            ['form_item_id' => 'q2', 'max_score' => '2', 'punteggio_domanda' => ''],
            ['form_item_id' => 'q2', 'max_score' => '', 'punteggio_domanda' => ''],
            ['form_item_id' => '', 'id_domanda' => 'q3', 'max_score' => '3'],
        ]),
        'pesi persistiti riusati con fallback e righe duplicate'
    );

    $negative = GoogleFormScoreNormalizer::normalize(2.0, -6.0, ['q' => 2.0]);
    checkSame(-300.0, $negative['cbm_percent'], 'CBM negativo non troncato');
    $aboveHundred = GoogleFormScoreNormalizer::normalize(2.0, 6.0, ['q' => 2.0]);
    checkSame(300.0, $aboveHundred['cbm_percent'], 'CBM oltre 100 non troncato');

    checkThrows(
        static fn() => GoogleFormScoreNormalizer::totalPoints([]),
        RuntimeException::class,
        'nessuna domanda valutata'
    );
    checkThrows(
        static fn() => GoogleFormScoreNormalizer::extractQuestionWeights([
            FakeFormItem::graded('q0', 0.0),
        ], []),
        RuntimeException::class,
        'pointValue non positivo'
    );
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception::class . ': ' . $exception->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: normalizzazione punteggi Google Forms.\n");
