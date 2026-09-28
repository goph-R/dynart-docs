/**
 * The page tree's folding and search, with no toolchain at all
 *
 *   node plugins/docs/tests/docs-admin.test.js
 *
 * `DocsTree` is plain functions over `{id, parent, depth, text}` rows - the part with the decisions
 * in it - so it is tested without a page.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = global;
eval(fs.readFileSync(path.join(__dirname, '..', 'assets', 'docs-admin.js'), 'utf8'));
const DocsTree = window.DocsTree;

// root > lisa (build, engine), dos (basics > vga), legal
const rows = [
    {id: '1', parent: '', depth: 0, text: 'Dynart Documentation index.md'},
    {id: '2', parent: '1', depth: 1, text: 'Lisa Engine lisa-engine/index.md'},
    {id: '3', parent: '2', depth: 2, text: 'How to build lisa-engine/build/index.md'},
    {id: '4', parent: '2', depth: 2, text: 'Engine Overview lisa-engine/engine/index.md'},
    {id: '5', parent: '1', depth: 1, text: 'DOS Game Engine dos-game-engine/index.md'},
    {id: '6', parent: '5', depth: 2, text: 'Graphics & Input dos-game-engine/BASICS/index.md'},
    {id: '7', parent: '6', depth: 3, text: 'VGA Graphics dos-game-engine/BASICS/VGA.md'},
    {id: '8', parent: '1', depth: 1, text: 'Legal legal/index.md'}
];

const shownIds = shown => Object.keys(shown).sort();

const tests = {

    'folded, the root and its chapters show'() {
        const open = DocsTree.initiallyOpen(rows);
        assert.deepStrictEqual(shownIds(DocsTree.visible(rows, open, '')), ['1', '2', '5', '8']);
    },

    'opening a chapter shows its pages, and only one level'() {
        const open = Object.assign(DocsTree.initiallyOpen(rows), {'5': true});
        assert.deepStrictEqual(shownIds(DocsTree.visible(rows, open, '')), ['1', '2', '5', '6', '8']);
    },

    'a page shows only when everything above it is open'() {
        // 6 is open, but 5 above it is not
        const open = Object.assign(DocsTree.initiallyOpen(rows), {'6': true});
        assert.ok(!DocsTree.visible(rows, open, '')['7']);
    },

    'a search shows the matches with the pages above them'() {
        const open = DocsTree.initiallyOpen(rows);
        assert.deepStrictEqual(shownIds(DocsTree.visible(rows, open, 'vga')), ['1', '5', '6', '7']);
    },

    'a search looks in the file as well as the title, in any case'() {
        assert.deepStrictEqual(shownIds(DocsTree.visible(rows, {}, 'LISA-ENGINE/BUILD')), ['1', '2', '3']);
    },

    'a search that matches nothing shows nothing'() {
        assert.deepStrictEqual(shownIds(DocsTree.visible(rows, {}, 'nothing like this')), []);
    },

    'an empty search is the tree as it was left'() {
        const open = Object.assign(DocsTree.initiallyOpen(rows), {'2': true});
        assert.deepStrictEqual(DocsTree.visible(rows, open, '   '), DocsTree.visible(rows, open, ''));
    },

    'the rows above a page, nearest first - what opening to a changed page opens'() {
        assert.deepStrictEqual(DocsTree.ancestors(rows, '7'), ['6', '5', '1']);
        assert.deepStrictEqual(DocsTree.ancestors(rows, '1'), []);
    },

    'children are grouped by parent, in order'() {
        const children = DocsTree.children(rows);
        assert.deepStrictEqual(children['1'], ['2', '5', '8']);
        assert.deepStrictEqual(children['2'], ['3', '4']);
        assert.strictEqual(children['7'], undefined);
    }
};

// --- runner ---

let failed = 0;
Object.keys(tests).forEach(name => {
    try {
        tests[name]();
        console.log('  ok  ' + name);
    } catch (error) {
        failed++;
        console.log('  FAIL  ' + name);
        console.log('        ' + error.message);
    }
});
const count = Object.keys(tests).length;
console.log(failed ? `\n${failed} of ${count} failed` : `\nOK (${count} tests)`);
process.exit(failed ? 1 : 0);
