// The chat view (a webview): messages, the agent and model picker, the sign-in dialog, and the
// "continue with another agent" offer. It talks to extension.js with postMessage.
(function () {
    const vscode = acquireVsCodeApi();
    const AGENTS = window.AGENTS;
    const ORDER = ['claude', 'cursor', 'copilot'];
    const saved = vscode.getState() || {};

    const state = {
        statuses: {},
        models: {},
        pick: saved.pick || { agent: 'claude', model: 'default' },
        // Effort per agent (null: the agent's own default), like Claude Code's effort dots.
        efforts: saved.efforts || {},
        expanded: {},
        pickerQuery: '',
        slash: false,
        slashQuery: '',
        slashIndex: 0,
        accounts: false,
        chat: null,
        chats: [],
        busy: false,
        attachments: [],
        // Selected code (Ctrl+L) and long pasted text, sent with the next message.
        snippets: [],
        snippetView: null,
        // Pasted or dropped images, referenced in the message as [Image #n].
        images: [],
        imageView: null,
        activeFile: null,
        useActive: true,
        picker: false,
        history: false,
        login: null,
        installing: {},
        thinking: false,
        graph: null,
        draft: saved.draft || '',
    };

    const send = (message) => vscode.postMessage(message);
    const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const persist = () => vscode.setState({ pick: state.pick, draft: state.draft, efforts: state.efforts });
    const effortOf = (agent) => (AGENTS[agent].efforts || []).includes(state.efforts[agent]) ? state.efforts[agent] : null;
    const EFFORT_LABEL = { minimal: 'Minimal', low: 'Low', medium: 'Medium', high: 'High', xhigh: 'Extra high', max: 'Max' };

    // ---------- Icons (inline SVG, the codicon shapes the chat needs) ----------
    // Material (MUI) icons, inline: the webview's security policy allows no icon font.
    const ICON = {
        send: '<svg viewBox="0 0 24 24"><path d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
        stop: '<svg viewBox="0 0 24 24"><path d="M6 6h12v12H6z"/></svg>',
        add: '<svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>',
        history: '<svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>',
        chevron: '<svg viewBox="0 0 24 24"><path d="M16.59 8.59 12 13.17 7.41 8.59 6 10l6 6 6-6z"/></svg>',
        check: '<svg viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        close: '<svg viewBox="0 0 24 24"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
        copy: '<svg viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>',
        link: '<svg viewBox="0 0 24 24"><path d="M19 19H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>',
        file: '<svg viewBox="0 0 24 24"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 0 1 5 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 0 0 5 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>',
        folder: '<svg viewBox="0 0 24 24"><path d="M9.17 6l2 2H20v10H4V6h5.17M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>',
        edit: '<svg viewBox="0 0 24 24"><path d="M14.06 9.02l.92.92L5.92 19H5v-.92l9.06-9.06M17.66 3c-.25 0-.51.1-.7.29l-1.83 1.83 3.75 3.75 1.83-1.83a.996.996 0 0 0 0-1.41l-2.34-2.34c-.2-.2-.45-.29-.71-.29zm-3.6 3.19L3 17.25V21h3.75L17.81 9.94l-3.75-3.75z"/></svg>',
        terminal: '<svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16c1.1 0 2-.9 2-2V6a2 2 0 0 0-2-2zm0 14H4V8h16v10zm-2-1h-6v-2h6v2zM7.5 17l-1.41-1.41L8.67 13l-2.59-2.59L7.5 9l4 4-4 4z"/></svg>',
        search: '<svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
        eye: '<svg viewBox="0 0 24 24"><path d="M12 6a9.77 9.77 0 0 1 8.82 5.5C19.17 14.87 15.79 17 12 17s-7.17-2.13-8.82-5.5A9.77 9.77 0 0 1 12 6m0-2C7 4 2.73 7.11 1 11.5 2.73 15.89 7 19 12 19s9.27-3.11 11-7.5C21.27 7.11 17 4 12 4zm0 5a2.5 2.5 0 0 1 0 5 2.5 2.5 0 0 1 0-5m0-2c-2.48 0-4.5 2.02-4.5 4.5S9.52 16 12 16s4.5-2.02 4.5-4.5S14.48 7 12 7z"/></svg>',
        tools: '<svg viewBox="0 0 24 24"><path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/></svg>',
        map: '<svg viewBox="0 0 24 24"><path d="M22 11V3h-7v3H9V3H2v8h7V8h2v10h4v3h7v-8h-7v3h-2V8h2v3z"/></svg>',
        warning: '<svg viewBox="0 0 24 24"><path d="M12 5.99 19.53 19H4.47L12 5.99M12 2 1 21h22L12 2zm1 14h-2v2h2v-2zm0-6h-2v4h2v-4z"/></svg>',
        'new-file': '<svg viewBox="0 0 24 24"><path d="M13 11h-2v3H8v2h3v3h2v-3h3v-2h-3zm1-9H6c-1.1 0-2 .9-2 2v16c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/></svg>',
        checklist: '<svg viewBox="0 0 24 24"><path d="M22 7h-9v2h9V7zm0 8h-9v2h9v-2zM5.54 11 2 7.46l1.41-1.41 2.12 2.12 4.24-4.24 1.41 1.41L5.54 11zm0 8L2 15.46l1.41-1.41 2.12 2.12 4.24-4.24 1.41 1.41L5.54 19z"/></svg>',
        bookmark: '<svg viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2zm0 15-5-2.18L7 18V5h10v13z"/></svg>',
        bookmarked: '<svg viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>',
        slash: '<svg viewBox="0 0 24 24"><path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 16H5V5h14v14zM9.6 17.5h-1.8L14.4 6.5h1.8z"/></svg>',
        rewind: '<svg viewBox="0 0 24 24"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg>',
        export: '<svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
        account: '<svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
        effort: '<svg viewBox="0 0 24 24"><path d="M11 21h-1l1-7H7.5c-.58 0-.57-.32-.38-.66.19-.34.05-.08.07-.12C8.48 10.94 10.42 7.54 13 3h1l-1 7h3.5c.49 0 .56.33.47.51l-.07.15C12.96 17.55 11 21 11 21z"/></svg>',
        clear: '<svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM8 9h8v10H8V9zm7.5-5-1-1h-5l-1 1H5v2h14V4z"/></svg>',
        sparkle: '<svg viewBox="0 0 24 24"><path d="M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25L19 9zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12l-5.5-2.5zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25L19 15z"/></svg>',
        image: '<svg viewBox="0 0 24 24"><path d="M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-4.86 8.86-3 3.87L9 13.14 6 17h12l-3.86-5.14z"/></svg>',
    };
    const icon = (name) => `<span class="ico">${ICON[name] || ICON.tools}</span>`;
    // Each agent's mark, drawn as SVG on its brand colour.
    const MARK = {
        claude: '<svg viewBox="0 0 24 24"><path d="M12 2.5v19M2.5 12h19M5.3 5.3l13.4 13.4M18.7 5.3 5.3 18.7" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" fill="none"/></svg>',
        cursor: '<svg viewBox="0 0 24 24"><path d="M12 2 3.5 7v10L12 22l8.5-5V7zm0 2.3 6.5 3.8L12 12 5.5 8.1zM5.5 9.8l5.5 3.3v6.3l-5.5-3.2zm7.5 9.6v-6.3l5.5-3.3v6.4z"/></svg>',
        copilot: '<svg viewBox="0 0 24 24"><path d="M12 4C7 4 3 6.5 3 11v3.5C3 18 7 20 12 20s9-2 9-5.5V11c0-4.5-4-7-9-7zm-4 4.5h2.5c1.1 0 1.5.9 1.5 2v1c0 1.1-.6 2-1.8 2H8c-1.1 0-2-.9-2-2v-1c0-1.2.9-2 2-2zm5.5 0H16c1.1 0 2 .8 2 2v1c0 1.1-.9 2-2 2h-2.2c-1.2 0-1.8-.9-1.8-2v-1c0-1.1.4-2 1.5-2zM9 15.5h1.5v2H9zm4.5 0H15v2h-1.5z"/></svg>',
    };
    const mark = (id, size = '') => `<span class="mark ${size}" style="background:${AGENTS[id].color}">${MARK[id] || AGENTS[id].mark}</span>`;

    // ---------- Markdown (safe: everything is escaped first) ----------
    function markdown(text) {
        const inline = (value) => esc(value)
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" data-url="$2">$1</a>');
        let html = '';

        String(text || '').split(/```/).forEach((part, index) => {
            if (index % 2 === 1) {
                const newline = part.indexOf('\n');
                const language = newline > -1 ? part.slice(0, newline).trim() : '';
                const code = (newline > -1 ? part.slice(newline + 1) : part).replace(/\n$/, '');
                html += `<div class="code"><div class="code-head"><span>${esc(language || 'code')}</span><button class="icon-btn" data-copy="${esc(code)}" title="Copy">${icon('copy')}</button></div><pre><code>${esc(code)}</code></pre></div>`;

                return;
            }

            let list = null;
            part.split('\n').forEach((line) => {
                const item = line.match(/^\s*([-*]|\d+\.)\s+(.*)$/);

                if (item) {
                    const tag = /\d/.test(item[1]) ? 'ol' : 'ul';

                    if (list !== tag) {
                        html += (list ? `</${list}>` : '') + `<${tag}>`;
                        list = tag;
                    }

                    html += `<li>${inline(item[2])}</li>`;

                    return;
                }

                if (list) {
                    html += `</${list}>`;
                    list = null;
                }

                const heading = line.match(/^(#{1,4})\s+(.*)$/);

                if (heading) {
                    html += `<h4>${inline(heading[2])}</h4>`;
                } else if (line.trim() !== '') {
                    html += `<p>${inline(line)}</p>`;
                }
            });

            if (list) {
                html += `</${list}>`;
            }
        });

        return html;
    }

    // ---------- Layout ----------
    const app = document.getElementById('app');
    app.innerHTML = `
        <header class="bar">
            <button class="title-btn" data-action="history" title="Chat history"><span class="chat-title"></span>${icon('chevron')}</button>
            <span class="grow"></span>
            <button class="icon-btn map-btn" data-action="map" title="Project map">${icon('map')}</button>
            <button class="icon-btn bookmark-btn" data-action="bookmark" title="Bookmark this chat">${icon('bookmark')}</button>
            <button class="icon-btn" data-action="history" title="Chat history">${icon('history')}</button>
            <button class="icon-btn" data-action="new" title="New chat">${icon('add')}</button>
            ${window.LFY_WHERE === 'panel' ? `<button class="icon-btn" data-action="closePanel" title="Close">${ICON.close}</button>` : ''}
        </header>
        <div class="history-pop" hidden></div>
        <main class="messages" tabindex="-1"></main>
        <section class="composer">
            <div class="limit-slot"></div>
            <div class="box">
                <div class="chips"></div>
                <textarea rows="1" placeholder="Ask, or describe what to build…" spellcheck="false"></textarea>
                <div class="tools">
                    <button class="icon-btn" data-action="attach" title="Add context (files, folders)">${icon('add')}</button>
                    <button class="icon-btn slash-btn" data-action="slash" title="Actions (type /)">${icon('slash')}</button>
                    <button class="picker-btn" data-action="picker" title="Agent, model and effort"></button>
                    <span class="grow"></span>
                    <button class="send-btn" data-action="send" title="Send (Enter)">${icon('send')}</button>
                </div>
                <div class="picker-pop" hidden></div>
                <div class="slash-pop" hidden></div>
            </div>
            <p class="hint">Enter to send · Shift+Enter for a new line · Shift+drag files here</p>
        </section>
        <div class="dialog-layer" hidden></div>`;

    const $ = (selector) => app.querySelector(selector);
    const messages = $('.messages');
    const textarea = $('textarea');
    textarea.value = state.draft;

    // ---------- Rendering ----------
    let frame = null;
    const schedule = () => {
        if (!frame) {
            frame = requestAnimationFrame(() => {
                frame = null;
                renderMessages();
            });
        }
    };

    function statusBadge(id) {
        const status = state.statuses[id];

        if (!status) return '<span class="badge">…</span>';
        if (state.installing[id]) return '<span class="badge">installing…</span>';
        if (!status.installed) return '<span class="badge">not installed</span>';
        if (!status.signedIn) return '<span class="badge warn">not signed in</span>';

        return `<span class="badge ok" title="${esc(status.who || '')}">${esc(status.who && status.who.includes('@') ? status.who.split('@')[0] : 'signed in')}</span>`;
    }

    function renderEmpty() {
        return `<div class="empty">
            <div class="empty-mark">${ICON.sparkle}</div>
            <h2>Build with AI agents</h2>
            <p>Chat with Claude, Cursor or Copilot about this project. When one reaches its limit, switch: the next one continues the same task.</p>
            <div class="agent-cards">${ORDER.map((id) => {
                const s = state.statuses[id] || {};
                const action = s.signedIn ? `<button class="link-btn" data-pick="${id}">Use ${esc(AGENTS[id].label)}</button>` : `<button class="primary-btn small" data-login="${id}">${s.installed ? 'Sign in' : 'Install & sign in'}</button>`;

                return `<div class="agent-card">${mark(id)}<div class="agent-card-body"><b>${esc(AGENTS[id].product)}</b><small>${esc(AGENTS[id].account)}</small></div>${statusBadge(id)}${action}</div>`;
            }).join('')}</div>
            ${state.graph === 'ready' ? `<p class="map-note">${icon('map')} The project map is ready: agents read it first and need fewer tokens.</p>` : ''}
        </div>`;
    }

    function renderTurn(turn, isLast) {
        const agent = AGENTS[turn.agent];
        const files = (turn.files || []).map((file) => `<span class="chip small">${icon(file.endsWith('/') ? 'folder' : 'file')}${esc(file.split('/').pop() || file)}</span>`).join('');
        let body = '';

        for (const part of turn.parts || []) {
            if (part.kind === 'text') {
                body += `<div class="md">${markdown(part.text)}</div>`;
            } else if (part.kind === 'tool') {
                body += `<div class="step ${part.file ? 'clickable' : ''}" ${part.file ? `data-file="${esc(part.file)}"` : ''}>${icon(part.icon)}<span>${esc(part.text)}</span></div>`;
            }
        }

        if (turn.status === 'running') {
            body += `<div class="working"><span class="spinner"></span>${state.thinking && !(turn.parts || []).length ? 'Thinking…' : 'Working…'}</div>`;
        }

        if (turn.status === 'stopped') {
            body += '<div class="note">Stopped.</div>';
        }

        if (turn.error) {
            body += `<div class="error-box ${turn.status === 'limit' ? 'limit' : ''}">${icon('warning')}<div><b>${turn.status === 'limit' ? esc(agent.product) + ' reached its usage limit' : esc(agent.product) + ' stopped'}</b><pre>${esc(turn.error)}</pre></div></div>`;
        }

        if (isLast && (turn.status === 'limit' || turn.status === 'error') && !state.busy) {
            body += `<div class="continue">${ORDER.filter((id) => id !== turn.agent).map((id) => {
                const s = state.statuses[id] || {};

                return s.signedIn
                    ? `<button class="primary-btn small" data-continue="${id}">${mark(id, 'tiny')}Continue with ${esc(AGENTS[id].label)}</button>`
                    : `<button class="secondary-btn small" data-login="${id}">${mark(id, 'tiny')}Sign in to ${esc(AGENTS[id].label)}</button>`;
            }).join('')}</div>`;
        }

        if (turn.status !== 'running' && turn.changes && turn.changes.length) {
            body += `<div class="changes"><span>${turn.changes.length} ${turn.changes.length === 1 ? 'file' : 'files'} changed by this answer</span><button class="link-btn" data-action="changes">Review in Source Control</button></div>`;
        }

        const continued = turn.continuedFrom ? `<span class="badge handoff" title="Read the handoff and the project map, then continued">continued from ${esc(AGENTS[turn.continuedFrom].label)}</span>` : '';

        return `<article class="turn" data-turn="${turn.id}">
            <div class="row user"><span class="avatar">You</span><div class="bubble">${esc(turn.prompt).replace(/\[Image #\d+\]/g, '<span class="ref">$&</span>')}${files || (turn.snippets || []).length || (turn.images || []).length ? `<div class="chips">${files}${(turn.images || []).map((image) => `<span class="chip image" title="${esc(image.path)}"><button class="chip-open" data-file="${esc(image.path)}">${icon('image')}<span>Image #${image.n}</span></button></span>`).join('')}${(turn.snippets || []).map((snippet, index) => `<span class="chip snippet" title="${esc(snippetTitle(snippet))}"><button class="chip-open" data-turn-snippet="${turn.id}:${index}">${icon(snippet.kind === 'selection' ? 'file' : 'copy')}<span>${esc(snippetLabel(snippet))}</span></button></span>`).join('')}</div>` : ''}${turn.checkpoint && !state.busy ? `<button class="icon-btn restore-btn" data-restore="${turn.id}" title="Restore checkpoint">${ICON.rewind}</button>` : ''}</div></div>
            <div class="row agent">${mark(turn.agent)}<div class="answer"><div class="who"><b>${esc(agent.product)}</b><span class="muted">${esc(turn.model || '')}</span>${continued}</div>${body || ''}</div></div>
        </article>`;
    }

    function renderMessages() {
        const turns = state.chat ? state.chat.turns : [];
        const atBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
        messages.innerHTML = turns.length ? turns.map((turn, index) => renderTurn(turn, index === turns.length - 1)).join('') : renderEmpty();

        if (atBottom || state.busy) {
            messages.scrollTop = messages.scrollHeight;
        }

        $('.chat-title').textContent = state.chat ? state.chat.title : 'Chat';
    }

    const lineCount = (text) => text.replace(/\n$/, '').split('\n').length;
    const snippetLabel = (snippet) => snippet.kind === 'selection' ? `${snippet.path.split('/').pop()} (${snippet.start}-${snippet.end})` : `Pasted text · ${lineCount(snippet.text)} lines`;
    const snippetTitle = (snippet) => snippet.kind === 'selection' ? `${snippet.path}, lines ${snippet.start}-${snippet.end}` : `${snippet.text.length.toLocaleString()} characters`;

    function renderComposer() {
        const chips = [];

        if (state.activeFile && state.useActive && !state.attachments.includes(state.activeFile)) {
            chips.push(`<span class="chip" title="${esc(state.activeFile)}">${icon('file')}${esc(state.activeFile.split('/').pop())}<i>current file</i><button data-unattach-active title="Remove">${ICON.close}</button></span>`);
        }

        state.attachments.forEach((file, index) => chips.push(`<span class="chip" title="${esc(file)}">${icon(file.endsWith('/') ? 'folder' : 'file')}${esc(file.split('/').pop() || file)}<button data-unattach="${index}" title="Remove">${ICON.close}</button></span>`));
        state.snippets.forEach((snippet, index) => chips.push(`<span class="chip snippet" title="${esc(snippetTitle(snippet))}"><button class="chip-open" data-snippet="${index}">${icon(snippet.kind === 'selection' ? 'file' : 'copy')}<span>${esc(snippetLabel(snippet))}</span></button><button data-unsnippet="${index}" title="Remove">${ICON.close}</button></span>`));
        state.images.forEach((image, index) => chips.push(`<span class="chip image" title="Image #${image.n} · click to view"><button class="chip-open" data-image="${index}"><img class="thumb" src="${image.dataUrl}" alt=""><span>Image #${image.n}</span></button><button data-unimage="${index}" title="Remove">${ICON.close}</button></span>`));
        $('.box > .chips').innerHTML = chips.join('');

        const agent = AGENTS[state.pick.agent];
        const models = state.models[state.pick.agent] || [];
        const model = models.find((m) => m.id === state.pick.model);
        const effort = effortOf(state.pick.agent);
        $('.picker-btn').innerHTML = `${mark(state.pick.agent, 'tiny')}<span>${esc(agent.label)}</span><span class="muted">${esc(model ? model.name : state.pick.model)}${effort ? ' · ' + esc(EFFORT_LABEL[effort]) : ''}</span>${icon('chevron')}`;
        const bookmark = $('.bookmark-btn');
        bookmark.innerHTML = icon(state.chat && state.chat.bookmarked ? 'bookmarked' : 'bookmark');
        bookmark.title = state.chat && state.chat.bookmarked ? 'Remove bookmark' : 'Bookmark this chat';
        bookmark.classList.toggle('on', !!(state.chat && state.chat.bookmarked));

        const send = $('.send-btn');
        send.innerHTML = icon(state.busy ? 'stop' : 'send');
        send.title = state.busy ? 'Stop' : 'Send (Enter)';
        send.classList.toggle('stop', state.busy);
        send.disabled = !state.busy && textarea.value.trim() === '' && !state.snippets.length && !state.images.length;
        $('.map-btn').classList.toggle('busy', state.graph === 'updating');
        $('.map-btn').title = state.graph === 'updating' ? 'Updating the project map…' : state.graph === 'ready' ? 'Project map (graphify): open the report' : 'Project map: not built yet';
    }

    /** Effort as dots (like Claude Code): the agent's own default, then each level. */
    function effortRow(id) {
        const levels = AGENTS[id].efforts || [];

        if (!levels.length) {
            return `<div class="effort-row muted">${icon('effort')}<span>Effort</span><span class="grow"></span><small>set by the model (Low, High… variants)</small></div>`;
        }

        const current = effortOf(id);
        const dots = levels.map((level, index) => {
            const on = current !== null && index <= levels.indexOf(current);

            return `<button class="dot ${on ? 'on' : ''} ${current === level ? 'current' : ''}" data-effort="${level}" data-effort-agent="${id}" title="${esc(EFFORT_LABEL[level])}"></button>`;
        }).join('');

        return `<div class="effort-row">${icon('effort')}<span>Effort</span><span class="muted">(${esc(current ? EFFORT_LABEL[current] : 'Default')})</span><span class="grow"></span>
            <button class="link-btn tiny ${current === null ? 'hidden-link' : ''}" data-effort="" data-effort-agent="${id}" title="Use the agent's default">reset</button><span class="dots">${dots}</span></div>`;
    }

    /** The current agent's group starts open; the others start folded. */
    function isExpanded(id) {
        return state.expanded[id] ?? id === state.pick.agent;
    }

    function renderPicker() {
        const pop = $('.picker-pop');
        pop.hidden = !state.picker;

        if (!state.picker) return;

        const query = state.pickerQuery.trim().toLowerCase();
        const groups = ORDER.map((id) => {
            const s = state.statuses[id] || {};
            // Only the featured models show; a search looks through all of them.
            const models = (state.models[id] || []).filter((m) => query ? (m.name + ' ' + m.id + ' ' + AGENTS[id].label).toLowerCase().includes(query) : m.featured !== false || m.id === state.pick.model);
            // The current agent is open; the others fold away (a search opens every match).
            const open = query ? models.length > 0 : isExpanded(id);
            const who = s.who ? `<span class="who-mail" title="${esc(s.who)}">${esc(s.who)}</span>` : '';
            const head = `<div class="pick-head" data-toggle="${id}">
                <span class="twisty ${open ? 'open' : ''}">${icon('chevron')}</span>${mark(id)}
                <span class="pick-name"><b>${esc(AGENTS[id].product)}</b>${s.signedIn ? who : ''}</span>
                ${s.signedIn ? '' : statusBadge(id)}<span class="grow"></span>
                ${s.signedIn ? `<button class="link-btn" data-logout="${id}">Sign out</button>` : `<button class="primary-btn small" data-login="${id}">${s.installed ? 'Sign in' : 'Install'}</button>`}</div>`;

            if (!s.signedIn || !open) {
                return `<div class="pick-group">${head}</div>`;
            }

            const list = state.models[id]
                ? models.map((m) => {
                    const active = state.pick.agent === id && state.pick.model === m.id;

                    return `<button class="pick-item ${active ? 'active' : ''}" data-model="${esc(m.id)}" data-agent="${id}">${active ? icon('check') : '<span class="ico"></span>'}<span>${esc(m.name)}</span><span class="muted">${esc(m.detail || '')}</span></button>`;
                }).join('') + (!query && models.length < state.models[id].length ? `<div class="pick-more muted">${state.models[id].length - models.length} more: type in the filter to search them</div>` : '') || '<div class="pick-loading muted">No model matches.</div>'
                : '<div class="pick-loading"><span class="spinner"></span>Loading models…</div>';

            return `<div class="pick-group">${head}<div class="pick-list">${list}</div>${effortRow(id)}</div>`;
        }).join('');

        pop.innerHTML = `<div class="pop-search"><input class="picker-filter" placeholder="Filter models…" value="${esc(state.pickerQuery)}" spellcheck="false"></div><div class="pop-scroll">${groups}</div>`;
    }

    /** The "/" menu: what Claude Code's menu offers, for every agent. */
    function slashItems() {
        const chat = state.chat;
        const items = [
            { section: 'Context' },
            { id: 'attach', icon: 'file', label: 'Attach file…' },
            { id: 'mention', icon: 'search', label: 'Mention file from this project…' },
            { id: 'clear', icon: 'clear', label: 'Clear conversation', hint: 'starts a new chat' },
            { id: 'rewind', icon: 'rewind', label: 'Rewind', hint: 'take back the last message', disabled: !chat || !chat.turns.length || state.busy },
            { id: 'restore', icon: 'history', label: 'Restore checkpoint', hint: 'files back to before your last message', disabled: !chat || !chat.turns.some((turn) => turn.checkpoint) || state.busy },
            { id: 'bookmarks', icon: 'bookmark', label: 'Bookmarks', hint: (state.chats.filter((c) => c.bookmarked).length || 'none') + '' },
            { id: 'export', icon: 'export', label: 'Export conversation', hint: 'as Markdown', disabled: !chat || !chat.turns.length },
            { section: 'Model' },
            { id: 'model', icon: 'tools', label: 'Switch model…', hint: AGENTS[state.pick.agent].label + ' · ' + ((state.models[state.pick.agent] || []).find((m) => m.id === state.pick.model) || { name: state.pick.model }).name },
            { id: 'effort', effort: true },
            { id: 'accounts', icon: 'account', label: 'Account & usage…' },
            { section: 'Project' },
            { id: 'map', icon: 'map', label: state.graph === 'ready' ? 'Open the project map' : 'Build the project map', hint: 'graphify' },
        ];
        const query = state.slashQuery.trim().toLowerCase();

        return query ? items.filter((item) => !item.section && (item.effort ? 'effort' : item.label.toLowerCase()).includes(query)) : items;
    }

    function renderSlash() {
        const pop = $('.slash-pop');
        pop.hidden = !state.slash;

        if (!state.slash) return;

        const items = slashItems();
        const actionable = items.filter((item) => !item.section && !item.effort && !item.disabled);
        state.slashIndex = Math.min(state.slashIndex, Math.max(0, actionable.length - 1));
        const active = actionable[state.slashIndex];

        pop.innerHTML = `<div class="pop-search"><input class="slash-filter" placeholder="Filter actions…" value="${esc(state.slashQuery)}" spellcheck="false"></div><div class="pop-scroll">${items.map((item) => {
            if (item.section) return `<div class="pop-section">${esc(item.section)}</div>`;
            if (item.effort) return effortRow(state.pick.agent);

            return `<button class="pop-item ${item === active ? 'active' : ''}" data-slash="${item.id}" ${item.disabled ? 'disabled' : ''}>${icon(item.icon)}<span>${esc(item.label)}</span><span class="muted">${esc(item.hint || '')}</span></button>`;
        }).join('') || '<div class="pick-loading muted">No action matches.</div>'}</div>`;
    }

    function runSlash(id) {
        state.slash = false;
        state.slashQuery = '';

        if (textarea.value === '/') {
            textarea.value = '';
        }

        switch (id) {
            case 'attach': send({ type: 'attachPick' }); break;
            case 'mention': send({ type: 'mentionPick' }); break;
            case 'clear': send({ type: 'newChat' }); break;
            case 'rewind': send({ type: 'rewind' }); break;
            case 'restore': {
                const last = state.chat.turns.filter((turn) => turn.checkpoint).pop();
                if (last) send({ type: 'restore', turnId: last.id });
                break;
            }
            case 'bookmarks': state.history = true; state.historyFilter = 'bookmarked'; break;
            case 'export': send({ type: 'export' }); break;
            case 'model': openPicker(); return;
            case 'accounts': state.accounts = true; send({ type: 'agents' }); break;
            case 'map': send({ type: state.graph === 'ready' ? 'openMap' : 'rebuildMap' }); break;
        }

        renderAll();
    }

    function renderHistory() {
        const pop = $('.history-pop');
        pop.hidden = !state.history;

        if (!state.history) return;

        const onlyBookmarks = state.historyFilter === 'bookmarked';
        const chats = state.chats
            .filter((chat) => !onlyBookmarks || chat.bookmarked)
            .sort((a, b) => (b.bookmarked ? 1 : 0) - (a.bookmarked ? 1 : 0) || b.updated - a.updated);

        pop.innerHTML = `<div class="history-head"><button class="tab ${onlyBookmarks ? '' : 'on'}" data-history-filter="all">Recent chats</button><button class="tab ${onlyBookmarks ? 'on' : ''}" data-history-filter="bookmarked">Bookmarks</button></div>` + (chats.length ? chats.map((chat) => `<div class="history-item ${state.chat && state.chat.id === chat.id ? 'active' : ''}" data-open="${chat.id}">
            ${chat.bookmarked ? `<span class="star">${icon('bookmarked')}</span>` : ''}<span class="history-title">${esc(chat.title)}</span>
            <span class="history-agents">${(chat.agents || []).map((id) => mark(id, 'tiny')).join('')}</span>
            <button class="icon-btn" data-delete="${chat.id}" title="Delete">${ICON.close}</button></div>`).join('') : `<p class="muted pad">${onlyBookmarks ? 'No bookmarked chats. Use the bookmark button at the top of a chat.' : 'No chats yet.'}</p>`);
    }

    /** Account & usage: who is signed in to each agent, sign in or out, and where to see usage. */
    function renderAccounts(layer) {
        layer.innerHTML = `<div class="dialog" role="dialog" aria-modal="true">
            <div class="dialog-head"><span class="mark" style="background:var(--vscode-button-background)">${ICON.account}</span><div><h3>Account &amp; usage</h3><p>Sign-ins stay with this website's server user. Usage and limits are on each provider's page.</p></div><button class="icon-btn" data-action="accountsClose" title="Close">${ICON.close}</button></div>
            <div class="accounts">${ORDER.map((id) => {
                const s = state.statuses[id] || {};

                return `<div class="account-row">${mark(id)}<div class="account-body"><b>${esc(AGENTS[id].product)}</b><small>${s.signedIn ? esc(s.who || 'Signed in') : s.installed ? 'Not signed in' : 'Not installed'}</small></div>
                    ${s.signedIn ? `<button class="secondary-btn small" data-usage="${id}">${icon('link')}Usage</button><button class="link-btn" data-logout="${id}">Sign out</button>` : `<button class="primary-btn small" data-login="${id}">${s.installed ? 'Sign in' : 'Install & sign in'}</button>`}</div>`;
            }).join('')}</div>
            <div class="dialog-foot"><button class="primary-btn small" data-action="accountsClose">Done</button></div>
        </div>`;
    }

    /** View or edit an attached selection or pasted text (like Claude's pasted-text cards). */
    function viewedSnippet() {
        const view = state.snippetView;

        if (view.turnId) {
            const turn = state.chat && state.chat.turns.find((t) => t.id === view.turnId);

            return turn && turn.snippets ? turn.snippets[view.index] : null;
        }

        return state.snippets[view.index];
    }

    function renderSnippet(layer) {
        const snippet = viewedSnippet();

        if (!snippet) {
            state.snippetView = null;
            layer.hidden = true;

            return;
        }

        const readOnly = !!state.snippetView.turnId;
        layer.innerHTML = `<div class="dialog wide" role="dialog" aria-modal="true">
            <div class="dialog-head"><span class="mark" style="background:var(--vscode-button-background)">${ICON[snippet.kind === 'selection' ? 'file' : 'copy']}</span><div><h3>${esc(snippetLabel(snippet))}</h3><p>${esc(snippetTitle(snippet))}${readOnly ? ' · sent with this message' : ' · edit it before sending'}</p></div><button class="icon-btn" data-action="snippetClose" title="Close">${ICON.close}</button></div>
            <textarea class="snippet-edit" spellcheck="false" ${readOnly ? 'readonly' : ''}>${esc(snippet.text)}</textarea>
            <div class="dialog-foot">${readOnly
                ? `<button class="secondary-btn small" data-copy="${esc(snippet.text)}">${icon('copy')}Copy</button><button class="primary-btn small" data-action="snippetClose">Close</button>`
                : `<button class="link-btn danger" data-action="snippetRemove">Remove</button><span class="grow"></span><button class="secondary-btn small" data-action="snippetClose">Cancel</button><button class="primary-btn small" data-action="snippetSave">Save</button>`}</div>
        </div>`;
    }

    function renderDialog() {
        const layer = $('.dialog-layer');
        const login = state.login;
        layer.hidden = !login && !state.accounts && !state.snippetView && state.imageView === null;

        if (!login && state.imageView !== null) {
            const image = state.images[state.imageView];

            if (!image) {
                state.imageView = null;
                layer.hidden = true;

                return;
            }

            layer.innerHTML = `<div class="dialog wide" role="dialog" aria-modal="true">
                <div class="dialog-head"><span class="mark" style="background:var(--vscode-button-background)">${ICON.image}</span><div><h3>Image #${image.n}</h3><p>Write [Image #${image.n}] in your message to point at it.</p></div><button class="icon-btn" data-action="imageClose" title="Close">${ICON.close}</button></div>
                <img class="image-view" src="${image.dataUrl}" alt="Image #${image.n}">
                <div class="dialog-foot"><button class="link-btn danger" data-action="imageRemove">Remove</button><span class="grow"></span><button class="secondary-btn small" data-action="imageRef">Insert [Image #${image.n}]</button><button class="primary-btn small" data-action="imageClose">Done</button></div>
            </div>`;

            return;
        }

        if (!login && state.snippetView) {
            renderSnippet(layer);

            return;
        }

        if (!login && state.accounts) {
            renderAccounts(layer);

            return;
        }

        if (!login) return;

        const agent = AGENTS[login.agent];
        const step = (n, title, content) => `<li><span class="step-n">${n}</span><div><b>${title}</b>${content}</div></li>`;
        let steps = '';
        let n = 1;

        if (login.state === 'starting') {
            steps = `<div class="dialog-status"><span class="spinner"></span>Starting ${esc(agent.product)} sign-in…</div>`;
        } else if (login.state === 'done') {
            steps = `<div class="dialog-done">${icon('check')}Signed in to ${esc(agent.product)}.</div>`;
        } else if (login.state === 'error') {
            steps = `<div class="error-box">${icon('warning')}<div><b>Sign-in did not finish</b><pre>${esc(login.message || '')}</pre></div></div>`;
        } else {
            if (login.url) {
                steps += step(n++, 'Open the sign-in page', `<div class="copy-field"><input readonly value="${esc(login.url)}"><button class="icon-btn" data-copy="${esc(login.url)}" title="Copy link">${icon('copy')}</button></div><button class="primary-btn small" data-url="${esc(login.url)}">${icon('link')}Open in browser</button>`);
            }

            if (login.code) {
                steps += step(n++, 'Enter this code on that page', `<div class="device-code"><span>${esc(login.code)}</span><button class="icon-btn" data-copy="${esc(login.code)}" title="Copy code">${icon('copy')}</button></div>`);
            }

            if (login.needsInput) {
                steps += step(n++, 'Paste the code the page shows', `<div class="copy-field"><input class="answer" placeholder="Paste the code here" autocomplete="off"><button class="primary-btn small" data-action="answer">Continue</button></div>`);
            } else {
                steps += step(n++, 'Approve in your browser', `<div class="dialog-status"><span class="spinner"></span>${login.state === 'checking' ? 'Checking…' : 'Waiting for you to finish signing in…'}</div>`);
            }

            steps = `<ol class="steps">${steps}</ol>`;
        }

        layer.innerHTML = `<div class="dialog" role="dialog" aria-modal="true">
            <div class="dialog-head">${mark(login.agent)}<div><h3>Sign in to ${esc(agent.product)}</h3><p>With your ${esc(agent.account)}. The sign-in stays with this website's server user.</p></div><button class="icon-btn" data-action="loginClose" title="Close">${ICON.close}</button></div>
            ${steps}
            <div class="dialog-foot">${login.state === 'done' || login.state === 'error' ? `<button class="primary-btn small" data-action="loginClose">${login.state === 'done' ? 'Done' : 'Close'}</button>` : '<button class="secondary-btn small" data-action="loginCancel">Cancel</button>'}</div>
        </div>`;

        const answer = layer.querySelector('.answer');

        if (answer) {
            answer.focus();
        }
    }

    function renderAll() {
        // A filter box being typed in keeps its focus and caret across re-renders.
        const focused = document.activeElement && document.activeElement.matches('.picker-filter, .slash-filter') ? document.activeElement.className : null;
        renderMessages();
        renderComposer();
        renderPicker();
        renderSlash();
        renderHistory();
        renderDialog();

        if (focused) {
            const input = app.querySelector('.' + focused.split(' ')[0]);

            if (input) {
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);
            }
        }
    }

    // ---------- Actions ----------
    function context() {
        const files = [...state.attachments];

        if (state.activeFile && state.useActive && !files.includes(state.activeFile)) {
            files.unshift(state.activeFile);
        }

        return files;
    }

    function submit(promptOverride = null, agentOverride = null, extraSnippets = null) {
        const snippets = extraSnippets || (promptOverride === null ? state.snippets : []);
        const images = promptOverride === null ? state.images.map(({ n, dataUrl }) => ({ n, dataUrl })) : [];
        const prompt = promptOverride ?? (textarea.value.trim() || (images.length ? 'Look at the attached image' + (images.length > 1 ? 's.' : '.') : snippets.length ? 'Look at the attached code.' : ''));

        if (!prompt || state.busy) return;

        const agent = agentOverride || state.pick.agent;
        const status = state.statuses[agent] || {};

        if (!status.signedIn) {
            startLogin(agent);

            return;
        }

        const model = agentOverride ? (state.models[agent] && state.models[agent][0] ? state.models[agent][0].id : null) : state.pick.model;
        send({ type: 'send', agent, model, effort: effortOf(agent), prompt, files: context(), snippets, images });

        if (promptOverride === null) {
            textarea.value = '';
            state.draft = '';
            state.attachments = [];
            state.snippets = [];
            state.images = [];
            state.useActive = true;
            persist();
            autosize();
        }

        renderComposer();
    }

    function startLogin(agent) {
        state.login = { agent, state: 'starting' };
        state.picker = false;
        send({ type: 'login', agent });
        renderAll();
    }

    function pickModel(agent, model) {
        state.pick = { agent, model };
        state.picker = false;
        persist();
        send({ type: 'pick', agent, model });
        renderAll();
    }

    function openPicker() {
        state.picker = !state.picker;
        state.history = false;

        if (state.picker) {
            ORDER.forEach((id) => {
                if ((state.statuses[id] || {}).signedIn && !state.models[id]) {
                    send({ type: 'models', agent: id });
                }
            });
            send({ type: 'agents' });
        }

        renderAll();
    }

    function autosize() {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 220) + 'px';
    }

    app.addEventListener('click', (event) => {
        const target = event.target.closest('button, [data-open], [data-file], [data-toggle], a[data-url]');

        if (!target) {
            if (!event.target.closest('.picker-pop, .picker-btn')) state.picker = false;
            if (!event.target.closest('.history-pop, .title-btn')) state.history = false;
            if (!event.target.closest('.slash-pop, .slash-btn')) state.slash = false;
            renderPicker();
            renderSlash();
            renderHistory();

            return;
        }

        const d = target.dataset;

        if (target.tagName === 'A' && d.url) {
            event.preventDefault();
            send({ type: 'openUrl', url: d.url });
        } else if (d.copy !== undefined) {
            send({ type: 'copy', text: d.copy });
            target.classList.add('copied');
            setTimeout(() => target.classList.remove('copied'), 1200);
        } else if (d.url) {
            send({ type: 'openUrl', url: d.url });
        } else if (d.snippet !== undefined) {
            state.snippetView = { index: Number(d.snippet) };
            renderDialog();
        } else if (d.turnSnippet) {
            const [turnId, index] = d.turnSnippet.split(':');
            state.snippetView = { turnId, index: Number(index) };
            renderDialog();
        } else if (d.image !== undefined) {
            state.imageView = Number(d.image);
            renderDialog();
        } else if (d.unimage !== undefined) {
            state.images.splice(Number(d.unimage), 1);
            renderComposer();
        } else if (d.unsnippet !== undefined) {
            state.snippets.splice(Number(d.unsnippet), 1);
            renderComposer();
        } else if (d.restore) {
            send({ type: 'restore', turnId: d.restore });
        } else if (d.toggle) {
            state.expanded[d.toggle] = !isExpanded(d.toggle);
            renderPicker();
        } else if (d.effort !== undefined && d.effortAgent) {
            state.efforts[d.effortAgent] = d.effort || null;
            persist();
            renderAll();
        } else if (d.slash) {
            runSlash(d.slash);
        } else if (d.historyFilter) {
            state.historyFilter = d.historyFilter;
            renderHistory();
        } else if (d.usage) {
            send({ type: 'usage', agent: d.usage });
        } else if (d.login) {
            state.accounts = false;
            startLogin(d.login);
        } else if (d.logout) {
            send({ type: 'logout', agent: d.logout });
        } else if (d.model) {
            pickModel(d.agent, d.model);
        } else if (d.pick) {
            pickModel(d.pick, (state.models[d.pick] && state.models[d.pick][0] || {}).id || (d.pick === 'claude' ? 'default' : 'auto'));
            textarea.focus();
        } else if (d.continue) {
            pickModel(d.continue, (state.models[d.continue] && state.models[d.continue][0] || {}).id || (d.continue === 'claude' ? 'default' : 'auto'));
            submit('Continue the task from where the previous assistant stopped. Check what is already done before changing anything.', d.continue);
        } else if (d.open) {
            if (!event.target.closest('[data-delete]')) {
                send({ type: 'openChat', id: d.open });
                state.history = false;
            }
        } else if (d.delete) {
            send({ type: 'deleteChat', id: d.delete });
        } else if (d.unattach !== undefined) {
            state.attachments.splice(Number(d.unattach), 1);
            renderComposer();
        } else if (d.unattachActive !== undefined) {
            state.useActive = false;
            renderComposer();
        } else if (d.file) {
            send({ type: 'openFile', path: d.file });
        } else {
            switch (d.action) {
                case 'send': state.busy ? send({ type: 'stop' }) : submit(); break;
                case 'picker': openPicker(); break;
                case 'history': state.history = !state.history; state.historyFilter = 'all'; state.picker = false; renderAll(); break;
                case 'slash':
                    state.slash = !state.slash;
                    state.slashQuery = '';
                    state.slashIndex = 0;
                    state.picker = false;
                    renderAll();
                    if (state.slash) app.querySelector('.slash-filter').focus();
                    break;
                case 'bookmark': send({ type: 'bookmark' }); break;
                case 'accountsClose': state.accounts = false; renderDialog(); break;
                case 'snippetClose': state.snippetView = null; renderDialog(); break;
                case 'imageClose': state.imageView = null; renderDialog(); break;
                case 'imageRemove': state.images.splice(state.imageView, 1); state.imageView = null; renderAll(); break;
                case 'imageRef': {
                    const image = state.images[state.imageView];
                    state.imageView = null;
                    renderDialog();
                    insertAtCaret(`[Image #${image.n}] `);
                    break;
                }
                case 'snippetRemove': state.snippets.splice(state.snippetView.index, 1); state.snippetView = null; renderAll(); break;
                case 'snippetSave': {
                    const snippet = state.snippets[state.snippetView.index];

                    if (snippet) {
                        snippet.text = app.querySelector('.snippet-edit').value;
                    }

                    state.snippetView = null;
                    renderAll();
                    break;
                }
                case 'new': send({ type: 'newChat' }); break;
                case 'closePanel': send({ type: 'closePanel' }); break;
                case 'attach': send({ type: 'attachPick' }); break;
                case 'changes': send({ type: 'openChanges' }); break;
                case 'map': send({ type: state.graph === 'ready' ? 'openMap' : 'rebuildMap' }); break;
                case 'answer': {
                    const input = app.querySelector('.answer');

                    if (input && input.value.trim()) {
                        send({ type: 'loginAnswer', text: input.value.trim() });
                    }

                    break;
                }
                case 'loginCancel': send({ type: 'loginCancel' }); state.login = null; renderDialog(); break;
                case 'loginClose': state.login = null; renderDialog(); break;
            }
        }
    });

    textarea.addEventListener('input', () => {
        state.draft = textarea.value;
        persist();
        autosize();
        renderComposer();

        // "/" in an empty box opens the actions menu, like Claude Code.
        if (textarea.value === '/' && !state.slash) {
            state.slash = true;
            state.slashQuery = '';
            state.slashIndex = 0;
            renderSlash();
            app.querySelector('.slash-filter').focus();
        }
    });
    app.addEventListener('input', (event) => {
        if (event.target.classList.contains('picker-filter')) {
            state.pickerQuery = event.target.value;
            renderAll();
        } else if (event.target.classList.contains('slash-filter')) {
            state.slashQuery = event.target.value;
            state.slashIndex = 0;
            renderAll();
        }
    });
    // ↑ / ↓ go through the messages you sent in this chat, newest first (like Cursor's box).
    let recall = { index: -1, draft: '' };

    textarea.addEventListener('keydown', (event) => {
        if ((event.key === 'ArrowUp' || event.key === 'ArrowDown') && !event.shiftKey && !event.altKey && !event.ctrlKey && !event.metaKey) {
            const sent = (state.chat ? state.chat.turns.map((turn) => turn.prompt) : []).reverse();
            const atTop = textarea.selectionStart === 0 && textarea.selectionEnd === 0 || !textarea.value.slice(0, textarea.selectionStart).includes('\n');
            const atBottom = !textarea.value.slice(textarea.selectionEnd).includes('\n');
            const up = event.key === 'ArrowUp';

            if (sent.length && (up ? atTop && (recall.index > -1 || textarea.value.trim() === '' || textarea.selectionStart === 0) : atBottom && recall.index > -1)) {
                event.preventDefault();

                if (recall.index === -1) recall.draft = textarea.value;

                recall.index = Math.max(-1, Math.min(sent.length - 1, recall.index + (up ? 1 : -1)));
                textarea.value = recall.index === -1 ? recall.draft : sent[recall.index];
                textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                state.draft = textarea.value;
                autosize();
                renderComposer();

                return;
            }
        }

        if (!event.key.startsWith('Arrow')) recall.index = -1;

        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            submit();
        }
    });
    app.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.classList.contains('answer')) {
            event.preventDefault();
            app.querySelector('[data-action="answer"]').click();
        }

        if (state.slash && event.target.classList.contains('slash-filter')) {
            const actionable = slashItems().filter((item) => !item.section && !item.effort && !item.disabled);

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                state.slashIndex = (state.slashIndex + step + actionable.length) % Math.max(1, actionable.length);
                renderAll();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (actionable[state.slashIndex]) runSlash(actionable[state.slashIndex].id);
            } else if (event.key === 'Backspace' && !event.target.value) {
                state.slash = false;
                if (textarea.value === '/') textarea.value = '';
                renderSlash();
                textarea.focus();
            }
        }

        if (event.key === 'Escape') {
            state.picker = false;
            state.history = false;
            state.slash = false;
            state.accounts = false;
            state.snippetView = null;
            state.imageView = null;
            renderAll();
            textarea.focus();
        }
    });

    // Files dragged from the Explorer or an editor tab, dropped anywhere on the chat. VS Code
    // hands them over as URIs or paths, under different types depending on where they came from.
    const box = $('.box');
    const droppedPaths = (data) => {
        const paths = [];

        for (const type of ['codefiles', 'resourceurls']) {
            try {
                paths.push(...JSON.parse(data.getData(type) || '[]'));
            } catch (e) {}
        }

        for (const type of ['application/vnd.code.uri-list', 'text/uri-list']) {
            paths.push(...(data.getData(type) || '').split(/\r?\n/).filter((line) => line && !line.startsWith('#')));
        }

        const plain = (data.getData('text/plain') || '').trim();

        if (!paths.length && /^(\/|[a-z][\w+.-]*:\/\/)\S+$/i.test(plain)) {
            paths.push(plain);
        }

        return [...new Set(paths)];
    };
    let dragDepth = 0;
    document.addEventListener('dragenter', (event) => { event.preventDefault(); dragDepth++; box.classList.add('drop'); });
    document.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; box.classList.remove('drop'); } });
    document.addEventListener('dragover', (event) => { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; }, true);
    document.addEventListener('drop', (event) => {
        dragDepth = 0;
        box.classList.remove('drop');
        const paths = droppedPaths(event.dataTransfer);

        const droppedImages = [...(event.dataTransfer.files || [])].filter((file) => file.type.startsWith('image/'));

        if (droppedImages.length && !paths.length) {
            event.preventDefault();
            event.stopPropagation();
            addImages(droppedImages);
        } else if (paths.length) {
            event.preventDefault();
            event.stopPropagation();
            send({ type: 'dropPaths', paths });
        } else if (event.target !== textarea) {
            event.preventDefault();
        }
    }, true);

    function insertAtCaret(text) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const before = textarea.value.slice(0, start);
        const glue = before && !/\s$/.test(before) ? ' ' : '';
        textarea.value = before + glue + text + textarea.value.slice(end);
        textarea.selectionStart = textarea.selectionEnd = start + glue.length + text.length;
        state.draft = textarea.value;
        persist();
        autosize();
        renderComposer();
        textarea.focus();
    }

    /** An image (pasted or dropped) becomes Image #n, with a reference typed at the caret. */
    function addImages(files) {
        const IMAGE_LIMIT = 10 * 1024 * 1024;

        files.filter((file) => /^image\/(png|jpe?g|gif|webp)$/.test(file.type)).forEach((file) => {
            if (file.size > IMAGE_LIMIT) {
                window.postMessage({ type: 'error', message: 'Images up to 10 MB can be attached.' }, '*');

                return;
            }

            const reader = new FileReader();
            reader.onload = () => {
                const n = state.images.reduce((max, image) => Math.max(max, image.n), 0) + 1;
                state.images.push({ n, dataUrl: reader.result });
                insertAtCaret(`[Image #${n}] `);
            };
            reader.readAsDataURL(file);
        });
    }

    // Long pasted text becomes a card (view and edit it before sending), not a wall of text.
    textarea.addEventListener('paste', (event) => {
        const pastedImages = [...(event.clipboardData ? event.clipboardData.items : [])]
            .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
            .map((item) => item.getAsFile())
            .filter(Boolean);

        if (pastedImages.length) {
            event.preventDefault();
            addImages(pastedImages);

            return;
        }

        const text = event.clipboardData && event.clipboardData.getData('text/plain');

        if (text && (lineCount(text) >= 12 || text.length >= 1200)) {
            event.preventDefault();
            state.snippets.push({ kind: 'paste', text });
            renderComposer();
        }
    });

    // ---------- Messages from the extension ----------
    window.addEventListener('message', ({ data }) => {
        switch (data.type) {
            case 'snippet':
                state.snippets.push(data.snippet);
                renderComposer();
                textarea.focus();
                break;
            // Both chats (side bar, phone tab) get it: the one on screen sends it.
            case 'prompt':
                if (!document.hidden) submit(data.text, null, []);
                break;
            case 'quickEdit':
                submit(data.instruction, null, [data.snippet]);
                break;
            case 'restorePrompt':
                textarea.value = data.text || '';
                state.draft = textarea.value;
                persist();
                autosize();
                renderComposer();
                textarea.focus();
                break;
            case 'state':
                state.chat = data.chat;
                state.chats = data.chats;
                state.busy = !!data.busy;

                if (data.saved && !saved.pick) {
                    state.pick = data.saved;
                }

                break;
            case 'chats':
                state.chats = data.chats;
                state.chat = data.chat;
                break;
            case 'agents':
                state.statuses = data.agents;

                if (!(state.statuses[state.pick.agent] || {}).signedIn) {
                    const ready = ORDER.find((id) => (state.statuses[id] || {}).signedIn);

                    if (ready) {
                        state.pick = { agent: ready, model: ready === 'claude' ? 'default' : 'auto' };
                    }
                }

                ORDER.forEach((id) => (state.statuses[id] || {}).signedIn && !state.models[id] && send({ type: 'models', agent: id }));
                break;
            case 'models':
                state.models[data.agent] = data.models;
                break;
            case 'activeFile':
                state.activeFile = data.path;
                state.useActive = true;
                break;
            case 'attach':
                if (!state.attachments.includes(data.path)) state.attachments.push(data.path);
                break;
            case 'busy':
                state.busy = data.busy;
                state.thinking = false;
                break;
            case 'turn':
                if (state.chat && state.chat.id === data.chatId) {
                    state.chat.turns.push(data.turn);
                    state.chat.title = state.chat.title === 'New chat' ? data.turn.prompt.slice(0, 60) : state.chat.title;
                }

                break;
            case 'parts':
            case 'done': {
                const turn = state.chat && state.chat.turns.find((t) => t.id === (data.turnId || data.turn.id));

                if (turn) {
                    if (data.type === 'parts') turn.parts = data.parts;
                    else Object.assign(turn, data.turn);
                }

                if (data.type === 'done') state.thinking = false;
                schedule();

                return renderComposer();
            }
            case 'thinking':
                state.thinking = true;
                schedule();

                return;
            case 'login':
                if (state.login && state.login.agent === data.agent) {
                    state.login = { ...state.login, ...data };
                    renderDialog();
                }

                return;
            case 'installing':
                state.installing[data.agent] = data.state;
                renderAll();
                break;
            case 'needLogin':
                startLogin(data.agent);

                return;
            case 'graph':
                state.graph = data.state;

                return renderComposer();
            case 'error':
                break;
        }

        renderAll();
    });

    renderAll();
    autosize();
    send({ type: 'ready' });

    // A phone-sized VS Code window (this page is on the same site, so it can read its width):
    // the extension then shows the chat full screen as an editor tab, not in the narrow side bar.
    function reportDevice() {
        let width = window.innerWidth;

        try {
            width = window.top.innerWidth;
        } catch (e) {}

        send({ type: 'device', phone: width <= 820, where: window.LFY_WHERE || 'sidebar' });
    }

    reportDevice();
    try {
        window.top.addEventListener('resize', () => { clearTimeout(reportDevice.timer); reportDevice.timer = setTimeout(reportDevice, 400); });
    } catch (e) {}
})();
