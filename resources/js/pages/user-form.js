// Formulaire utilisateur : génération d'un mot de passe provisoire solide (lettres, chiffres et symbole, sans caractères
// ambigus), recopié dans la confirmation et affiché, pour l'envoyer par e-mail ou le communiquer.
// L'œil et l'indicateur de force viennent de lib/password-fields.
const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
const DIGITS = '23456789';
const SYMBOLS = '!#$%*+-=?@';

function pick(alphabet, count) {
    const values = new Uint32Array(count);
    window.crypto.getRandomValues(values);
    return Array.from(values, (v) => alphabet[v % alphabet.length]);
}

/** 14 caractères : majuscules, minuscules, chiffres et un symbole, mélangés. */
export function generatePassword() {
    const chars = [...pick(LETTERS.slice(0, 24), 2), ...pick(LETTERS.slice(24), 7), ...pick(DIGITS, 3), ...pick(SYMBOLS, 1), ...pick(LETTERS + DIGITS, 1)];
    const order = new Uint32Array(chars.length);
    window.crypto.getRandomValues(order);
    return chars.map((c, i) => [order[i], c]).sort((a, b) => a[0] - b[0]).map(([, c]) => c).join('');
}

export default function userFormPage() {
    const input = document.getElementById('password');
    const confirmation = document.getElementById('password_confirmation');
    if (!input) return;
    document.querySelector('[data-generate-password]')?.addEventListener('click', () => {
        const password = generatePassword();
        [input, confirmation].filter(Boolean).forEach((field) => {
            field.value = password;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('password:reveal'));
        });
        input.select();
    });
}
