const assert = require('node:assert/strict');
const utils = require('../../public/assets/js/uda-editor-utils.js');

assert.equal(utils.filterObjectives([{ codice: 'A', descrizione: 'Reti', competenza: 'TCP', parole_chiave: '' }], 'tcp').length, 1);
assert.deepEqual(utils.normalizeKeywords('rete, rete; TCP'), ['rete', 'TCP']);
assert.deepEqual(utils.validateMultiple([{ testo: 'A', corretta: true }, { testo: 'B', corretta: true }]), { valid: true, options: [{ testo: 'A', corretta: true }, { testo: 'B', corretta: true }] });
assert.equal(utils.parseOptions('A|B*|C')[1].corretta, true);
assert.throws(() => utils.serializeMultipleChoice([{ testo: 'A', corretta: false }, { testo: 'B', corretta: false }]), /corretta/);
console.log('PASS: editor utils JS');
