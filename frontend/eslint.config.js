import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

// Minimal flat config: React recommended + hooks, JSX via parser options.
// Run: npm run lint. Deps install pending (Agent 4: npm install).
export default [
  {
    ignores: ['dist/**', 'node_modules/**', 'public/fonts/**'],
  },
  {
    files: ['src/**/*.{js,jsx}'],
    plugins: {
      react,
      'react-hooks': reactHooks,
    },
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      parserOptions: {
        ecmaFeatures: { jsx: true },
      },
    },
    settings: {
      react: { version: 'detect' },
    },
    rules: {
      ...react.configs.recommended.rules,
      ...reactHooks.configs.recommended.rules,
      'react/react-in-jsx-scope': 'off',
      'react/prop-types': 'off',
    },
  },
];
