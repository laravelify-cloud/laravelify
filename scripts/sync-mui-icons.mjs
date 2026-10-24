// Builds resources/icons/mui.json from @mui/icons-material (Material Design icons, Apache-2.0).
// The package is not a dependency of Laravelify: install it anywhere and point MUI_ICONS_PATH at it.
//   npm install --prefix /tmp/mui @mui/icons-material --legacy-peer-deps
//   MUI_ICONS_PATH=/tmp/mui/node_modules/@mui/icons-material node scripts/sync-mui-icons.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const source = process.env.MUI_ICONS_PATH ?? 'node_modules/@mui/icons-material';
const output = join(dirname(fileURLToPath(import.meta.url)), '../resources/icons/mui.json');

// Laravelify icon name => Material icon name
const icons = {
    dashboard: 'DashboardOutlined',
    groups: 'FolderOutlined',
    server: 'DnsOutlined',
    sources: 'AccountTreeOutlined',
    tunnels: 'CloudOutlined',
    settings: 'SettingsOutlined',
    variables: 'DataObjectOutlined',
    runtime: 'MemoryOutlined',
    nginx: 'DescriptionOutlined',
    processes: 'FormatListBulletedOutlined',
    deployments: 'HistoryOutlined',
    terminal: 'TerminalOutlined',
    files: 'FolderOpenOutlined',
    danger: 'WarningAmberOutlined',
    keys: 'VpnKeyOutlined',
    upload: 'FileUploadOutlined',
    download: 'FileDownloadOutlined',
    pencil: 'EditOutlined',
    trash: 'DeleteOutlineOutlined',
    refresh: 'RefreshOutlined',
    search: 'SearchOutlined',
    home: 'HomeOutlined',
    up: 'ArrowUpwardOutlined',
    grid: 'GridViewOutlined',
    list: 'ViewListOutlined',
    'file-plus': 'NoteAddOutlined',
    'folder-plus': 'CreateNewFolderOutlined',
    more: 'MoreHorizOutlined',
    close: 'CloseOutlined',
    eye: 'VisibilityOutlined',
    'eye-off': 'VisibilityOffOutlined',
    archive: 'ArchiveOutlined',
    'chevron-right': 'ChevronRightOutlined',
    database: 'StorageOutlined',
    shield: 'ShieldOutlined',
    lock: 'LockOutlined',
    robot: 'SmartToyOutlined',
    telegram: 'SendOutlined',
    logs: 'ArticleOutlined',
    pulse: 'MonitorHeartOutlined',
    play: 'PlayArrowOutlined',
    pause: 'PauseOutlined',
    stop: 'StopOutlined',
    sun: 'LightModeOutlined',
    moon: 'DarkModeOutlined',
    logout: 'LogoutOutlined',
    chevrons: 'UnfoldMoreOutlined',
    'chevron-down': 'ExpandMoreOutlined',
    external: 'OpenInNewOutlined',
    git: 'CallSplitOutlined',
    rocket: 'RocketLaunchOutlined',
    'arrow-down': 'ArrowDownwardOutlined',
    clock: 'ScheduleOutlined',
    webhook: 'WebhookOutlined',
    copy: 'ContentCopyOutlined',
    move: 'DriveFileMoveOutlined',
    link: 'LinkOutlined',
    wrap: 'WrapTextOutlined',
    minus: 'RemoveOutlined',
    plus: 'AddOutlined',
    expand: 'OpenInFullOutlined',
    shrink: 'CloseFullscreenOutlined',
    book: 'MenuBookOutlined',
    cpu: 'DeveloperBoardOutlined',
    chart: 'BarChartOutlined',
    bug: 'BugReportOutlined',
    globe: 'PublicOutlined',
    ban: 'BlockOutlined',
    check: 'CheckOutlined',
    'arrow-up-right': 'NorthEastOutlined',
    backup: 'BackupOutlined',
    'cloud-upload': 'CloudUploadOutlined',
    restore: 'RestoreOutlined',
    schedule: 'EventRepeatOutlined',
    s3: 'CloudSyncOutlined',
    table: 'TableChartOutlined',
    menu: 'MenuOutlined',
    image: 'ImageOutlined',
    github: 'GitHub',
    tune: 'TuneOutlined',
    build: 'BuildOutlined',
    attach: 'AttachFileOutlined',
    article: 'ArticleOutlined',
    bolt: 'BoltOutlined',
    login: 'LoginOutlined',
};

const result = {};

for (const [name, material] of Object.entries(icons)) {
    const code = readFileSync(join(source, `${material}.mjs`), 'utf8');
    const paths = [...code.matchAll(/\bd:\s*"([^"]+)"/g)].map((match) => match[1]);

    if (paths.length === 0) {
        throw new Error(`No path found in ${material}`);
    }

    result[name] = paths;
}

writeFileSync(output, JSON.stringify(result, null, 2) + '\n');
console.log(`Wrote ${Object.keys(result).length} icons to ${output}`);
