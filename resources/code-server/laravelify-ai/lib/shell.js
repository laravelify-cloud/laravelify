// Running commands as the website user, with ~/.local/bin (where the agents install) on PATH.
const { execFile, spawn } = require('child_process');
const os = require('os');
const path = require('path');

const localBin = path.join(os.homedir(), '.local', 'bin');
const shellPath = [localBin, '/opt/graphify/bin', process.env.PATH || '/usr/local/bin:/usr/bin:/bin'].join(':');
const env = () => ({ ...process.env, PATH: shellPath, NO_OPEN_BROWSER: '1', BROWSER: 'true', TERM: 'xterm-256color', NO_COLOR: '1' });

/** Run a shell command and collect what it prints. */
function run(command, { timeout = 60000, cwd } = {}) {
    return new Promise((resolve) => {
        execFile('/bin/bash', ['-lc', command], { timeout, cwd, env: env(), maxBuffer: 8 * 1024 * 1024 }, (error, stdout, stderr) => {
            resolve({ ok: !error, out: String(stdout || '') + String(stderr || '') });
        });
    });
}

async function which(name) {
    const { ok, out } = await run(`command -v ${name}`, { timeout: 5000 });

    return ok ? out.trim().split('\n')[0] : null;
}

/** A long-running process whose output arrives line by line. */
function stream(file, args, { cwd, onLine, onExit }) {
    const child = spawn(file, args, { cwd, env: env() });
    let buffer = '';
    let errors = '';

    const take = (chunk) => {
        buffer += chunk.toString();
        const lines = buffer.split('\n');
        buffer = lines.pop();
        lines.forEach((line) => onLine(line.replace(/\r$/, '')));
    };

    child.stdout.on('data', take);
    child.stderr.on('data', (chunk) => {
        errors += chunk.toString();
        take(chunk);
    });
    child.on('close', (code) => {
        if (buffer) {
            onLine(buffer);
        }

        onExit(code, errors);
    });
    child.on('error', (error) => onExit(-1, String(error.message || error)));

    return child;
}

/**
 * A command that needs a terminal (sign-ins ask questions): run inside `script`, which gives it
 * one, so its prompts can be answered by writing to its input.
 */
function pty(command, { cwd, onData, onExit }) {
    const child = spawn('script', ['-qfec', command, '/dev/null'], { cwd, env: env() });
    child.stdout.on('data', (chunk) => onData(chunk.toString()));
    child.stderr.on('data', (chunk) => onData(chunk.toString()));
    child.on('close', (code) => onExit(code));
    child.on('error', () => onExit(-1));

    return child;
}

module.exports = { run, which, stream, pty, shellPath, localBin };
