import test from 'node:test';
import assert from 'node:assert/strict';
import { installApp } from '../../resources/js/install-app.js';

function setup({ agent = 'Android', standalone = false, secure = true } = {}) {
    const events = {};
    const button = { hidden: true, addEventListener: (_, fn) => { button.click = fn; } };
    const help = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    const instructions = {};
    const mode = { matches: standalone, addEventListener: (_, fn) => { mode.change = fn; } };
    const win = { isSecureContext: secure, navigator: { userAgent: agent }, matchMedia: () => mode,
        addEventListener: (name, fn) => { events[name] = fn; } };
    const doc = { querySelector: (selector) => ({ '[data-install-app]': button,
        '[data-install-help]': help, '[data-install-instructions]': instructions })[selector] };
    installApp(win, doc);
    return { events, button, help, instructions, mode };
}

test('Android prompts only on click and a dismissed prompt is never reused', async () => {
    const ui = setup();
    let prompts = 0, prevented = false;
    ui.events.beforeinstallprompt({ preventDefault() { prevented = true; },
        async prompt() { prompts++; }, userChoice: Promise.resolve({ outcome: 'dismissed' }) });
    assert.equal(prevented, true);
    assert.equal(prompts, 0);
    await ui.button.click();
    assert.equal(prompts, 1);
    await ui.button.click();
    assert.equal(prompts, 1);
    assert.equal(ui.help.open, true);
});

test('iPhone offers manual installation guidance', async () => {
    const ui = setup({ agent: 'iPhone' });
    assert.equal(ui.button.hidden, false);
    await ui.button.click();
    assert.match(ui.instructions.textContent, /Bagikan.*Tambah ke Layar Utama/);
    assert.equal(ui.help.open, true);
});

test('installed and standalone apps hide installation UI', async () => {
    assert.equal(setup({ standalone: true }).button.hidden, true);
    const ui = setup();
    await ui.button.click();
    ui.events.appinstalled();
    assert.equal(ui.button.hidden, true);
    assert.equal(ui.help.open, false);
});

test('unsupported desktop and insecure origins do not offer installation', () => {
    assert.equal(setup({ agent: 'Desktop' }).button.hidden, true);
    assert.equal(setup({ secure: false }).button.hidden, true);
});

test('failed browser prompt falls back to instructions without breaking the page', async () => {
    const ui = setup();
    ui.events.beforeinstallprompt({ preventDefault() {}, async prompt() { throw new Error('unavailable'); } });
    await ui.button.click();
    assert.equal(ui.help.open, true);
    assert.equal(ui.button.disabled, false);
});
