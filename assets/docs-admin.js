/**
 * The Documentation screens: the page tree folded to its chapters, searched, and marked with what
 * differs from the remote
 *
 * **The tree** is the core's tree table, whose rows carry `data-id`, `data-parent` and
 * `data-depth`. It starts folded - the root and its chapters - and each row with pages under it
 * gets a button to open them. What is open is remembered for the browser session, so going to the
 * editor and back finds the tree as it was left.
 *
 * **The search** shows the pages whose title, address or file has the text in it, each with the
 * pages above it so it is still clear where it sits. Emptied, the tree is folded as before.
 *
 * **The git status** is fetched after the screen is drawn - asking git is a few processes on the
 * server, and the list should not wait for them - and a changed page is opened to, so a file that
 * needs committing is never folded out of sight.
 *
 * Through `Dpress.addInit()`, so it runs on a full load and after every partial navigation alike.
 * The decisions are plain functions over `{id, parent, depth, text}` rows (`DocsTree`), which is
 * what `docs-admin.test.js` tests; the rest reads them off the page and writes them back.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'docs-tree-open';

    var DocsTree = {

        /** The rows each row holds, by id - '' for the ones at the top */
        children: function (rows) {
            var children = {};
            rows.forEach(function (row) {
                (children[row.parent] = children[row.parent] || []).push(row.id);
            });
            return children;
        },

        /** What is open before anybody opens anything: the root, so its chapters show */
        initiallyOpen: function (rows) {
            var open = {};
            rows.forEach(function (row) {
                if (row.depth === 0) {
                    open[row.id] = true;
                }
            });
            return open;
        },

        /** The ids above a row, nearest first */
        ancestors: function (rows, id) {
            var byId = {};
            rows.forEach(function (row) { byId[row.id] = row; });
            var found = [];
            var row = byId[id];
            while (row && row.parent !== '' && byId[row.parent] && found.length < rows.length) {
                found.push(row.parent);
                row = byId[row.parent];
            }
            return found;
        },

        /**
         * Which rows show: every row whose ancestors are all open - or, with a search, the rows
         * that match it and the rows above them
         *
         * @return {Object} id => true, for the rows to show
         */
        visible: function (rows, open, query) {
            var shown = {};
            query = (query || '').trim().toLowerCase();
            if (query !== '') {
                rows.forEach(function (row) {
                    if (row.text.toLowerCase().indexOf(query) !== -1) {
                        shown[row.id] = true;
                        DocsTree.ancestors(rows, row.id).forEach(function (id) { shown[id] = true; });
                    }
                });
                return shown;
            }
            rows.forEach(function (row) {
                var hidden = DocsTree.ancestors(rows, row.id).some(function (id) { return !open[id]; });
                if (!hidden) {
                    shown[row.id] = true;
                }
            });
            return shown;
        }
    };

    function remembered(fallback) {
        try {
            var saved = JSON.parse(global.sessionStorage.getItem(STORAGE_KEY) || 'null');
            return saved && typeof saved === 'object' ? saved : fallback;
        } catch (error) {
            return fallback;   // storage off, or full: the tree simply starts folded
        }
    }

    function remember(open) {
        try {
            global.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(open));
        } catch (error) {
            // nothing to do - it is a convenience
        }
    }

    function initTree(root) {
        var holder = (root || document).querySelector('[data-docs-tree]');
        if (!holder || holder.dataset.docsTreeBound) {
            return;
        }
        holder.dataset.docsTreeBound = '1';
        var elements = {};
        var rows = Array.prototype.map.call(holder.querySelectorAll('tr[data-id]'), function (tr) {
            var id = tr.dataset.id;
            elements[id] = tr;
            return {
                id: id,
                parent: tr.dataset.parent || '',
                depth: parseInt(tr.dataset.depth || '0', 10),
                // what the search looks in: the title, the address and the file
                text: Array.prototype.map.call(tr.querySelectorAll('td[data-property]'), function (td) {
                    return td.textContent;
                }).join(' ')
            };
        });
        var children = DocsTree.children(rows);
        var open = remembered(DocsTree.initiallyOpen(rows));
        var search = holder.querySelector('[data-docs-tree-search]');
        var toggles = {};

        rows.forEach(function (row) {
            var label = elements[row.id].querySelector('td[data-tree-label]');
            if (!label) {
                return;
            }
            if (!children[row.id]) {
                var space = document.createElement('span');
                space.className = 'docs-toggle-space';
                label.insertBefore(space, label.firstChild);
                return;
            }
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'docs-toggle';
            toggle.addEventListener('click', function () {
                open[row.id] = !open[row.id];
                remember(open);
                apply();
            });
            label.insertBefore(toggle, label.firstChild);
            toggles[row.id] = toggle;
        });

        function apply() {
            var query = search ? search.value : '';
            var shown = DocsTree.visible(rows, open, query);
            rows.forEach(function (row) {
                elements[row.id].hidden = !shown[row.id];
                var toggle = toggles[row.id];
                if (toggle) {
                    // while searching, a row is as open as the matches under it make it
                    var expanded = query.trim() !== ''
                        ? children[row.id].some(function (id) { return shown[id]; })
                        : !!open[row.id];
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    toggle.setAttribute('aria-label', expanded ? 'Hide the pages under this one' : 'Show the pages under this one');
                    toggle.disabled = query.trim() !== '';
                }
            });
        }

        if (search) {
            search.addEventListener('input', apply);
        }
        apply();

        /** Opens the rows above these, so what they are is not folded out of sight */
        holder.docsReveal = function (ids) {
            ids.forEach(function (id) {
                DocsTree.ancestors(rows, id).forEach(function (above) { open[above] = true; });
            });
            remember(open);
            apply();
        };
    }

    function fillChanges(box, data) {
        box.innerHTML = data.html || '';
        var badges = data.badges || {};
        var changed = [];
        Array.prototype.forEach.call(document.querySelectorAll('.tree-table tr[data-id]'), function (row) {
            var source = row.querySelector('td[data-property="source"]');
            var cell = row.querySelector('td[data-property="git"]');
            if (source && cell) {
                var badge = badges[source.textContent.trim()] || '';
                cell.innerHTML = badge;
                if (badge !== '') {
                    changed.push(row.dataset.id);
                }
            }
        });
        var holder = document.querySelector('[data-docs-tree]');
        if (changed.length && holder && holder.docsReveal) {
            holder.docsReveal(changed);
        }
    }

    function initChanges(root) {
        var boxes = (root || document).querySelectorAll('[data-docs-changes]');
        Array.prototype.forEach.call(boxes, function (box) {
            if (box.dataset.docsLoaded || !global.fetch) {
                return;
            }
            box.dataset.docsLoaded = '1';
            global.fetch(box.dataset.docsChanges, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (data) {
                        fillChanges(box, data);
                    }
                })
                .catch(function () {
                    // no status is what a source folder that is not a clone shows too
                });
        });
    }

    global.DocsTree = DocsTree;

    if (global.Dpress && global.Dpress.addInit) {
        global.Dpress.addInit(function (root) {
            // the tree first, so the status that arrives later has a tree to open
            initTree(root);
            initChanges(root);
        });
    }
}(typeof window !== 'undefined' ? window : this));
