<script>
    window.fileManager = function (options) {
        var storedView = 'list';

        try {
            storedView = localStorage.getItem('laravelify-files-view') || 'list';
        } catch (e) {}

        return {
            downloadBase: options.downloadBase,
            root: options.root,
            rootLabel: options.rootLabel,
            canZip: options.canZip,
            view: storedView,
            query: '',
            editingPath: false,
            pathValue: '',
            submittingPath: false,
            selected: [],
            dialog: null,
            targets: [],
            renameValue: '',
            typed: '',
            chmod: '644',
            dirty: false,
            menu: { open: false, x: 0, y: 0, target: { relative: '', name: '', directory: false, link: false, extension: '', permissions: '' } },
            transfer: { mode: 'copy', path: '', folders: [], loading: false, error: '' },
            zip: { name: '', folders: [], skip: [], loading: false, error: '' },
            pickTarget: null,
            dragging: false,
            dragDepth: 0,
            dropTarget: null,
            uploading: false,
            progress: 0,
            uploadLabel: '',

            ref(name) {
                return this.$refs[name] || document.querySelector('[x-ref="' + name + '"]');
            },

            setView(view) {
                this.view = view;

                try {
                    localStorage.setItem('laravelify-files-view', view);
                } catch (e) {}
            },

            matches(name) {
                var query = this.query.trim().toLowerCase();

                return query === '' || name.toLowerCase().includes(query);
            },

            joinRoot(relative) {
                var root = this.root.replace(/[\\/]+$/, '');

                return relative === '' ? (root || '/') : root + '/' + relative;
            },

            downloadUrl(relative) {
                return this.downloadBase + '?path=' + encodeURIComponent(relative);
            },

            // Typed path

            editPath() {
                this.pathValue = '/' + this.$wire.directory;
                this.editingPath = true;
                this.$nextTick(() => {
                    var input = this.$refs.pathInput;
                    input.focus();
                    input.setSelectionRange(input.value.length, input.value.length);
                });
            },

            submitPath() {
                if (this.submittingPath) {
                    return;
                }

                this.submittingPath = true;
                this.$wire.goToPath(this.pathValue).then((found) => {
                    this.submittingPath = false;

                    if (found) {
                        this.editingPath = false;
                    } else {
                        this.$refs.pathInput.focus();
                        this.$refs.pathInput.select();
                    }
                });
            },

            cancelPath() {
                if (!this.submittingPath) {
                    this.editingPath = false;
                }
            },

            // Selection

            isSelected(relative) {
                return this.selected.includes(relative);
            },

            toggle(relative) {
                this.selected = this.isSelected(relative)
                    ? this.selected.filter((item) => item !== relative)
                    : this.selected.concat([relative]);
            },

            visibleEntries() {
                return this.$wire.entries.filter((entry) => this.matches(entry.name));
            },

            allSelected() {
                var visible = this.visibleEntries();

                return visible.length > 0 && visible.every((entry) => this.isSelected(entry.relative));
            },

            toggleAll() {
                this.selected = this.allSelected() ? [] : this.visibleEntries().map((entry) => entry.relative);
            },

            clearSelection() {
                this.selected = [];
            },

            selectedTargets() {
                return this.$wire.entries
                    .filter((entry) => this.isSelected(entry.relative))
                    .map((entry) => ({
                        relative: entry.relative,
                        name: entry.name,
                        directory: entry.directory,
                        link: entry.link,
                        extension: entry.extension,
                        permissions: entry.permissions,
                    }));
            },

            targetLabel() {
                return this.targets.length === 1 ? this.targets[0].name : this.targets.length + ' items';
            },

            // Keyboard

            shortcut(event) {
                var tag = (event.target.tagName || '').toLowerCase();
                var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;

                if (typing || this.dialog !== null || this.$wire.openPath !== '' || this.$el.offsetParent === null) {
                    return;
                }

                if (event.key === '/') {
                    event.preventDefault();
                    this.$refs.search.focus();
                } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'l') {
                    event.preventDefault();
                    this.editPath();
                } else if (event.key === 'Delete' && this.selected.length > 0) {
                    event.preventDefault();
                    this.askDelete(this.selectedTargets());
                } else if (event.key === 'F2' && this.selected.length === 1) {
                    event.preventDefault();
                    this.askRename(this.selectedTargets()[0]);
                } else if (event.key === 'Escape' && this.selected.length > 0 && !this.menu.open) {
                    this.clearSelection();
                }
            },

            // Action menu

            openMenu(event, target) {
                var x = event.clientX;
                var y = event.clientY;

                if (event.type === 'click' || (!x && !y)) {
                    var rect = event.currentTarget.getBoundingClientRect();
                    x = rect.right - 4;
                    y = rect.bottom + 4;
                }

                this.menu.target = target;
                this.menu.x = x;
                this.menu.y = y;
                this.menu.open = true;

                this.$nextTick(() => {
                    var menu = document.querySelector('.fm-menu');

                    if (!menu) {
                        return;
                    }

                    var width = menu.offsetWidth;
                    var height = menu.offsetHeight;
                    var left = x;

                    if (event.type === 'click') {
                        left = x - width;
                    }

                    this.menu.x = Math.max(8, Math.min(left, window.innerWidth - width - 8));
                    this.menu.y = y + height > window.innerHeight - 8 ? Math.max(8, y - height - 8) : y;
                });
            },

            act(action) {
                var target = this.menu.target;
                this.menu.open = false;

                switch (action) {
                    case 'open':
                        target.directory ? this.$wire.openDirectory(target.relative) : this.$wire.openFile(target.relative);
                        break;
                    case 'upload':
                        this.pick(target.relative);
                        break;
                    case 'rename':
                        this.askRename(target);
                        break;
                    case 'duplicate':
                        this.$wire.duplicateEntry(target.relative);
                        break;
                    case 'copy':
                    case 'move':
                        this.askTransfer(action, [target]);
                        break;
                    case 'compress':
                        this.askCompress([target]);
                        break;
                    case 'downloadZip':
                        this.$wire.prepareDownload(target.relative);
                        break;
                    case 'extract':
                        this.$wire.extractEntry(target.relative);
                        break;
                    case 'chmod':
                        this.askChmod(target);
                        break;
                    case 'copyPath':
                        this.copyText(this.joinRoot(target.relative), 'Path copied.');
                        break;
                    case 'delete':
                        this.askDelete([target]);
                        break;
                }
            },

            copyText(text, message) {
                if (!navigator.clipboard) {
                    window.toast('Copying needs a secure (https) connection.', 'error');

                    return;
                }

                navigator.clipboard.writeText(text).then(
                    () => window.toast(message),
                    () => window.toast('Could not copy to the clipboard.', 'error')
                );
            },

            // Dialogs

            openDialog(name) {
                this.dialog = name;
                this.$wire.folderName = '';
                this.$wire.fileName = '';
                this.$nextTick(() => {
                    var input = this.ref(name === 'folder' ? 'folderInput' : 'fileInput');
                    input && input.focus();
                });
            },

            askRename(target) {
                this.targets = [target];
                this.renameValue = target.name;
                this.dialog = 'rename';
                this.$nextTick(() => {
                    var input = this.ref('renameInput');
                    var dot = target.directory ? -1 : target.name.lastIndexOf('.');

                    if (input) {
                        input.focus();
                        input.setSelectionRange(0, dot > 0 ? dot : target.name.length);
                    }
                });
            },

            submitRename() {
                var value = this.renameValue.trim();

                if (value === '' || value === this.targets[0].name) {
                    return;
                }

                this.$wire.renameEntry(this.targets[0].relative, value).then(() => {
                    this.dialog = null;
                    this.clearSelection();
                });
            },

            askDelete(targets) {
                if (targets.length === 0) {
                    return;
                }

                this.targets = targets;
                this.typed = '';
                this.$wire.confirmName = '';
                this.dialog = 'delete';
                this.$nextTick(() => {
                    var input = this.ref('deleteInput');
                    input && input.focus();
                });
            },

            deleteWord() {
                return this.targets.length === 1 ? this.targets[0].name : 'delete';
            },

            submitDelete() {
                if (this.typed !== this.deleteWord()) {
                    return;
                }

                this.$wire.deleteEntries(this.targets.map((target) => target.relative));
            },

            askChmod(target) {
                this.targets = [target];
                this.chmod = target.permissions || '644';
                this.dialog = 'chmod';
            },

            hasBit(row, bit) {
                return (parseInt(this.chmod.padStart(3, '0')[row], 8) || 0) & bit;
            },

            toggleBit(row, bit) {
                var digits = this.chmod.padStart(3, '0').slice(-3).split('');
                digits[row] = String((parseInt(digits[row], 8) || 0) ^ bit);
                this.chmod = digits.join('');
            },

            // Compress: list the folders a zip usually does not need, ticked to leave out.

            askCompress(targets) {
                if (targets.length === 0) {
                    return;
                }

                this.targets = targets;
                this.zip = { name: '', folders: [], skip: [], loading: true, error: '' };
                this.dialog = 'compress';

                this.$wire.compressPlan(targets.map((target) => target.relative)).then((plan) => {
                    this.zip.name = plan.name;
                    this.zip.folders = plan.folders;
                    this.zip.skip = plan.folders.filter((folder) => folder.checked).map((folder) => folder.path);
                    this.zip.error = plan.error;
                    this.zip.loading = false;
                });
            },

            toggleSkip(path) {
                this.zip.skip = this.zip.skip.includes(path)
                    ? this.zip.skip.filter((item) => item !== path)
                    : this.zip.skip.concat([path]);
            },

            skipAll(skip) {
                this.zip.skip = skip ? this.zip.folders.map((folder) => folder.path) : [];
            },

            submitCompress() {
                this.$wire.compressEntries(this.targets.map((target) => target.relative), this.zip.skip, this.zip.name);
                this.dialog = null;
            },

            askTransfer(mode, targets) {
                if (targets.length === 0) {
                    return;
                }

                this.targets = targets;
                this.transfer.mode = mode;
                this.dialog = 'transfer';
                this.browse(this.$wire.directory);
            },

            browse(path) {
                this.transfer.loading = true;
                this.transfer.error = '';

                this.$wire.folderTree(path).then((result) => {
                    this.transfer.path = result.path;
                    this.transfer.folders = result.folders;
                    this.transfer.error = result.error;
                    this.transfer.loading = false;
                });
            },

            parentOf(path) {
                var index = path.lastIndexOf('/');

                return index === -1 ? '' : path.slice(0, index);
            },

            crumbsOf(path) {
                var crumbs = [];
                var prefix = '';

                path.split('/').filter(Boolean).forEach((part) => {
                    prefix = prefix === '' ? part : prefix + '/' + part;
                    crumbs.push({ label: part, path: prefix });
                });

                return crumbs;
            },

            isTransferSource(path) {
                return this.targets.some((target) => target.directory && (path === target.relative || path.startsWith(target.relative + '/')));
            },

            submitTransfer() {
                this.$wire.transferEntries(this.targets.map((target) => target.relative), this.transfer.path, this.transfer.mode);
            },

            closeEditor() {
                if (this.dirty && !window.confirm('Discard unsaved changes?')) {
                    return;
                }

                this.dirty = false;
                this.$wire.closeFile();
            },

            // Uploads

            pick(target) {
                this.pickTarget = target;
                this.$refs.picker.click();
            },

            hasFiles(event) {
                return event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');
            },

            dragEnter(event) {
                if (!this.hasFiles(event)) {
                    return;
                }

                this.dragDepth += 1;
                this.dragging = true;
            },

            dragOver(event) {
                if (this.hasFiles(event)) {
                    event.dataTransfer.dropEffect = 'copy';
                    this.dropTarget = null;
                }
            },

            dragLeave(event) {
                if (!this.hasFiles(event)) {
                    return;
                }

                this.dragDepth = Math.max(0, this.dragDepth - 1);

                if (this.dragDepth === 0) {
                    this.dragging = false;
                    this.dropTarget = null;
                }
            },

            drop(event, target) {
                var files = event.dataTransfer ? event.dataTransfer.files : [];
                this.dragDepth = 0;
                this.dragging = false;
                this.dropTarget = null;

                if (files && files.length) {
                    this.upload(files, target);
                }
            },

            upload(files, target) {
                if (!files || !files.length || this.uploading) {
                    return;
                }

                var count = files.length;
                var where = target === '' || target === null ? 'the site folder' : '/' + target;

                this.uploading = true;
                this.progress = 0;
                this.uploadLabel = 'Uploading ' + (count === 1 ? files[0].name : count + ' files') + ' to ' + where;
                this.pickTarget = null;

                var done = () => {
                    this.uploading = false;
                    this.progress = 0;
                };

                this.$wire.uploadMultiple(
                    'uploads',
                    files,
                    () => {
                        this.progress = 100;
                        this.uploadLabel = 'Saving to ' + where;
                        this.$wire.storeUploads(target || '').finally(done);
                    },
                    () => {
                        done();
                        window.toast('Upload failed. Each file must be 32 MB or smaller.', 'error');
                    },
                    (event) => {
                        this.progress = event.detail.progress;
                    },
                    done,
                    false
                );
            },
        };
    };

    window.fileEditor = function () {
        var prefs = {};

        try {
            prefs = JSON.parse(localStorage.getItem('laravelify-editor') || '{}') || {};
        } catch (e) {}

        return {
            shown: false,
            full: !!prefs.full,
            wrap: !!prefs.wrap,
            fontSize: prefs.fontSize || 13,
            fit: true,
            dimensions: '',
            findOpen: false,
            findQuery: '',
            matchLabel: '',
            line: 1,
            col: 1,
            lines: 1,

            init() {
                this.$nextTick(() => {
                    this.shown = true;
                    this.measure();

                    if (this.$refs.code) {
                        this.$refs.code.focus();
                        this.$refs.code.setSelectionRange(0, 0);
                    }
                });
            },

            savePrefs() {
                try {
                    localStorage.setItem('laravelify-editor', JSON.stringify({ full: this.full, wrap: this.wrap, fontSize: this.fontSize }));
                } catch (e) {}
            },

            toggleFull() {
                this.full = !this.full;
                this.savePrefs();
            },

            toggleWrap() {
                this.wrap = !this.wrap;
                this.savePrefs();
            },

            zoom(step) {
                this.fontSize = Math.min(22, Math.max(10, this.fontSize + step));
                this.savePrefs();
            },

            measure() {
                if (this.$refs.code) {
                    this.lines = this.$refs.code.value.split('\n').length;
                    this.caret();
                }
            },

            gutter() {
                var numbers = [];

                for (var i = 1; i <= this.lines; i++) {
                    numbers.push(i);
                }

                return numbers.join('\n');
            },

            caret() {
                var code = this.$refs.code;

                if (!code) {
                    return;
                }

                var before = code.value.slice(0, code.selectionStart);
                this.line = before.split('\n').length;
                this.col = code.selectionStart - before.lastIndexOf('\n');
            },

            copyAll() {
                this.copyText(this.$refs.code.value, 'File contents copied.');
            },

            reload() {
                if (this.dirty && !window.confirm('Discard unsaved changes and reload the file from disk?')) {
                    return;
                }

                this.dirty = false;
                this.$wire.openFile(this.$wire.openPath).then(() => this.$nextTick(() => this.measure()));
            },

            openFind() {
                var code = this.$refs.code;

                if (!code) {
                    return;
                }

                var selection = code.value.slice(code.selectionStart, code.selectionEnd);

                if (selection && !selection.includes('\n')) {
                    this.findQuery = selection;
                }

                this.findOpen = true;
                this.countMatches();
                this.$nextTick(() => {
                    this.$refs.find.focus();
                    this.$refs.find.select();
                });
            },

            closeFind() {
                this.findOpen = false;
                this.matchLabel = '';
                this.$refs.code && this.$refs.code.focus();
            },

            countMatches() {
                var query = this.findQuery.toLowerCase();

                if (query === '') {
                    this.matchLabel = '';

                    return 0;
                }

                var text = this.$refs.code.value.toLowerCase();
                var count = 0;
                var index = text.indexOf(query);

                while (index !== -1) {
                    count++;
                    index = text.indexOf(query, index + query.length);
                }

                this.matchLabel = count === 0 ? 'No results' : count + (count === 1 ? ' match' : ' matches');

                return count;
            },

            find(direction) {
                var code = this.$refs.code;
                var query = this.findQuery.toLowerCase();
                var total = this.countMatches();

                if (query === '' || total === 0) {
                    return;
                }

                var text = code.value.toLowerCase();
                var index = direction > 0
                    ? text.indexOf(query, code.selectionEnd)
                    : text.lastIndexOf(query, Math.max(0, code.selectionStart - 1));

                if (index === -1 || (direction < 0 && index >= code.selectionStart)) {
                    index = direction > 0 ? text.indexOf(query) : text.lastIndexOf(query);
                }

                var position = 0;
                var cursor = text.indexOf(query);

                while (cursor !== -1 && cursor <= index) {
                    position++;
                    cursor = text.indexOf(query, cursor + query.length);
                }

                code.focus();
                code.setSelectionRange(index, index + query.length);

                var lineHeight = parseFloat(getComputedStyle(code).lineHeight) || 20;
                var line = text.slice(0, index).split('\n').length - 1;
                code.scrollTop = Math.max(0, (line - 4) * lineHeight);

                this.caret();
                this.matchLabel = position + ' of ' + total;
                this.$refs.find.focus();
            },
        };
    };
</script>
