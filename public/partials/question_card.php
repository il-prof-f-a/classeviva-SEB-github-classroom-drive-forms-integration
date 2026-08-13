<?php

$questionCardTemplate = $questionCardTemplate ?? false;
$questionCardData = is_array($questionCardData ?? null) ? $questionCardData : [];
$questionCardActions = $questionCardActions ?? 'none';
$questionCardSelectable = (bool)($questionCardSelectable ?? false);
$questionCardSelectionName = (string)($questionCardSelectionName ?? '');
$questionCardIndex = $questionCardIndex ?? null;
$questionCardLabel = (string)($questionCardLabel ?? '');
$questionCardEditPayload = is_array($questionCardEditPayload ?? null) ? $questionCardEditPayload : $questionCardData;
$questionCardDeleteId = (string)($questionCardDeleteId ?? ($questionCardData['id_domanda'] ?? ''));

$questionCardDifficulty = max(1, min(5, (int)($questionCardData['difficolta'] ?? 3)));
$questionCardType = strtolower((string)($questionCardData['tipo_domanda'] ?? ($questionCardData['tipo'] ?? 'aperta')));
$questionCardType = in_array($questionCardType, ['multipla', 'multipla_multi', 'chiusa'], true) ? 'multipla' : 'aperta';
$questionCardQuestion = (string)($questionCardData['domanda'] ?? '');
$questionCardExpected = (string)($questionCardData['risposta_attesa'] ?? '');
$questionCardOptions = $questionCardData['opzioni'] ?? $questionCardData['risposte'] ?? [];
if (!is_array($questionCardOptions)) {
    $questionCardOptions = [];
}
$questionCardKeywords = $questionCardData['parole_chiave'] ?? '';
if (is_array($questionCardKeywords)) {
    $questionCardKeywords = implode(', ', $questionCardKeywords);
}

$normalizeQuestionCardOption = static function ($option): ?array {
    if (is_string($option)) {
        $text = trim($option);
        return $text === '' ? null : ['text' => $text, 'correct' => false];
    }
    if (!is_array($option)) {
        return null;
    }
    $text = trim((string)($option['text'] ?? $option['testo'] ?? ''));
    return $text === '' ? null : [
        'text' => $text,
        'correct' => !empty($option['correct']) || !empty($option['corretta']),
    ];
};
$questionCardOptions = array_values(array_filter(array_map($normalizeQuestionCardOption, $questionCardOptions)));

$questionCardTypeLabel = $questionCardType === 'multipla' ? 'Risposta multipla' : 'Risposta aperta';
$questionCardDisplayQuestion = $questionCardTemplate ? '' : $questionCardQuestion;
$questionCardDisplayLabel = $questionCardTemplate ? '' : $questionCardLabel;
?>
<div class="card question-card question-card-shared<?= $questionCardSelectable ? ' import-selectable-card' : '' ?> difficulty-<?= $questionCardDifficulty ?> mb-3"
     data-question-card
     data-question-card-type="<?= htmlspecialchars($questionCardType, ENT_QUOTES) ?>"<?= $questionCardIndex !== null ? ' data-question-card-index="' . htmlspecialchars((string)$questionCardIndex, ENT_QUOTES) . '"' : '' ?>>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
                <div class="question-card-label<?= $questionCardDisplayLabel === '' ? ' d-none' : '' ?>" data-question-card-label>
                    <?= htmlspecialchars($questionCardDisplayLabel) ?>
                </div>
                <h5 class="card-title mb-2" data-question-card-text><?= htmlspecialchars($questionCardDisplayQuestion) ?></h5>
                <div class="wizard-question-summary d-none" aria-hidden="true"></div>

                <div class="answer-preview<?= $questionCardType !== 'multipla' || $questionCardOptions === [] ? ' d-none' : '' ?>" data-question-card-options>
                    <?php foreach ($questionCardOptions as $option): ?>
                        <div class="answer-line">
                            <i class="bi <?= !empty($option['correct']) ? 'bi-check-square-fill text-success' : 'bi-square text-muted' ?> answer-icon"></i>
                            <span><?= htmlspecialchars($option['text']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="alert alert-light mt-2 mb-2<?= $questionCardType !== 'aperta' || trim($questionCardExpected) === '' ? ' d-none' : '' ?>" data-question-card-open-answer>
                    <strong>Risposta attesa:</strong><br>
                    <span data-question-card-open-answer-text><?= nl2br(htmlspecialchars($questionCardExpected)) ?></span>
                </div>

                <div class="question-card-meta mb-2">
                    <span class="badge bg-secondary" data-question-card-type-label><?= htmlspecialchars($questionCardTypeLabel) ?></span>
                    <span class="badge bg-info" data-question-card-difficulty>Difficoltà: <?= $questionCardDifficulty ?>/5</span>
                </div>

                <?php $keywordItems = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string)$questionCardKeywords)))); ?>
                <div class="mb-2<?= $keywordItems === [] ? ' d-none' : '' ?>" data-question-card-keywords>
                    <small class="text-muted">Parole chiave:</small>
                    <?php foreach ($keywordItems as $keyword): ?>
                        <span class="badge bg-light text-dark keyword-tag"><?= htmlspecialchars($keyword) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($questionCardSelectable || $questionCardActions !== 'none'): ?>
                <div class="ms-3" data-question-card-actions>
                    <?php if ($questionCardSelectable && $questionCardSelectionName !== ''): ?>
                        <label class="form-check mb-2 text-nowrap">
                            <input type="checkbox" class="form-check-input" name="<?= htmlspecialchars($questionCardSelectionName, ENT_QUOTES) ?>" value="1" checked data-question-card-import>
                            <span class="form-check-label">Importa</span>
                        </label>
                    <?php endif; ?>
                    <?php if ($questionCardActions === 'wizard'): ?>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary" data-question-edit>
                                <i class="bi bi-pencil"></i> Modifica
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-question-delete>
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    <?php elseif ($questionCardActions === 'server'): ?>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary" data-question-edit
                                    data-bs-toggle="modal" data-bs-target="#addQuestionModal"
                                    data-edit="true"
                                    data-id="<?= htmlspecialchars($questionCardData['id_domanda'] ?? '', ENT_QUOTES) ?>"
                                    data-editor-payload="<?= htmlspecialchars(json_encode($questionCardEditPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Sicuro di voler eliminare questa domanda?');">
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="domanda_id" value="<?= htmlspecialchars($questionCardDeleteId, ENT_QUOTES) ?>">
                                <button type="submit" class="btn btn-outline-danger" data-question-delete>
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
