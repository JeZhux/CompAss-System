import eslint from '@eslint/js';
import globals from 'globals';

export default [
    eslint.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
            },
        },
        files: ['**/*.js', '**/*.mjs'],
    },
];
