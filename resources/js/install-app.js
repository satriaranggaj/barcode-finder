export function installApp(win = window, doc = document) {
    const button = doc.querySelector('[data-install-app]');
    const help = doc.querySelector('[data-install-help]');
    if (!button || !help || !win.isSecureContext) return;

    const mode = win.matchMedia('(display-mode: standalone)');
    const ios = /iPad|iPhone|iPod/.test(win.navigator.userAgent)
        || (win.navigator.platform === 'MacIntel' && win.navigator.maxTouchPoints > 1);
    const mobile = ios || /Android/.test(win.navigator.userAgent);
    let pending = null;
    let installed = false;
    const standalone = () => installed || mode.matches || win.navigator.standalone === true;
    const refresh = () => {
        button.hidden = standalone() || (!pending && !mobile);
        if (standalone() && help.open) help.close();
    };
    const showHelp = () => {
        doc.querySelector('[data-install-instructions]').textContent = ios
            ? 'Buka menu Bagikan, pilih Tambah ke Layar Utama, lalu Tambah. Jika pilihan tersebut tidak tersedia, buka Lensku di Safari.'
            : 'Buka menu browser (⋮), lalu pilih Instal aplikasi atau Tambahkan ke layar utama. Jika pilihan belum tersedia, coba buka Lensku di Chrome.';
        help.showModal();
    };

    win.addEventListener('beforeinstallprompt', (event) => {
        if (standalone()) return;
        event.preventDefault();
        pending = event;
        refresh();
    });
    win.addEventListener('appinstalled', () => {
        installed = true;
        pending = null;
        refresh();
    });
    mode.addEventListener('change', refresh);
    button.addEventListener('click', async () => {
        if (standalone()) return;
        if (!pending) return showHelp();
        const prompt = pending;
        pending = null; // Browser prompts can only be used once.
        button.disabled = true;
        try {
            await prompt.prompt();
            const choice = await prompt.userChoice;
            if (choice.outcome === 'accepted') installed = true;
        } catch {
            showHelp();
        } finally {
            button.disabled = false;
            refresh();
        }
    });
    refresh();
}
