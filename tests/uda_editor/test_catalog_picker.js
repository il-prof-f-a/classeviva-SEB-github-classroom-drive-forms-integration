const assert = require('assert');
const { filterItems, getSelectedItem } = require('../../public/assets/js/catalog-picker.js');

const items = [
  { id: '1', title: 'Verifica reti', author: 'Docente' },
  { id: '2', title: 'Laboratorio API', author: 'Docente' }
];

assert.strictEqual(filterItems(items, 'reti').length, 1);
assert.strictEqual(getSelectedItem(items, '2').title, 'Laboratorio API');
console.log('PASS: catalog picker utilities');
