// Champs mot de passe de toute l'application :
// - un œil pour afficher / masquer la saisie (tous les champs type="password", sauf data-no-reveal) ;
// - un indicateur de force et les critères à remplir pour les nouveaux mots de passe (autocomplete="new-password") ;
// - la vérification de la confirmation (champ *_confirmation) : identique ou différente.

const LEVELS = [
    { label: 'Trop faible', color: 'danger', width: 15 },
    { label: 'Faible', color: 'danger', width: 35 },
    { label: 'Moyen', color: 'warning', width: 55 },
    { label: 'Bon', color: 'info', width: 78 },
    { label: 'Très fort', color: 'success', width: 100 },
];

const COMMON = ['password', 'motdepasse', 'azerty', 'qwerty', '123456', 'gavi', 'airid', 'admin', 'bonjour', 'benin'];

/** Note de 0 à 4 et critères remplis. */
export function passwordStrength(value, minLength = 10) {
    const checks = {
        length: value.length >= minLength,
        letters: /[a-zA-Z]/.test(value),
        digits: /\d/.test(value),
        cases: /[a-z]/.test(value) && /[A-Z]/.test(value),
        symbols: /[^a-zA-Z0-9]/.test(value),
    };
    if (value === '') return { score: -1, checks };
    let score = 0;
    if (value.length >= minLength) score++;
    if (value.length >= minLength + 4) score++;
    if (checks.letters && checks.digits) score++;
    if (checks.cases) score++;
    if (checks.symbols) score++;
    // Pénalités : motifs courants, caractère répété, suites simples.
    const lower = value.toLowerCase();
    if (COMMON.some((word) => lower.includes(word))) score -= 2;
    if (/(.)\1{2,}/.test(value)) score--;
    if (/(?:0123|1234|2345|3456|4567|5678|6789|abcd|bcde|cdef)/i.test(value)) score--;
    // Règle minimale de la plateforme non remplie : jamais au-dessus de « Faible ».
    if (!checks.length || !checks.letters || !checks.digits) score = Math.min(score, 1);

    return { score: Math.max(0, Math.min(4, score)), checks };
}

function wrap(input) {
    let group = input.parentElement;
    if (!group.classList.contains('input-group')) {
        group = document.createElement('div');
        group.className = 'input-group';
        if (input.classList.contains('form-control-sm')) group.classList.add('input-group-sm');
        input.before(group);
        group.append(input);
        // Message d'erreur de validation : dans le groupe pour rester affiché.
        const feedback = group.nextElementSibling;
        if (feedback?.classList.contains('invalid-feedback')) group.append(feedback);
    }
    if (input.classList.contains('is-invalid')) group.classList.add('has-validation');

    return group;
}

function addReveal(input) {
    const group = wrap(input);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-outline-secondary';
    button.setAttribute('aria-label', 'Afficher le mot de passe');
    button.title = 'Afficher le mot de passe';
    button.innerHTML = '<i class="bi bi-eye"></i>';
    button.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
        const label = visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
        button.setAttribute('aria-label', label);
        button.title = label;
        input.focus();
    });
    // Avant le message d'erreur éventuel (dernier élément du groupe).
    const feedback = group.querySelector(':scope > .invalid-feedback');
    if (feedback) feedback.before(button); else group.append(button);
    // Affichage demandé par un autre script (ex. mot de passe généré).
    input.addEventListener('password:reveal', () => { if (input.type === 'password') button.click(); });
}

function addMeter(input) {
    const minLength = Number(input.getAttribute('minlength')) || 10;
    const meter = document.createElement('div');
    meter.className = 'password-meter mt-1';
    meter.setAttribute('aria-live', 'polite');
    meter.innerHTML = `
        <div class="progress" style="height: 5px;" role="progressbar" aria-label="Force du mot de passe" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: 0%"></div>
        </div>
        <div class="d-flex flex-wrap justify-content-between gap-2 small mt-1">
            <span data-level class="fw-semibold text-muted">Force du mot de passe</span>
            <span class="d-flex flex-wrap gap-2 text-muted" data-criteria>
                <span data-check="length">${minLength} caractères</span>
                <span data-check="letters">lettres</span>
                <span data-check="digits">chiffres</span>
                <span data-check="cases">majuscules et minuscules</span>
                <span data-check="symbols">symbole (!, ?, #…)</span>
            </span>
        </div>`;
    input.closest('.input-group').after(meter);
    const bar = meter.querySelector('.progress-bar');
    const level = meter.querySelector('[data-level]');

    const update = () => {
        const { score, checks } = passwordStrength(input.value, minLength);
        const current = LEVELS[score] ?? null;
        bar.style.width = `${current ? current.width : 0}%`;
        bar.className = `progress-bar ${current ? `bg-${current.color}` : ''}`;
        meter.querySelector('.progress').setAttribute('aria-valuenow', current ? current.width : 0);
        level.textContent = current ? `Force : ${current.label}` : 'Force du mot de passe';
        level.className = `fw-semibold ${current ? `text-${current.color}-emphasis` : 'text-muted'}`;
        meter.querySelectorAll('[data-check]').forEach((item) => {
            const ok = checks[item.dataset.check];
            const required = ['length', 'letters', 'digits'].includes(item.dataset.check);
            item.className = ok ? 'text-success' : (required && input.value !== '' ? 'text-danger' : 'text-muted');
            item.innerHTML = `<i class="bi bi-${ok ? 'check-circle-fill' : 'circle'} me-1"></i>${item.textContent.trim()}`;
        });
    };
    input.addEventListener('input', update);
    update();
}

function addConfirmation(input, original) {
    const note = document.createElement('div');
    note.className = 'small mt-1';
    note.setAttribute('aria-live', 'polite');
    input.closest('.input-group').after(note);
    const update = () => {
        if (input.value === '') {
            note.textContent = '';
            return;
        }
        const same = input.value === original.value;
        note.className = `small mt-1 ${same ? 'text-success' : 'text-danger'}`;
        note.innerHTML = `<i class="bi bi-${same ? 'check-circle-fill' : 'x-circle-fill'} me-1"></i>${same ? 'Les deux mots de passe sont identiques.' : 'Les deux mots de passe sont différents.'}`;
    };
    input.addEventListener('input', update);
    original.addEventListener('input', update);
    update();
}

export function initPasswordFields(root = document) {
    root.querySelectorAll('input[type="password"]:not([data-no-reveal]):not([data-password-ready])').forEach((input) => {
        input.dataset.passwordReady = '1';
        addReveal(input);
        const name = input.name || '';
        const isConfirmation = name.endsWith('_confirmation');
        if (isConfirmation) {
            const original = input.form?.querySelector(`input[name="${CSS.escape(name.replace(/_confirmation$/, ''))}"]`);
            if (original) addConfirmation(input, original);
        } else if (input.autocomplete === 'new-password') {
            addMeter(input);
        }
    });
}
