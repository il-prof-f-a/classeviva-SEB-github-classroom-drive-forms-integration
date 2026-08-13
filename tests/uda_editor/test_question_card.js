const assert = require('node:assert/strict');
const QuestionCard = require('../../public/assets/js/question-card.js');

assert.equal(typeof QuestionCard.normalizeState, 'function');
const state = QuestionCard.normalizeState({
  domanda: 'Domanda multipla',
  difficolta: 4,
  tipo_domanda: 'multipla',
  risposta_attesa: JSON.stringify({ risposte: [
    { testo: 'Corretta', corretta: true },
    { testo: 'Errata', corretta: false }
  ] }),
  parole_chiave: 'uno, due, uno'
});
assert.equal(state.tipo_domanda, 'multipla');
assert.equal(state.opzioni.length, 2);
assert.equal(state.opzioni[0].corretta, true);
assert.deepEqual(state.parole_chiave, ['uno', 'due']);

console.log('PASS: question card JS');
