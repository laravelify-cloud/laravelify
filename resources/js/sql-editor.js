/*
 * The Database tool's SQL editor (CodeMirror 6, MIT): line numbers, syntax colors, brackets, and
 * autocomplete like DataGrip's: SQL keywords, the tables of the chosen database, and after a dot or
 * an alias (FROM users u … u.) that table's columns. The schema comes from the server once
 * (sqlSchema), and again when the database changes. Ctrl/Cmd+Enter runs the selection, or
 * everything when nothing is selected.
 */
import { EditorView, basicSetup } from 'codemirror';
import { keymap, placeholder as placeholderText } from '@codemirror/view';
import { Compartment, Prec } from '@codemirror/state';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { acceptCompletion, startCompletion } from '@codemirror/autocomplete';
import { indentWithTab } from '@codemirror/commands';
import { tags } from '@lezer/highlight';
import { MSSQL, MySQL, PostgreSQL, SQLite, StandardSQL, sql } from '@codemirror/lang-sql';

const DIALECTS = { mysql: MySQL, pgsql: PostgreSQL, sqlite: SQLite, sqlsrv: MSSQL };

const colors = HighlightStyle.define([
    { tag: tags.keyword, color: 'var(--sql-keyword)', fontWeight: '600' },
    { tag: [tags.string, tags.special(tags.string)], color: 'var(--sql-string)' },
    { tag: [tags.number, tags.bool, tags.null], color: 'var(--sql-number)' },
    { tag: [tags.lineComment, tags.blockComment, tags.comment], color: 'var(--sql-comment)', fontStyle: 'italic' },
    { tag: [tags.typeName, tags.standard(tags.name)], color: 'var(--sql-type)' },
    { tag: [tags.operator, tags.punctuation], color: 'var(--color-muted)' },
    { tag: tags.special(tags.name), color: 'var(--sql-quoted)' },
]);

const look = EditorView.theme({
    '&': { fontSize: '12.5px', backgroundColor: 'var(--color-recessed, var(--color-fill))', color: 'var(--color-ink)', borderRadius: '10px', border: '1px solid var(--color-line)' },
    '&.cm-focused': { outline: 'none', borderColor: 'var(--color-accent)', boxShadow: '0 0 0 3px color-mix(in srgb, var(--color-accent) 18%, transparent)' },
    '.cm-scroller': { fontFamily: 'var(--font-mono)', lineHeight: '1.6', minHeight: '170px', maxHeight: '420px' },
    '.cm-content': { caretColor: 'var(--color-ink)', padding: '10px 0' },
    '.cm-gutters': { backgroundColor: 'transparent', color: 'var(--color-muted)', border: 'none', borderRight: '1px solid var(--color-line)' },
    '.cm-activeLine, .cm-activeLineGutter': { backgroundColor: 'color-mix(in srgb, var(--color-ink) 4%, transparent)' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground, ::selection': { backgroundColor: 'color-mix(in srgb, var(--color-accent) 28%, transparent) !important' },
    '.cm-cursor': { borderLeftColor: 'var(--color-ink)' },
    '.cm-placeholder': { color: 'var(--color-muted)' },
    '.cm-matchingBracket': { backgroundColor: 'color-mix(in srgb, var(--color-accent) 22%, transparent)', outline: 'none' },
    '.cm-tooltip': { backgroundColor: 'var(--color-panel)', color: 'var(--color-ink)', border: '1px solid var(--color-line)', borderRadius: '10px', overflow: 'hidden', boxShadow: '0 12px 32px rgb(0 0 0 / .28)' },
    '.cm-tooltip-autocomplete > ul': { fontFamily: 'var(--font-mono)', fontSize: '12.5px', maxHeight: '18em' },
    '.cm-tooltip-autocomplete > ul > li': { padding: '3px 10px !important' },
    '.cm-tooltip-autocomplete > ul > li[aria-selected]': { backgroundColor: 'color-mix(in srgb, var(--color-accent) 24%, transparent)', color: 'var(--color-ink)' },
    '.cm-completionDetail': { color: 'var(--color-muted)', fontStyle: 'normal', marginLeft: '12px' },
    '.cm-completionMatchedText': { textDecoration: 'none', color: 'var(--color-accent)', fontWeight: '700' },
    '.cm-completionIcon': { opacity: '.7', width: '1.1em', paddingRight: '.5em' },
});

/** CodeMirror wants {table: [columns]}; each column is labelled with its table in the list. */
function schemaFor(tables) {
    const schema = {};

    Object.entries(tables || {}).forEach(([table, columns]) => {
        schema[table] = columns.map((column) => ({ label: column, type: 'property', detail: table }));
    });

    return schema;
}

export function mountSqlEditor(host, source, options) {
    const language = new Compartment();
    const dialect = DIALECTS[options.dialect] || StandardSQL;
    const languageFor = (tables) => sql({ dialect, schema: schemaFor(tables), upperCaseKeywords: true });

    const view = new EditorView({
        parent: host,
        doc: source.value || '',
        extensions: [
            basicSetup,
            language.of(languageFor({})),
            syntaxHighlighting(colors),
            look,
            placeholderText(options.placeholder || ''),
            EditorView.lineWrapping,
            Prec.highest(keymap.of([
                { key: 'Mod-Enter', run: () => { options.onRun(); return true; } },
                { key: 'Tab', run: acceptCompletion },
                { key: 'Ctrl-Space', run: startCompletion },
            ])),
            keymap.of([indentWithTab]),
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    source.value = update.state.doc.toString();
                    source.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }),
        ],
    });

    return {
        view,
        setSchema(tables) {
            view.dispatch({ effects: language.reconfigure(languageFor(tables)) });
        },
        value: () => view.state.doc.toString(),
        setValue(text) {
            view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text }, selection: { anchor: text.length } });
            view.focus();
        },
        selection() {
            const range = view.state.selection.main;

            return range.empty ? '' : view.state.sliceDoc(range.from, range.to);
        },
        destroy: () => view.destroy(),
    };
}
